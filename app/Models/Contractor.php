<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Contractor extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_us_person' => 'boolean',
        'has_w9_on_file' => 'boolean',
        'w9_received_at' => 'datetime',
        'wise_recipient_details' => 'array',
        'recurring_amount' => 'decimal:2',
    ];

    protected $hidden = [
        'tax_id_encrypted',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_TERMINATED = 'terminated';

    // Onboarding constants
    public const ONBOARDING_INVITED = 'invited';

    public const ONBOARDING_INFO_SUBMITTED = 'info_submitted';

    public const ONBOARDING_BANK_VERIFIED = 'bank_verified';

    public const ONBOARDING_COMPLETE = 'complete';

    // Payment type constants
    public const PAYMENT_RECURRING = 'recurring';

    public const PAYMENT_INVOICE = 'invoice';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($contractor) {
            if (empty($contractor->uuid)) {
                $contractor->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(ContractorInvoice::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(WiseTransfer::class);
    }

    // Encrypted tax_id accessors
    public function setTaxIdAttribute($value): void
    {
        if ($value) {
            $this->attributes['tax_id_encrypted'] = Crypt::encryptString($value);
            $this->attributes['tax_id_last_four'] = substr(preg_replace('/[^0-9]/', '', $value), -4);
        }
    }

    public function getTaxIdAttribute(): ?string
    {
        return $this->tax_id_encrypted ? Crypt::decryptString($this->tax_id_encrypted) : null;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeUsContractors($query)
    {
        return $query->where('is_us_person', true);
    }

    public function scopeInternational($query)
    {
        return $query->where('is_us_person', false);
    }

    public function scopeRecurring($query)
    {
        return $query->where('payment_type', self::PAYMENT_RECURRING);
    }

    public function scopeNeedsW9($query)
    {
        return $query->where('is_us_person', true)
            ->where('has_w9_on_file', false);
    }

    public function scopeOnboardingComplete($query)
    {
        return $query->where('onboarding_status', self::ONBOARDING_COMPLETE);
    }

    // Helpers
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOnboardingComplete(): bool
    {
        return $this->onboarding_status === self::ONBOARDING_COMPLETE;
    }

    public function canReceivePayments(): bool
    {
        return $this->isActive()
            && $this->isOnboardingComplete()
            && $this->wise_recipient_id !== null;
    }

    public function requiresW9(): bool
    {
        return $this->is_us_person && ! $this->has_w9_on_file;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->company_name ?: $this->name;
    }

    public function getPaymentSetupUrlAttribute(): string
    {
        return route('my.payment-setup');
    }

    public function getTotalPaidThisYearAttribute(): float
    {
        return $this->transfers()
            ->where('status', 'completed')
            ->whereYear('created_at', now()->year)
            ->sum('source_amount');
    }
}
