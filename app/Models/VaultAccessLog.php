<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultAccessLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'vault_secret_id',
        'accessor_type',
        'accessor_id',
        'accessor_name',
        'action',
        'ip_address',
        'user_agent',
        'context',
        'was_successful',
        'failure_reason',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'was_successful' => 'boolean',
        'created_at' => 'datetime',
    ];

    const TYPE_USER = 'user';

    const TYPE_AGENT = 'agent';

    const TYPE_SYSTEM = 'system';

    const ACTION_READ = 'read';

    const ACTION_WRITE = 'write';

    const ACTION_DELETE = 'delete';

    const ACTION_ROTATE = 'rotate';

    public function secret(): BelongsTo
    {
        return $this->belongsTo(VaultSecret::class, 'vault_secret_id');
    }

    public static function log(
        VaultSecret $secret,
        string $action,
        string $accessorType,
        ?int $accessorId = null,
        ?string $accessorName = null,
        bool $wasSuccessful = true,
        ?string $failureReason = null,
        array $context = []
    ): self {
        return static::create([
            'vault_secret_id' => $secret->id,
            'accessor_type' => $accessorType,
            'accessor_id' => $accessorId,
            'accessor_name' => $accessorName,
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'context' => $context,
            'was_successful' => $wasSuccessful,
            'failure_reason' => $failureReason,
            'created_at' => now(),
        ]);
    }
}
