<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class Client extends Model implements AuthenticatableContract
{
    // HasApiTokens: a Client can hold a Sanctum token so a client SITE can call
    // the scoped MCP server (/mcp/zao-client) as itself. The token IS the client,
    // so client-scoped tools can never reach another client's data.
    //
    // Authenticatable: the token's tokenable is returned by $request->user(), and
    // Laravel\Mcp\Request::user() is typed ?Authenticatable — so the Client must
    // implement that contract or the scoped MCP tools fatal on user().
    use AuthenticatableTrait, HasApiTokens, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_PROSPECT = 'prospect';

    public const STATUS_CHURNED = 'churned';

    public const STATUS_ARCHIVED = 'archived';

    protected $guarded = [];

    protected $casts = [
        'health_score' => 'decimal:1',
        'default_tax_rate' => 'decimal:2',
        'default_hourly_rate' => 'decimal:2',
        'recurring_invoice_enabled' => 'boolean',
        'recurring_invoice_amount' => 'decimal:2',
        'recurring_invoice_auto_send' => 'boolean',
        'recurring_invoice_in_advance' => 'boolean',
        'recurring_invoice_last_generated' => 'date',
    ];

    public function archive(): void
    {
        $this->update(['status' => self::STATUS_ARCHIVED]);
    }

    public function unarchive(): void
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Parsed billing CC addresses (comma/semicolon/newline separated input).
     * Invalid entries are dropped so a stray character can't break sends.
     *
     * @return array<int, string>
     */
    public function billingCcList(): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $this->billing_cc_emails))
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    public function scopeNotArchived($query)
    {
        return $query->where('status', '!=', self::STATUS_ARCHIVED);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function githubRepos(): HasMany
    {
        return $this->hasMany(GitHubRepo::class);
    }

    public function harvestProjects(): HasMany
    {
        return $this->hasMany(HarvestProject::class);
    }

    public function retainerPeriods(): HasMany
    {
        return $this->hasMany(RetainerPeriod::class);
    }

    public function activeRetainer(): ?RetainerPeriod
    {
        return RetainerPeriod::currentForClient($this->id);
    }

    public function clientReports(): HasMany
    {
        return $this->hasMany(ClientReport::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function harvestInvoices(): HasMany
    {
        return $this->hasMany(HarvestInvoice::class);
    }

    public function healthAlerts(): HasMany
    {
        return $this->hasMany(HealthAlert::class);
    }

    public function unresolvedHealthAlerts(): HasMany
    {
        return $this->healthAlerts()->unresolved();
    }

    public function pmConnections(): HasMany
    {
        return $this->hasMany(PmConnection::class);
    }

    public function clientReportSettings(): HasOne
    {
        return $this->hasOne(ClientReportSettings::class);
    }

    public function slackChannel(): BelongsTo
    {
        return $this->belongsTo(SlackChannel::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class);
    }
}
