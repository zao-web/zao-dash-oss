<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a Google Sheet to a Zao client so SheetTaskSyncService knows what
 * to read, how to interpret the columns, and where to write status updates.
 *
 * column_map shape (logical → header label):
 *   [
 *     'title'        => 'Task / Issue Description',
 *     'status'       => 'Status',
 *     'priority'     => 'Priority',
 *     'asset'        => 'Asset',
 *     'task_type'    => 'Task Type',
 *     'user_type'    => 'User Type',
 *     'notes'        => 'Notes',
 *     'website_link' => 'Website Link',
 *     'zao_id'       => 'Zao ID',
 *   ]
 *
 * Header labels are matched case-insensitively against the row at header_row.
 */
class ClientSheetSync extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'column_map' => 'array',
        'header_row' => 'integer',
        'sheet_gid' => 'integer',
        'active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Default column map for the Locumpedia tracker layout. Used as a
     * sensible starting point when configuring a new client sheet.
     *
     * @return array<string, string>
     */
    public static function defaultColumnMap(): array
    {
        return [
            'title' => 'Task / Issue Description',
            'asset' => 'Asset',
            'task_type' => 'Task Type',
            'user_type' => 'User Type',
            'priority' => 'Priority',
            'notes' => 'Notes',
            'website_link' => 'Website Link',
            'status' => 'Status',
            'zao_id' => 'Zao ID',
        ];
    }
}
