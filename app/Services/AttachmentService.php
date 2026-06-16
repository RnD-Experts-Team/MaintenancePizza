<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

/**
 * Saves uploaded files (sent multipart alongside their parent record) to the
 * public disk and records them as polymorphic Attachment rows. Replaces the
 * former HandlesAttachments trait.
 */
class AttachmentService
{
    /**
     * @param  Model  $owner  A model exposing an `attachments()` morphMany relation.
     * @param  array<int, UploadedFile>  $files
     * @return array<int, Attachment> The attachments that were created.
     */
    public function store(Model $owner, array $files): array
    {
        $created = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('attachments', 'public');

            $created[] = $owner->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'created_by' => Auth::id(),
            ]);
        }

        return $created;
    }

    /**
     * Present a model's loaded `attachments` relation, or null when not loaded.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function presentMany(Model $owner): ?array
    {
        if (! $owner->relationLoaded('attachments')) {
            return null;
        }

        return $owner->attachments->map(fn (Attachment $a) => $this->present($a))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'path' => $attachment->path,
            // Frontend uses this directly; derived from the public disk.
            'url' => $attachment->url,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'created_by' => $attachment->created_by,
            'creator' => $attachment->relationLoaded('creator') && $attachment->creator
                ? ['id' => $attachment->creator->id, 'name' => $attachment->creator->name, 'email' => $attachment->creator->email]
                : null,
            'created_at' => $attachment->created_at,
        ];
    }
}
