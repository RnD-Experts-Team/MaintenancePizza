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
     * Before / after photos of a job have no column of their own: they are
     * ordinary attachments whose original_name starts with one of these, and
     * present() reads the prefix back as `kind`.
     */
    public const BEFORE_PREFIX = 'Before - ';

    public const AFTER_PREFIX = 'After - ';

    /**
     * @param  Model  $owner  A model exposing an `attachments()` morphMany relation.
     * @param  array<int, UploadedFile>  $files
     * @param  string|null  $namePrefix  put in front of each original name
     *                                   (BEFORE_PREFIX / AFTER_PREFIX)
     * @return array<int, Attachment> The attachments that were created.
     */
    public function store(Model $owner, array $files, ?string $namePrefix = null): array
    {
        $created = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('attachments', 'public');

            $created[] = $owner->attachments()->create([
                'path' => $path,
                'original_name' => mb_substr(($namePrefix ?? '').$file->getClientOriginalName(), 0, 255),
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
            // before | after for a job's photos (see BEFORE_PREFIX), else null.
            'kind' => $this->kindOf($attachment->original_name),
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'created_by' => $attachment->created_by,
            'creator' => $attachment->relationLoaded('creator') && $attachment->creator
                ? ['id' => $attachment->creator->id, 'name' => $attachment->creator->name, 'email' => $attachment->creator->email]
                : null,
            'created_at' => $attachment->created_at,
        ];
    }

    /**
     * before | after when the name carries that prefix, otherwise null.
     */
    public function kindOf(?string $originalName): ?string
    {
        return match (true) {
            $originalName !== null && str_starts_with($originalName, self::BEFORE_PREFIX) => 'before',
            $originalName !== null && str_starts_with($originalName, self::AFTER_PREFIX) => 'after',
            default => null,
        };
    }
}
