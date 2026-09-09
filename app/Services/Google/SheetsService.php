<?php

namespace App\Services\Google;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin wrapper around the Google Sheets v4 API for the per-client sheet
 * task tracker. Bidirectional sync semantics live in SheetTaskSyncService;
 * this class only knows how to read/write cells and ranges.
 */
class SheetsService
{
    private const BASE_URL = 'https://sheets.googleapis.com/v4/spreadsheets';

    public function __construct(
        private GoogleOAuthService $oauth
    ) {}

    /**
     * Fetch the workbook metadata: list of tabs (sheets) with their gid + title.
     *
     * @return array{spreadsheetId: string, properties: array<string,mixed>, sheets: array<int, array<string,mixed>>}
     */
    public function getMetadata(User $user, string $spreadsheetId): array
    {
        $token = $this->oauth->getValidAccessToken($user);
        $response = Http::withToken($token)
            ->get(self::BASE_URL."/{$spreadsheetId}", [
                'fields' => 'spreadsheetId,properties.title,sheets(properties(sheetId,title,index,gridProperties))',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google Sheets getMetadata failed: '.$response->status().' '.$response->body());
        }

        return $response->json();
    }

    /**
     * Read a rectangular range. Returns a 2D array of strings (rows of cells).
     *
     * @return array<int, array<int, string>>
     */
    public function getValues(User $user, string $spreadsheetId, string $range, string $valueRenderOption = 'UNFORMATTED_VALUE'): array
    {
        $token = $this->oauth->getValidAccessToken($user);
        $response = Http::withToken($token)
            ->get(self::BASE_URL."/{$spreadsheetId}/values/".rawurlencode($range), [
                'valueRenderOption' => $valueRenderOption,
                'dateTimeRenderOption' => 'FORMATTED_STRING',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google Sheets getValues failed: '.$response->status().' '.$response->body());
        }

        return $response->json('values', []);
    }

    /**
     * Fetch a range with BOTH displayed values AND the underlying formulas.
     * Used so we can surface `=HYPERLINK("url", "label")` URLs that
     * UNFORMATTED_VALUE strips. Returns parallel 2D arrays.
     *
     * @return array{values: array<int, array<int, string>>, formulas: array<int, array<int, string>>}
     */
    public function getValuesAndFormulas(User $user, string $spreadsheetId, string $range): array
    {
        return [
            'values' => $this->getValues($user, $spreadsheetId, $range, 'UNFORMATTED_VALUE'),
            'formulas' => $this->getValues($user, $spreadsheetId, $range, 'FORMULA'),
        ];
    }

    /**
     * If a cell's formula is `=HYPERLINK("url", "label")`, return the URL.
     * Otherwise return null. Case-insensitive, tolerant of whitespace.
     */
    public static function extractHyperlinkUrl(?string $formula): ?string
    {
        if (! is_string($formula) || $formula === '' || $formula[0] !== '=') {
            return null;
        }
        if (! preg_match('/^=\s*HYPERLINK\s*\(\s*"((?:[^"\\\\]|\\\\.)*)"/i', $formula, $matches)) {
            return null;
        }

        return stripcslashes($matches[1]);
    }

    /**
     * Write a single cell. Range example: "Sheet1!K2".
     */
    public function updateCell(User $user, string $spreadsheetId, string $range, string $value): void
    {
        $this->updateValues($user, $spreadsheetId, $range, [[$value]]);
    }

    /**
     * Write a rectangular range of values.
     *
     * @param  array<int, array<int, mixed>>  $values
     */
    public function updateValues(User $user, string $spreadsheetId, string $range, array $values): void
    {
        $token = $this->oauth->getValidAccessToken($user);
        $response = Http::withToken($token)
            ->put(self::BASE_URL."/{$spreadsheetId}/values/".rawurlencode($range).'?valueInputOption=USER_ENTERED', [
                'range' => $range,
                'majorDimension' => 'ROWS',
                'values' => $values,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google Sheets updateValues failed: '.$response->status().' '.$response->body());
        }
    }

    /**
     * Apply multiple value updates in a single API call. Each update is
     * [range, values]. Used to assign UUIDs to many rows at once without
     * burning a quota call per row.
     *
     * @param  array<int, array{range: string, values: array<int, array<int, mixed>>}>  $updates
     */
    public function batchUpdateValues(User $user, string $spreadsheetId, array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $token = $this->oauth->getValidAccessToken($user);
        $response = Http::withToken($token)
            ->post(self::BASE_URL."/{$spreadsheetId}/values:batchUpdate", [
                'valueInputOption' => 'USER_ENTERED',
                'data' => array_map(fn ($u) => [
                    'range' => $u['range'],
                    'majorDimension' => 'ROWS',
                    'values' => $u['values'],
                ], $updates),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google Sheets batchUpdateValues failed: '.$response->status().' '.$response->body());
        }
    }

    /**
     * Apply structural updates (insert column, format cells, etc.) via the
     * batchUpdate endpoint. Used to add the Zao ID column on first sync.
     *
     * @param  array<int, array<string, mixed>>  $requests
     */
    public function batchUpdate(User $user, string $spreadsheetId, array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        $token = $this->oauth->getValidAccessToken($user);
        $response = Http::withToken($token)
            ->post(self::BASE_URL."/{$spreadsheetId}:batchUpdate", [
                'requests' => $requests,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Google Sheets batchUpdate failed: '.$response->status().' '.$response->body());
        }

        return $response->json();
    }

    /**
     * Convert a 1-based column number to its A1 letter (1=A, 27=AA, etc.).
     */
    public static function columnLetter(int $oneBased): string
    {
        if ($oneBased < 1) {
            throw new \InvalidArgumentException('Column number must be >= 1');
        }

        $letters = '';
        while ($oneBased > 0) {
            $oneBased--;
            $letters = chr(65 + ($oneBased % 26)).$letters;
            $oneBased = intdiv($oneBased, 26);
        }

        return $letters;
    }

    /**
     * Generate a UUID-like ID suitable for the Zao ID column. Short prefix
     * makes it visually obvious in the sheet that we own this cell.
     */
    public static function generateRowId(): string
    {
        return 'zao_'.Str::ulid()->toBase32();
    }

    /**
     * Log without leaking the token. Useful when debugging quota errors.
     */
    protected function debug(string $message, array $context = []): void
    {
        Log::debug('[GoogleSheets] '.$message, $context);
    }
}
