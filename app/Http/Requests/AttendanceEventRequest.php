<?php

namespace App\Http\Requests;

use App\Enums\AttendanceEventKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One thing that happened on the clock.
 *
 * `at` is required, unlike the nullable clock columns this replaces: an event
 * with no time is not an event. "He has not clocked out yet" is now said by the
 * ABSENCE of a clock-out event rather than by a null in a column, which is both
 * more honest and something the system can act on.
 *
 * NO ORDERING RULES, deliberately, and none should be added. A coordinator
 * writing up a visit from a phone call enters events as they are remembered,
 * and the stream re-sorts by time. durations() is written defensively precisely
 * so this layer never has to reject a real day for arriving out of order.
 */
class AttendanceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Absent on PATCH, which only ever moves an event in time.
            'kind' => [
                $this->isMethod('POST') ? 'required' : 'prohibited',
                Rule::enum(AttendanceEventKind::class),
            ],
            'at' => ['required', 'date'],
        ];
    }
}
