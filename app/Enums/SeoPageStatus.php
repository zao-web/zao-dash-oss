<?php

namespace App\Enums;

enum SeoPageStatus: string
{
    case Queued = 'queued';
    case Generating = 'generating';
    case Published = 'published';
    case Failed = 'failed';
    case Draft = 'draft';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Generating => 'Generating',
            self::Published => 'Published',
            self::Failed => 'Failed',
            self::Draft => 'Draft',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Generating => 'yellow',
            self::Published => 'green',
            self::Failed => 'red',
            self::Draft => 'blue',
            self::Archived => 'slate',
        };
    }
}
