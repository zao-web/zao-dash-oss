<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\Query;

/**
 * Configures Nightwatch query filtering to conserve quota.
 *
 * Strategy: Filter high-frequency, low-diagnostic-value queries while
 * preserving visibility into business-critical operations.
 *
 * @see https://nightwatch.laravel.com/docs/queries
 */
class NightwatchServiceProvider extends ServiceProvider
{
    /**
     * Tables where simple ID lookups are safe to filter.
     * These are high-frequency, cache-friendly operations that rarely indicate issues.
     */
    protected array $filterIdLookups = [
        'harvest_projects',    // 150K calls - simple FK lookups
        'slack_channels',      // 43K calls - message processing
        'slack_workspaces',    // 6.4K calls - workspace resolution
        'github_installations', // 1.1K calls - repo sync
    ];

    /**
     * Internal processing tables - filter all queries.
     * These support background sync jobs, not user-facing features.
     */
    protected array $filterAllQueries = [
        'slack_messages',  // 17.8K distinct + 6.4K counts - internal analytics
        'jobs',            // Queue internals
        'job_batches',     // Batch job tracking
        'failed_jobs',     // Failed job storage
        'cache',           // Cache operations
        'cache_locks',     // Cache lock management
        'sessions',        // Session storage
    ];

    /**
     * Sync timestamp updates - high frequency, low value.
     */
    protected array $filterSyncUpdates = [
        'wordpress_posts',   // 1.3K synced_at updates
        'github_repos',      // 1.8K+ synced_at updates
        'slack_channels',    // 6.4K last_message_at updates
    ];

    public function boot(): void
    {
        if (! class_exists(Nightwatch::class)) {
            return;
        }

        // Filter simple ID lookups on high-frequency tables
        Nightwatch::rejectQueries(function (Query $query) {
            foreach ($this->filterIdLookups as $table) {
                // Match: SELECT ... FROM "table" WHERE ... "id" = ? ... LIMIT 1
                if (str_contains($query->sql, "from \"{$table}\"")
                    && str_contains($query->sql, 'limit 1')
                    && (str_contains($query->sql, '"id" = ?')
                        || str_contains($query->sql, '"slack_id" = ?')
                        || str_contains($query->sql, '"harvest_id" = ?')
                        || str_contains($query->sql, '"repo_id" = ?'))) {
                    return true;
                }
            }

            return false;
        });

        // Filter all queries on internal processing tables
        Nightwatch::rejectQueries(function (Query $query) {
            foreach ($this->filterAllQueries as $table) {
                if (str_contains($query->sql, "\"{$table}\"")) {
                    return true;
                }
            }

            return false;
        });

        // Filter sync timestamp updates (high frequency background noise)
        Nightwatch::rejectQueries(function (Query $query) {
            if (! str_starts_with($query->sql, 'update')) {
                return false;
            }

            foreach ($this->filterSyncUpdates as $table) {
                if (str_contains($query->sql, "update \"{$table}\"")
                    && (str_contains($query->sql, 'synced_at')
                        || str_contains($query->sql, 'last_message_at')
                        || str_contains($query->sql, 'issues_synced_at')
                        || str_contains($query->sql, 'prs_synced_at'))) {
                    return true;
                }
            }

            return false;
        });

        // Filter harvest_client_id lookups (client resolution during sync)
        Nightwatch::rejectQueries(function (Query $query) {
            return str_contains($query->sql, '"harvest_client_id" = ?')
                && str_contains($query->sql, 'limit 1');
        });

        // Filter wordpress post sync lookups
        Nightwatch::rejectQueries(function (Query $query) {
            return str_contains($query->sql, '"wordpress_site_id" = ?')
                && str_contains($query->sql, '"wp_post_id" = ?');
        });

        // Filter client_contacts email lookups (Slack user resolution)
        Nightwatch::rejectQueries(function (Query $query) {
            return str_contains($query->sql, 'from "client_contacts"')
                && str_contains($query->sql, '"email" = ?')
                && str_contains($query->sql, 'limit 1');
        });
    }
}
