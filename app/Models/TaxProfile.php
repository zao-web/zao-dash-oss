<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class TaxProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reasonable_salary' => 'decimal:2',
        'w2_wages_paid' => 'decimal:2',
        'prior_year_tax_liability' => 'decimal:2',
        'prior_year_agi' => 'decimal:2',
        'prior_year_federal_overpayment_applied' => 'decimal:2',
        'prior_year_oregon_overpayment_applied' => 'decimal:2',
        'prior_year_capital_loss_carryforward' => 'decimal:2',
        'prior_year_nol_carryforward' => 'decimal:2',
        'prior_year_shareholder_basis' => 'decimal:2',
        'mortgage_interest_paid' => 'decimal:2',
        'property_tax_paid' => 'decimal:2',
        'charitable_contributions_paid' => 'decimal:2',
        'medical_expenses_paid' => 'decimal:2',
        'hsa_contributions_paid' => 'decimal:2',
        'education_expenses_paid' => 'decimal:2',
        'health_insurance_annual' => 'decimal:2',
        'dependents' => 'array',
        's_election_date' => 'date',
        'taxpayer_dob' => 'date',
        'spouse_dob' => 'date',
        'has_solo_401k' => 'boolean',
        'has_hsa' => 'boolean',
    ];

    protected $hidden = [
        'ssn_encrypted',
        'spouse_ssn_encrypted',
        'entity_ein_encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->where('tax_year', $year);
    }

    public function scopeCurrent($query)
    {
        return $query->where('tax_year', now()->year);
    }

    public function getDependentCountAttribute(): int
    {
        return count($this->dependents ?? []);
    }

    public function getSsnAttribute(): ?string
    {
        return $this->decryptField($this->ssn_encrypted);
    }

    public function getSpouseSsnAttribute(): ?string
    {
        return $this->decryptField($this->spouse_ssn_encrypted);
    }

    public function getEntityEinAttribute(): ?string
    {
        return $this->decryptField($this->entity_ein_encrypted);
    }

    public function getHomeOfficePercentAttribute(): float
    {
        if ($this->home_total_sqft <= 0) {
            return 0;
        }

        return min(($this->home_office_sqft / $this->home_total_sqft) * 100, 100);
    }

    protected function decryptField(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
