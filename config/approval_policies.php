<?php

/**
 * Approval Policies Configuration
 *
 * Defines approval requirements, risk levels, and auto-approve conditions
 * for different action categories.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Approval Categories
    |--------------------------------------------------------------------------
    |
    | Each category defines:
    | - risk_level: critical, high, medium, low
    | - requires_approval: boolean
    | - auto_approve_conditions: array of conditions for auto-approval
    | - expires_hours: hours until approval request expires
    | - notify_roles: roles to notify when approval is needed
    |
    */
    'categories' => [
        /*
        |----------------------------------------------------------------------
        | Deployment Actions
        |----------------------------------------------------------------------
        */
        'deploy.production' => [
            'risk_level' => 'critical',
            'requires_approval' => true,
            'auto_approve_conditions' => [], // Never auto-approve
            'expires_hours' => 24,
            'notify_roles' => ['owner', 'admin'],
            'description' => 'Production deployment',
        ],

        'deploy.staging' => [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'auto_approve_conditions' => [
                'tests_pass' => true,
                'no_breaking_changes' => true,
            ],
            'expires_hours' => 12,
            'notify_roles' => ['owner', 'admin', 'developer'],
            'description' => 'Staging deployment',
        ],

        /*
        |----------------------------------------------------------------------
        | Financial Actions
        |----------------------------------------------------------------------
        */
        'financial.invoice' => [
            'risk_level' => 'high',
            'requires_approval' => true,
            'auto_approve_conditions' => [
                'amount_under' => 500,
                'existing_client' => true,
            ],
            'expires_hours' => 48,
            'notify_roles' => ['owner', 'admin'],
            'description' => 'Send invoice to client',
        ],

        'financial.payment' => [
            'risk_level' => 'critical',
            'requires_approval' => true,
            'auto_approve_conditions' => [], // Never auto-approve payments
            'expires_hours' => 24,
            'notify_roles' => ['owner'],
            'description' => 'Process payment',
        ],

        'financial.categorize' => [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'auto_approve_conditions' => [
                'amount_under' => 100,
            ],
            'expires_hours' => 72,
            'notify_roles' => ['owner', 'admin'],
            'description' => 'Categorize expense in QuickBooks',
        ],

        /*
        |----------------------------------------------------------------------
        | Wise / Contractor Payment Actions
        |----------------------------------------------------------------------
        */
        'wise.payment' => [
            'risk_level' => 'critical',
            'requires_approval' => true,
            'auto_approve_conditions' => [], // NEVER auto-approve wire transfers
            'expires_hours' => 24,
            'notify_roles' => ['owner'], // ONLY owner can approve
            'requires_2fa' => true,
            'description' => 'Initiate wire transfer via Wise',
        ],

        'contractor.invoice_approval' => [
            'risk_level' => 'high',
            'requires_approval' => true,
            'auto_approve_conditions' => [
                'amount_under' => 500,
                'recurring_contractor' => true,
            ],
            'expires_hours' => 48,
            'notify_roles' => ['owner'],
            'description' => 'Approve contractor invoice for payment',
        ],

        'contractor.onboarding' => [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 72,
            'notify_roles' => ['owner', 'admin'],
            'description' => 'Approve new contractor onboarding',
        ],

        /*
        |----------------------------------------------------------------------
        | Tax Actions
        |----------------------------------------------------------------------
        */
        'tax.1099_generation' => [
            'risk_level' => 'high',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 48,
            'notify_roles' => ['owner'],
            'description' => 'Generate 1099 filing data',
        ],

        'tax.quarterly_payment' => [
            'risk_level' => 'high',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 48,
            'notify_roles' => ['owner'],
            'description' => 'Submit quarterly estimated tax payment',
        ],

        'tax.package_export' => [
            'risk_level' => 'high',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 48,
            'notify_roles' => ['owner'],
            'description' => 'Generate or export a tax package containing sensitive filing data',
        ],

        'tax.filing_preparation' => [
            'risk_level' => 'high',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 48,
            'notify_roles' => ['owner'],
            'description' => 'Prepare filing-ready tax forms, packets, or filing instructions for external use',
        ],

        'tax.strategy_implementation' => [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 72,
            'notify_roles' => ['owner'],
            'description' => 'Implement tax optimization strategy',
        ],

        /*
        |----------------------------------------------------------------------
        | Database Actions
        |----------------------------------------------------------------------
        */
        'database.migration' => [
            'risk_level' => 'critical',
            'requires_approval' => true,
            'auto_approve_conditions' => [], // Never auto-approve
            'expires_hours' => 24,
            'notify_roles' => ['owner', 'admin'],
            'description' => 'Run database migration',
        ],

        'database.backup' => [
            'risk_level' => 'low',
            'requires_approval' => false,
            'auto_approve_conditions' => [],
            'expires_hours' => null,
            'notify_roles' => [],
            'description' => 'Create database backup',
        ],

        /*
        |----------------------------------------------------------------------
        | Communication Actions
        |----------------------------------------------------------------------
        */
        'communication.client_email' => [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'auto_approve_conditions' => [
                'pre_approved_template' => [
                    'status_update',
                    'meeting_reminder',
                    'invoice_receipt',
                ],
            ],
            'expires_hours' => 24,
            'notify_roles' => ['owner', 'admin', 'account_manager'],
            'description' => 'Send email to client',
        ],

        'communication.cold_outreach' => [
            'risk_level' => 'high',
            'requires_approval' => true,
            'auto_approve_conditions' => [], // Never auto-approve cold outreach
            'expires_hours' => 48,
            'notify_roles' => ['owner', 'admin'],
            'description' => 'Send cold outreach message',
        ],

        'communication.internal' => [
            'risk_level' => 'low',
            'requires_approval' => false,
            'auto_approve_conditions' => [],
            'expires_hours' => null,
            'notify_roles' => [],
            'description' => 'Internal team communication',
        ],

        /*
        |----------------------------------------------------------------------
        | Content Actions
        |----------------------------------------------------------------------
        */
        'content.publish' => [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 72,
            'notify_roles' => ['owner', 'admin', 'content_manager'],
            'description' => 'Publish content to website',
        ],

        'content.draft' => [
            'risk_level' => 'low',
            'requires_approval' => false,
            'auto_approve_conditions' => [],
            'expires_hours' => null,
            'notify_roles' => [],
            'description' => 'Create content draft',
        ],

        'content.social_post' => [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 24,
            'notify_roles' => ['owner', 'admin', 'marketing'],
            'description' => 'Post to social media',
        ],

        /*
        |----------------------------------------------------------------------
        | Code Actions
        |----------------------------------------------------------------------
        */
        'code.merge' => [
            'risk_level' => 'high',
            'requires_approval' => true,
            'auto_approve_conditions' => [
                'tests_pass' => true,
                'no_breaking_changes' => true,
            ],
            'expires_hours' => 48,
            'notify_roles' => ['owner', 'admin', 'developer'],
            'description' => 'Merge code to main branch',
        ],

        'code.commit' => [
            'risk_level' => 'low',
            'requires_approval' => false,
            'auto_approve_conditions' => [],
            'expires_hours' => null,
            'notify_roles' => [],
            'description' => 'Commit code changes',
        ],

        /*
        |----------------------------------------------------------------------
        | Agent Actions
        |----------------------------------------------------------------------
        */
        'agent.chain' => [
            'risk_level' => 'medium',
            'requires_approval' => false, // Chaining is pre-configured
            'auto_approve_conditions' => [],
            'expires_hours' => null,
            'notify_roles' => [],
            'description' => 'Chain to another agent',
        ],

        'agent.task_assignment' => [
            'risk_level' => 'medium',
            'requires_approval' => true,
            'auto_approve_conditions' => [],
            'expires_hours' => 24,
            'notify_roles' => ['owner', 'admin'],
            'description' => 'Assign task to agent',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Level Definitions
    |--------------------------------------------------------------------------
    */
    'risk_levels' => [
        'critical' => [
            'color' => 'red',
            'icon' => 'exclamation-triangle',
            'description' => 'Irreversible or high-impact action',
            'requires_2fa' => true,
        ],
        'high' => [
            'color' => 'orange',
            'icon' => 'exclamation-circle',
            'description' => 'Significant impact, external-facing',
            'requires_2fa' => false,
        ],
        'medium' => [
            'color' => 'yellow',
            'icon' => 'info-circle',
            'description' => 'Moderate impact, reversible',
            'requires_2fa' => false,
        ],
        'low' => [
            'color' => 'green',
            'icon' => 'check-circle',
            'description' => 'Minimal impact, internal only',
            'requires_2fa' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'channels' => ['database', 'mail', 'slack'],
        'urgent_channels' => ['database', 'mail', 'slack', 'sms'],
        'slack_channel' => env('APPROVAL_SLACK_CHANNEL', '#approvals'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation Rules
    |--------------------------------------------------------------------------
    */
    'escalation' => [
        // Escalate if not approved within these hours
        'escalate_after_hours' => 12,

        // Who to escalate to
        'escalate_to' => ['owner'],

        // Categories that trigger immediate escalation
        'immediate_escalation' => [
            'deploy.production',
            'financial.payment',
            'database.migration',
            'wise.payment',
            'tax.1099_generation',
        ],
    ],
];
