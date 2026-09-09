<?php

namespace App\Services\Tax;

use App\Models\TaxFormFieldOverride;
use Illuminate\Support\Facades\Cache;

/**
 * Returns merged tax form field coordinates: config defaults + database overrides.
 *
 * Defaults live in config/tax-form-fields.php (committed).
 * Overrides live in the tax_form_field_overrides table (set via the calibration UI).
 * For any field with an override row, that row's x/y/page wins. Other attributes
 * (size, align, width, font) always come from the config defaults.
 */
class TaxFormFieldRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function fieldsFor(string $formCode): array
    {
        $defaults = config("tax-form-fields.{$formCode}", []);

        if ($defaults === []) {
            return [];
        }

        $overrides = $this->overridesFor($formCode);

        if ($overrides === []) {
            return $defaults;
        }

        foreach ($overrides as $key => $override) {
            if (! isset($defaults[$key])) {
                continue;
            }

            $defaults[$key]['x'] = $override['x'];
            $defaults[$key]['y'] = $override['y'];
            $defaults[$key]['page'] = $override['page'];
        }

        return $defaults;
    }

    /**
     * @return array<string, array{x: float, y: float, page: int}>
     */
    public function overridesFor(string $formCode): array
    {
        return Cache::remember(
            "tax-form-overrides:{$formCode}",
            now()->addMinutes(10),
            fn (): array => TaxFormFieldOverride::query()
                ->where('form_code', $formCode)
                ->get(['field_key', 'x', 'y', 'page'])
                ->mapWithKeys(fn (TaxFormFieldOverride $o): array => [
                    $o->field_key => [
                        'x' => (float) $o->x,
                        'y' => (float) $o->y,
                        'page' => (int) $o->page,
                    ],
                ])
                ->all(),
        );
    }

    public function setOverride(string $formCode, string $fieldKey, float $x, float $y, int $page): void
    {
        TaxFormFieldOverride::updateOrCreate(
            ['form_code' => $formCode, 'field_key' => $fieldKey],
            ['x' => round($x, 1), 'y' => round($y, 1), 'page' => $page],
        );

        $this->forgetCache($formCode);
    }

    public function resetForm(string $formCode): int
    {
        $count = TaxFormFieldOverride::where('form_code', $formCode)->delete();
        $this->forgetCache($formCode);

        return $count;
    }

    public function forgetCache(string $formCode): void
    {
        Cache::forget("tax-form-overrides:{$formCode}");
    }
}
