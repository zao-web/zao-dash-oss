<?php

namespace App\Observers;

use App\Events\NotificationCreated;
use App\Models\ApprovalRequest;
use App\Models\Notification;

class ApprovalRequestObserver
{
    public function created(ApprovalRequest $approval): void
    {
        $notification = Notification::approvalNeeded($approval);
        broadcast(new NotificationCreated($notification))->toOthers();
    }

    public function updated(ApprovalRequest $approval): void
    {
        // If approval was just decided, notify about the outcome
        if ($approval->wasChanged('status') && in_array($approval->status, ['approved', 'rejected'])) {
            $isApproved = $approval->status === 'approved';

            $notification = Notification::create([
                'user_id' => null,
                'type' => 'approval_decided',
                'title' => $isApproved ? 'Approval Granted' : 'Approval Rejected',
                'message' => "{$approval->action_type} was ".($isApproved ? 'approved' : 'rejected'),
                'icon' => $isApproved ? '✅' : '❌',
                'severity' => $isApproved ? 'success' : 'warning',
                'action_url' => '/approvals',
                'action_label' => 'View',
                'metadata' => [
                    'approval_id' => $approval->id,
                    'action_type' => $approval->action_type,
                    'decided_by' => $approval->decided_by,
                ],
            ]);

            broadcast(new NotificationCreated($notification))->toOthers();
        }
    }
}
