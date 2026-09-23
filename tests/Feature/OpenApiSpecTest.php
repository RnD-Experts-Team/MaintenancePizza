<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * public/openapi.json is the only contract the SPA has — there is no frontend
 * in this repo — so a route that exists but is undocumented is invisible to
 * the people who have to call it. These tests keep the two in step.
 */
class OpenApiSpecTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $spec = json_decode(file_get_contents(base_path('public/openapi.json')), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'openapi.json is not valid JSON.');

        return $spec;
    }

    public function test_every_api_route_is_documented(): void
    {
        $spec = $this->spec();

        $documented = collect(array_keys($spec['paths']))
            ->map(fn (string $p) => ltrim($p, '/'))
            ->all();

        $undocumented = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            // The export sits behind a shared secret rather than the usual auth.
            ->map(fn ($route) => substr($route->uri(), strlen('api/')))
            ->unique()
            ->reject(fn (string $uri) => in_array($uri, $documented, true))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $undocumented,
            "These API routes are not in public/openapi.json:\n  " . implode("\n  ", $undocumented)
        );
    }

    public function test_no_documented_path_is_missing_from_the_router(): void
    {
        $spec = $this->spec();

        $live = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route) => '/' . substr($route->uri(), strlen('api/')))
            ->unique()
            ->all();

        $stale = collect(array_keys($spec['paths']))
            ->reject(fn (string $path) => in_array($path, $live, true))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $stale,
            "These documented paths no longer exist:\n  " . implode("\n  ", $stale)
        );
    }

    /**
     * Every $ref must resolve, or the SPA's generated client breaks on a
     * dangling pointer.
     */
    public function test_every_internal_ref_resolves(): void
    {
        $spec = $this->spec();
        $broken = [];

        $walk = function ($node) use (&$walk, $spec, &$broken) {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/')) {
                    $target = $spec;

                    foreach (explode('/', substr($value, 2)) as $segment) {
                        if (! is_array($target) || ! array_key_exists($segment, $target)) {
                            $broken[] = $value;

                            continue 2;
                        }

                        $target = $target[$segment];
                    }

                    continue;
                }

                $walk($value);
            }
        };

        $walk($spec);

        $this->assertSame([], array_values(array_unique($broken)), 'Dangling $ref pointers in openapi.json.');
    }
}
