<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpinupWpServer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'disk_space' => 'array',
            'database_config' => 'array',
            'reboot_required' => 'boolean',
            'upgrade_required' => 'boolean',
            'is_default' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    const STATUS_PROVISIONING = 'provisioning';

    const STATUS_PROVISIONED = 'provisioned';

    const STATUS_FAILED = 'failed';

    const CONNECTION_CONNECTED = 'connected';

    const CONNECTION_DISCONNECTED = 'disconnected';

    const CONNECTION_UNKNOWN = 'unknown';

    public function sites(): HasMany
    {
        return $this->hasMany(SpinupWpSite::class, 'spinup_server_id');
    }

    public function isProvisioned(): bool
    {
        return $this->status === self::STATUS_PROVISIONED;
    }

    public function isConnected(): bool
    {
        return $this->connection_status === self::CONNECTION_CONNECTED;
    }

    public function canHostSites(): bool
    {
        return $this->isProvisioned() && $this->isConnected();
    }

    public function getDiskUsagePercent(): ?int
    {
        if (! $this->disk_space || ! isset($this->disk_space['total'], $this->disk_space['used'])) {
            return null;
        }

        $total = $this->disk_space['total'];
        if ($total <= 0) {
            return null;
        }

        return (int) round(($this->disk_space['used'] / $total) * 100);
    }

    public function getDiskAvailableGb(): ?float
    {
        if (! $this->disk_space || ! isset($this->disk_space['available'])) {
            return null;
        }

        $bytesPerGb = 1073741824;

        return round($this->disk_space['available'] / $bytesPerGb, 2);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', self::STATUS_PROVISIONED)
            ->where('connection_status', self::CONNECTION_CONNECTED);
    }

    public function setAsDefault(): bool
    {
        static::where('is_default', true)->update(['is_default' => false]);

        return $this->update(['is_default' => true]);
    }

    public function updateFromApi(array $data): bool
    {
        return $this->update([
            'name' => $data['name'] ?? $this->name,
            'provider_name' => $data['provider_name'] ?? $this->provider_name,
            'ubuntu_version' => $data['ubuntu_version'] ?? $this->ubuntu_version,
            'ip_address' => $data['ip_address'] ?? $this->ip_address,
            'ssh_port' => $data['ssh_port'] ?? $this->ssh_port,
            'timezone' => $data['timezone'] ?? $this->timezone,
            'region' => $data['region'] ?? $this->region,
            'size' => $data['size'] ?? $this->size,
            'disk_space' => $data['disk_space'] ?? $this->disk_space,
            'database_config' => $data['database'] ?? $this->database_config,
            'ssh_publickey' => $data['ssh_publickey'] ?? $this->ssh_publickey,
            'git_publickey' => $data['git_publickey'] ?? $this->git_publickey,
            'connection_status' => $data['connection_status'] ?? $this->connection_status,
            'reboot_required' => $data['reboot_required'] ?? $this->reboot_required,
            'upgrade_required' => $data['upgrade_required'] ?? $this->upgrade_required,
            'install_notes' => $data['install_notes'] ?? $this->install_notes,
            'status' => $data['status'] ?? $this->status,
            'last_synced_at' => now(),
        ]);
    }
}
