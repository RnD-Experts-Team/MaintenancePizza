<?php

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * ObservableThrottleRequests and the limits in AppServiceProvider.
 *
 * The real limits are far too big to walk past in a test, so most cases swap
 * in a tiny limiter under the same name; the last few check the real numbers.
 * Ported from ToolboxPizza.
 */
class ThrottleTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    private const PROBE = '/api/issues';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
    }

    private function tinyApiLimiter(int $real, int $target = 100): void
    {
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute($real)->by('t:'.$request->bearerToken()),
            Limit::perMinute($target)->by('target:t:'.$request->bearerToken()),
        ]);
    }

    public function test_passing_a_target_logs_once_and_blocks_nothing(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 100, target: 2);

        foreach (range(1, 5) as $_) {
            $this->getJson(self::PROBE, $this->headers())->assertOk();
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.usage', Mockery::on(fn ($ctx) => $ctx['limiter'] === 'api' && $ctx['max'] === 2))
            ->once();
    }

    public function test_record_only_mode_lets_a_breach_through_and_logs_it_once(): void
    {
        config(['app.throttle_enforce' => false]);
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 2);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        foreach (range(1, 3) as $_) {
            $this->getJson(self::PROBE, $this->headers())
                ->assertOk()
                ->assertHeader('X-RateLimit-Remaining', '0');
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.breach', Mockery::on(fn ($ctx) => $ctx['enforced'] === false))
            ->once();
    }

    public function test_enforce_mode_answers_429_with_retry_after(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 2);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        $this->getJson(self::PROBE, $this->headers())
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_a_throttled_request_never_reaches_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_route_tier_also_runs_before_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        RateLimiter::for('api-heavy', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));

        $this->getJson('/api/tickets/analytics', $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson('/api/tickets/analytics', $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_two_tokens_do_not_share_a_bucket(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);

        $this->getJson(self::PROBE, ['Authorization' => 'Bearer 2|other-token', 'Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_an_unreachable_limiter_store_serves_the_request_unthrottled(): void
    {
        config(['app.throttle_enforce' => true]);

        $down = new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('redis down');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('redis down');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('redis down');
            }
        };

        $limiter = new CacheRateLimiter(new Repository($down));
        $limiter->for('api', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));
        $this->app->instance(CacheRateLimiter::class, $limiter);

        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        Log::shouldHaveReceived('error')->with('throttle.store_unavailable', Mockery::any())->twice();
    }

    public function test_the_real_api_limits_split_reads_from_writes(): void
    {
        $limits = fn (string $method) => collect(RateLimiter::limiter('api')(
            Request::create(self::PROBE, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer 1|test-token'])
        ));

        $real = fn ($all) => $all->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();
        $target = fn ($all) => $all->filter(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();

        $this->assertSame([600, 5000], $real($limits('GET')));
        $this->assertSame([120, 1200], $target($limits('GET')));
        $this->assertSame([300, 5000], $real($limits('POST')));
        $this->assertSame([60, 1200], $target($limits('POST')));
    }

    public function test_keys_hash_the_token_and_fall_back_to_the_ip(): void
    {
        $key = fn (array $server) => RateLimiter::limiter('api-upload')(Request::create('/x', 'POST', server: $server))[0]->key;

        $a = $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']);

        $this->assertSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']));
        $this->assertNotSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|bbb']));
        $this->assertStringNotContainsString('aaa', $a);
        $this->assertSame('ip:10.1.2.3', $key(['REMOTE_ADDR' => '10.1.2.3']));
    }

    public function test_the_service_secret_key_hashes_the_secret_wherever_it_is_sent_plus_the_ip(): void
    {
        $limits = fn (array $server, array $body = []) => RateLimiter::limiter('service-secret')(
            Request::create('/api/export/excel', 'GET', $body, server: $server + ['REMOTE_ADDR' => '10.1.2.3'])
        );

        $header = $limits(['HTTP_X_SECRET_KEY' => 'sesame'])[0]->key;

        $this->assertSame($header, $limits([], ['X-Secret-Key' => 'sesame'])[0]->key);
        $this->assertSame($header, $limits([], ['x_secret_key' => 'sesame'])[0]->key);
        $this->assertNotSame($header, $limits(['HTTP_X_SECRET_KEY' => 'guess'])[0]->key);
        $this->assertStringNotContainsString('sesame', $header);
        $this->assertStringEndsWith('|10.1.2.3', $header);

        $all = collect($limits(['HTTP_X_SECRET_KEY' => 'sesame']));
        $this->assertSame([60], $all->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all());
        $this->assertSame([10, 60], $all->filter(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all());
    }

    public function test_guessing_the_export_secret_is_throttled_before_the_check(): void
    {
        config(['app.throttle_enforce' => true, 'services.x_secret_key' => 'sesame']);
        RateLimiter::for('service-secret', fn (Request $request) => Limit::perMinute(2)->by('s:'.$request->ip()));

        $guess = fn () => $this->getJson('/api/export/excel', ['X-Secret-Key' => 'guess']);

        $guess()->assertUnauthorized();
        $guess()->assertUnauthorized();
        $guess()->assertStatus(429);
    }

    public function test_each_tier_is_on_the_routes_the_plan_puts_it_on(): void
    {
        $routesWith = fn (string $middleware) => collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array($middleware, $route->gatherMiddleware(), true))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $uploads = $routesWith('throttle:api-upload');
        $this->assertCount(34, $uploads);
        $this->assertTrue($uploads->every(fn ($r) => str_starts_with($r, 'POST ')
            && preg_match('#/(notes|attachments)$|attendance-entries$#', $r) === 1));

        $this->assertEqualsCanonicalizing(
            ['GET|HEAD api/tickets/analytics', 'GET|HEAD api/stores/{store}/tickets/analytics'],
            $routesWith('throttle:api-heavy')->all(),
        );

        $this->assertSame(['GET|HEAD api/export/excel'], $routesWith('throttle:service-secret')->all());
        $this->assertSame(['GET|HEAD api/export/excel'], $routesWith('throttle:api-export')->all());

        $this->assertSame(
            $routesWith('auth.token.store')->all(),
            $routesWith('auth.token.store')->intersect($routesWith('throttle:api'))->values()->all(),
        );
    }
}
