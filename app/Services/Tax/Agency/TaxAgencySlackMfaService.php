<?php

namespace App\Services\Tax\Agency;

use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\TaxAgencyConnection;
use App\Models\TaxAgencyMfaChallenge;
use App\Models\User;
use App\Services\Slack\SlackApiService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TaxAgencySlackMfaService
{
    public const DEFAULT_POLL_AFTER_SECONDS = 5;

    public function __construct(
        protected SlackApiService $slackApiService,
    ) {}

    public function requestCode(
        User $user,
        string $agencyCode,
        ?TaxAgencyConnection $connection = null,
        array $context = [],
    ): TaxAgencyMfaChallenge {
        $workspace = $this->workspace();
        if (! $workspace) {
            throw new RuntimeException('No Slack workspace is configured for tax MFA delivery.');
        }

        $slackUserId = $this->resolveSlackUserId($workspace, $user, $context);
        if (! is_string($slackUserId) || $slackUserId === '') {
            throw new RuntimeException('Slack user could not be resolved for tax MFA delivery.');
        }

        $slackChannelId = $this->resolveSlackChannelId($workspace, $slackUserId, $context);
        if (! is_string($slackChannelId) || $slackChannelId === '') {
            throw new RuntimeException('Slack DM channel could not be opened for tax MFA delivery.');
        }

        $promptMessage = $this->promptMessage($agencyCode, $context);

        $challenge = DB::transaction(function () use ($user, $connection, $workspace, $agencyCode, $slackUserId, $slackChannelId, $promptMessage, $context): TaxAgencyMfaChallenge {
            TaxAgencyMfaChallenge::query()
                ->where('user_id', $user->id)
                ->where('agency_code', $agencyCode)
                ->where('status', TaxAgencyMfaChallenge::STATUS_PENDING)
                ->update([
                    'status' => TaxAgencyMfaChallenge::STATUS_CANCELLED,
                    'updated_at' => now(),
                ]);

            SlackChannel::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'channel_id' => $slackChannelId,
                ],
                [
                    'channel_name' => "DM: {$user->name}",
                    'is_private' => true,
                    'is_shared' => false,
                    'is_dm' => true,
                    'monitoring_enabled' => true,
                    'classification' => 'internal',
                ],
            );

            return TaxAgencyMfaChallenge::query()->create([
                'user_id' => $user->id,
                'tax_agency_connection_id' => $connection?->id,
                'slack_workspace_id' => $workspace->id,
                'agency_code' => $agencyCode,
                'challenge_type' => (string) ($context['challenge_type'] ?? 'sms_code'),
                'delivery_method' => 'slack_dm',
                'status' => TaxAgencyMfaChallenge::STATUS_PENDING,
                'slack_channel_id' => $slackChannelId,
                'slack_user_id' => $slackUserId,
                'prompt_message' => $promptMessage,
                'context' => $context,
                'requested_at' => now(),
                'expires_at' => now()->addMinutes((int) ($context['expires_in_minutes'] ?? 10)),
            ]);
        });

        $this->slackApiService->postMessage($workspace, $slackChannelId, $promptMessage);

        return $challenge->fresh();
    }

    public function capturePendingCodeFromSlackMessage(SlackWorkspace $workspace, SlackChannel $channel, array $event): bool
    {
        if (! $channel->is_dm) {
            return false;
        }

        $slackUserId = (string) ($event['user'] ?? '');
        $messageText = trim((string) ($event['text'] ?? ''));
        if ($slackUserId === '' || $messageText === '') {
            return false;
        }

        $challenge = TaxAgencyMfaChallenge::query()
            ->pending()
            ->where('slack_workspace_id', $workspace->id)
            ->where('slack_user_id', $slackUserId)
            ->orderByDesc('requested_at')
            ->first();

        if (! $challenge) {
            return false;
        }

        if (Str::lower($messageText) === 'cancel') {
            $challenge->forceFill([
                'status' => TaxAgencyMfaChallenge::STATUS_CANCELLED,
            ])->save();

            $this->slackApiService->postMessage($workspace, $channel->channel_id, "Cancelled the pending {$this->agencyLabel($challenge->agency_code)} MFA request.");

            return true;
        }

        $code = $this->extractCode($messageText);
        if ($code === null) {
            return false;
        }

        $challenge->forceFill([
            'status' => TaxAgencyMfaChallenge::STATUS_RESOLVED,
            'response_code' => $code,
            'response_message_ts' => (string) ($event['ts'] ?? ''),
            'resolved_at' => now(),
        ])->save();

        $this->slackApiService->postMessage($workspace, $channel->channel_id, "Received the {$this->agencyLabel($challenge->agency_code)} code. Continuing the sign-in flow.");

        return true;
    }

    /**
     * @return array{code: string, challenge_id: int, resolved_at: string|null}|null
     */
    public function consumeResolvedCode(User $user, string $agencyCode): ?array
    {
        /** @var ?TaxAgencyMfaChallenge $challenge */
        $challenge = TaxAgencyMfaChallenge::query()
            ->where('user_id', $user->id)
            ->where('agency_code', $agencyCode)
            ->where('status', TaxAgencyMfaChallenge::STATUS_RESOLVED)
            ->orderByDesc('resolved_at')
            ->first();

        if (! $challenge || ! is_string($challenge->response_code) || $challenge->response_code === '') {
            return null;
        }

        $challenge->forceFill([
            'status' => TaxAgencyMfaChallenge::STATUS_CONSUMED,
            'consumed_at' => now(),
        ])->save();

        return [
            'code' => $challenge->response_code,
            'challenge_id' => $challenge->id,
            'resolved_at' => $challenge->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     challenge_id: int,
     *     delivery_method: string,
     *     challenge_type: string,
     *     requested_at: string|null,
     *     resolved_at: string|null,
     *     expires_at: string|null,
     * }|null
     */
    public function latestChallengeState(User $user, string $agencyCode): ?array
    {
        /** @var ?TaxAgencyMfaChallenge $challenge */
        $challenge = TaxAgencyMfaChallenge::query()
            ->where('user_id', $user->id)
            ->where('agency_code', $agencyCode)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->first();

        if (! $challenge) {
            return null;
        }

        if ($challenge->status === TaxAgencyMfaChallenge::STATUS_PENDING
            && $challenge->expires_at !== null
            && $challenge->expires_at->isPast()) {
            $challenge->forceFill([
                'status' => TaxAgencyMfaChallenge::STATUS_EXPIRED,
            ])->save();
        }

        return [
            'status' => (string) $challenge->status,
            'challenge_id' => $challenge->id,
            'delivery_method' => (string) $challenge->delivery_method,
            'challenge_type' => (string) $challenge->challenge_type,
            'requested_at' => $challenge->requested_at?->toIso8601String(),
            'resolved_at' => $challenge->resolved_at?->toIso8601String(),
            'expires_at' => $challenge->expires_at?->toIso8601String(),
        ];
    }

    protected function workspace(): ?SlackWorkspace
    {
        return SlackWorkspace::query()
            ->orderByDesc('is_primary')
            ->orderByDesc('is_active')
            ->first();
    }

    protected function resolveSlackUserId(SlackWorkspace $workspace, User $user, array $context): ?string
    {
        $contextUserId = Arr::get($context, 'slack_user_id');
        if (is_string($contextUserId) && $contextUserId !== '') {
            return $contextUserId;
        }

        $slackUser = $this->slackApiService->lookupUserByEmail($workspace, $user->email);

        return is_array($slackUser) ? (string) ($slackUser['id'] ?? '') : null;
    }

    protected function resolveSlackChannelId(SlackWorkspace $workspace, string $slackUserId, array $context): ?string
    {
        $contextChannelId = Arr::get($context, 'slack_channel_id');
        if (is_string($contextChannelId) && $contextChannelId !== '') {
            return $contextChannelId;
        }

        return $this->slackApiService->openDirectMessage($workspace, $slackUserId);
    }

    protected function promptMessage(string $agencyCode, array $context): string
    {
        $codeLength = (int) ($context['code_length'] ?? 6);
        $delivery = (string) ($context['delivery_hint'] ?? 'text message');

        return "Tax autopilot needs the {$this->agencyLabel($agencyCode)} {$codeLength}-digit MFA code from your {$delivery}. Reply in this DM with only the code within 10 minutes, or reply `cancel`.";
    }

    protected function agencyLabel(string $agencyCode): string
    {
        return match ($agencyCode) {
            TaxAgencyConnection::AGENCY_IRS => 'IRS',
            TaxAgencyConnection::AGENCY_OREGON_DOR => 'Oregon Revenue Online',
            default => strtoupper($agencyCode),
        };
    }

    protected function extractCode(string $messageText): ?string
    {
        if (preg_match('/\b(\d{6,8})\b/', $messageText, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
