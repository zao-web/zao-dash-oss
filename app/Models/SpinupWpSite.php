<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class SpinupWpSite extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $hidden = [
        'wp_admin_password_encrypted',
        'database_password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'additional_domains' => 'array',
            'nginx_config' => 'array',
            'database_config' => 'array',
            'backup_config' => 'array',
            'git_config' => 'array',
            'is_wordpress' => 'boolean',
            'page_cache_enabled' => 'boolean',
            'https_enabled' => 'boolean',
            'basic_auth_enabled' => 'boolean',
            'provisioned_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    const STATUS_PENDING = 'pending';

    const STATUS_PROVISIONING = 'provisioning';

    const STATUS_DEPLOYED = 'deployed';

    const STATUS_FAILED = 'failed';

    const STATUS_DELETING = 'deleting';

    public function server(): BelongsTo
    {
        return $this->belongsTo(SpinupWpServer::class, 'spinup_server_id');
    }

    public function wordpressSite(): BelongsTo
    {
        return $this->belongsTo(WordPressSite::class);
    }

    public function websiteProject(): BelongsTo
    {
        return $this->belongsTo(WebsiteProject::class);
    }

    public function setWpAdminPasswordAttribute(?string $value): void
    {
        $this->attributes['wp_admin_password_encrypted'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getWpAdminPasswordAttribute(): ?string
    {
        $encrypted = $this->attributes['wp_admin_password_encrypted'] ?? null;

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    public function setDatabasePasswordAttribute(?string $value): void
    {
        $this->attributes['database_password_encrypted'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getDatabasePasswordAttribute(): ?string
    {
        $encrypted = $this->attributes['database_password_encrypted'] ?? null;

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    public function getUrlAttribute(): string
    {
        $scheme = $this->https_enabled ? 'https' : 'http';

        return "{$scheme}://{$this->domain}";
    }

    public function getAdminUrlAttribute(): string
    {
        return $this->url.'/wp-admin';
    }

    public function getGitDeploymentUrlAttribute(): ?string
    {
        return $this->git_config['deployment_url'] ?? null;
    }

    public function isProvisioned(): bool
    {
        return $this->status === self::STATUS_DEPLOYED;
    }

    public function isProvisioning(): bool
    {
        return $this->status === self::STATUS_PROVISIONING;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasGitEnabled(): bool
    {
        return ! empty($this->git_config['repo']);
    }

    public function scopeProvisioned($query)
    {
        return $query->where('status', self::STATUS_DEPLOYED);
    }

    public function scopeForServer($query, int $serverId)
    {
        return $query->where('spinup_server_id', $serverId);
    }

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('website_project_id', $projectId);
    }

    public function updateFromApi(array $data): bool
    {
        $updateData = [
            'domain' => $data['domain'] ?? $this->domain,
            'additional_domains' => $data['additional_domains'] ?? $this->additional_domains,
            'site_user' => $data['site_user'] ?? $this->site_user,
            'php_version' => $data['php_version'] ?? $this->php_version,
            'public_folder' => $data['public_folder'] ?? $this->public_folder,
            'is_wordpress' => $data['is_wordpress'] ?? $this->is_wordpress,
            'page_cache_enabled' => $data['page_cache']['enabled'] ?? $this->page_cache_enabled,
            'https_enabled' => $data['https']['enabled'] ?? $this->https_enabled,
            'nginx_config' => $data['nginx'] ?? $this->nginx_config,
            'database_config' => $data['database'] ?? $this->database_config,
            'backup_config' => $data['backups'] ?? $this->backup_config,
            'git_config' => $data['git'] ?? $this->git_config,
            'basic_auth_enabled' => $data['basic_auth']['enabled'] ?? $this->basic_auth_enabled,
            'basic_auth_username' => $data['basic_auth']['username'] ?? $this->basic_auth_username,
            'status' => $data['status'] ?? $this->status,
            'last_synced_at' => now(),
        ];

        if ($data['status'] === self::STATUS_DEPLOYED && ! $this->provisioned_at) {
            $updateData['provisioned_at'] = now();
        }

        return $this->update($updateData);
    }

    public function createLinkedWordPressSite(): WordPressSite
    {
        $wpSite = WordPressSite::updateOrCreate(
            ['url' => $this->url],
            [
                'name' => $this->domain,
                'username' => $this->wp_admin_user,
                'application_password' => $this->wp_admin_password,
                'mcp_enabled' => false,
                'is_primary' => false,
            ]
        );

        $this->update(['wordpress_site_id' => $wpSite->id]);

        return $wpSite;
    }
}
