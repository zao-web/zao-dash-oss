<?php

namespace App\Services\Reports;

use App\Models\TimeEntry;

/**
 * Applies human corrections to a retainer period's time entries.
 *
 * The narrative generator writes entries with source='ai_estimated' and wipes
 * them on every regenerate. Any entry a human corrects is promoted to
 * source='manual' so it survives a subsequent narrative refresh — the operator's
 * ground truth always wins over the AI estimate.
 */
class RetainerTimeEntryAdjuster
{
    /**
     * Apply corrections to an entry and promote it to a manual (human-owned)
     * record. Only the provided fields are changed.
     *
     * @param  array{hours?: float|int|string, notes?: string, is_billable?: bool}  $changes
     */
    public function adjust(TimeEntry $entry, array $changes): TimeEntry
    {
        if (array_key_exists('hours', $changes)) {
            $entry->hours = round((float) $changes['hours'], 2);
        }

        if (array_key_exists('notes', $changes)) {
            $entry->notes = $changes['notes'];
        }

        if (array_key_exists('is_billable', $changes)) {
            $entry->is_billable = (bool) $changes['is_billable'];
        }

        // Promote: a corrected entry is no longer an AI estimate, so the next
        // narrative regenerate (which deletes ai_estimated rows) won't erase it.
        $entry->source = 'manual';
        $entry->save();

        return $entry;
    }

    /**
     * Remove an entry entirely — e.g. work the client handled themselves, or a
     * topic the AI invented. Hard delete; if the narrative is later regenerated
     * the AI may re-surface the same work from the raw evidence, so removals are
     * best done once the period's evidence is settled.
     */
    public function remove(TimeEntry $entry): void
    {
        $entry->delete();
    }
}
