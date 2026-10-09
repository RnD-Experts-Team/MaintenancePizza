<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksTicketRecord;
use App\Services\NoteService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lock (MOS only) or unlock a note on a ticket. Who may is the auth rule's
 * business; this checks the note is on the ticket in the URL (404 if not).
 */
class UpdateNotePrivacyRequest extends FormRequest
{
    use ChecksTicketRecord;

    public function authorize(): bool
    {
        $this->checkTicketRecord();

        abort_unless(
            app(NoteService::class)->isOnTicket($this->route('note'), $this->route('ticket')),
            404,
        );

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_private' => ['required', 'boolean'],
        ];
    }
}
