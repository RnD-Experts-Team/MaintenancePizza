<?php

namespace App\Http\Middleware;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Services\TripService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `trip.token`: opens a trip from the token in a technician's link
 * (`public/trips/{token}/...`, docs/MAINTENANCE-V2.md §3).
 *
 *   unknown token                         404
 *   trip closed / mistaken / link idle    410 {message}
 *
 * Otherwise the trip is put on the request (`$request->attributes->get('trip')`),
 * the `{token}` parameter is taken off the route so controllers never see
 * it, and the link is stamped as used: every request through it, opening it
 * included, keeps it alive for another `link_idle_hours`.
 */
class TripTokenMiddleware
{
    public function __construct(private TripService $trips) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');
        $trip = $this->trips->findByToken($token);

        if ($trip === null) {
            return response()->json(['message' => 'This trip link does not exist. Ask the office for the right link.'], 404);
        }

        if (! $this->trips->linkActive($trip)) {
            return response()->json(['message' => $this->whyDead($trip)], 410);
        }

        $this->trips->markUsed($trip);

        $request->attributes->set('trip', $trip);
        $request->route()?->forgetParameter('token');

        return $next($request);
    }

    private function whyDead(Trip $trip): string
    {
        return match (true) {
            $trip->mistaken => 'This trip was cancelled. Ask the office for a new link.',
            $trip->status === TripStatus::Closed => 'This trip has been closed. Ask the office if you still need it.',
            default => 'This link has expired because it was not used for a while. Ask the office for a new one.',
        };
    }
}
