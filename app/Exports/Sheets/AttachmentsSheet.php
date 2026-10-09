<?php

namespace App\Exports\Sheets;

use App\Models\Attachment;
use App\Models\Note;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class AttachmentsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Attachments';
    }

    public function collection(): Collection
    {
        // Files on a private (locked) note are hidden with the note.
        return Attachment::query()
            ->whereNot(fn ($q) => $q
                ->where('attachable_type', (new Note())->getMorphClass())
                ->whereIn('attachable_id', Note::query()->where('is_private', true)->select('id')))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Attachable Type', 'Attachable ID', 'Path', 'URL', 'Original Name', 'Mime Type', 'Size', 'Created By', 'Created At'];
    }

    /**
     * @param  Attachment  $attachment
     * @return array<int, mixed>
     */
    public function map($attachment): array
    {
        return [
            $attachment->id,
            class_basename($attachment->attachable_type),
            $attachment->attachable_id,
            $attachment->path,
            $attachment->url,
            $attachment->original_name,
            $attachment->mime_type,
            $attachment->size,
            $attachment->created_by,
            (string) $attachment->created_at,
        ];
    }
}
