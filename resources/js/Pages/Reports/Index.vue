<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

const reports = [
    {
        name: 'Time Report',
        description: 'Hours tracked by period, client, project, or task. View utilization and billable breakdown.',
        href: '/reports/time',
        icon: 'clock',
    },
    {
        name: 'Profitability',
        description: 'Revenue, costs, and profit margins by client. Track effective hourly rates.',
        href: '/reports/profitability',
        icon: 'trending-up',
    },
    {
        name: 'Payments Received',
        description: 'Payment history with breakdowns by month, method, and client.',
        href: '/reports/payments',
        icon: 'currency',
    },
    {
        name: 'AR Aging',
        description: 'Outstanding invoices organized by age buckets (current, 30, 60, 90+ days).',
        href: '/reports/ar-aging',
        icon: 'calendar',
    },
    {
        name: 'Unbilled Time',
        description: 'Billable time entries not yet invoiced, grouped by client and project.',
        href: '/reports/unbilled-time',
        icon: 'exclamation',
    },
];
</script>

<template>
    <AppLayout title="Reports">
        <div class="page-header">
            <h1 class="page-title">Reports</h1>
            <p class="page-subtitle">Financial and operational reports</p>
        </div>

        <div class="reports-grid">
            <Link
                v-for="report in reports"
                :key="report.name"
                :href="report.href"
                class="report-card"
            >
                <div class="report-icon">
                    <svg v-if="report.icon === 'clock'" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <svg v-else-if="report.icon === 'trending-up'" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
                    </svg>
                    <svg v-else-if="report.icon === 'currency'" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <svg v-else-if="report.icon === 'calendar'" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                    </svg>
                    <svg v-else-if="report.icon === 'exclamation'" class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                </div>
                <div class="report-content">
                    <h3 class="report-name">{{ report.name }}</h3>
                    <p class="report-description">{{ report.description }}</p>
                </div>
                <div class="report-arrow">
                    <svg class="arrow-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </div>
            </Link>
        </div>
    </AppLayout>
</template>

<style scoped>
.page-header {
    margin-bottom: 2rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.page-subtitle {
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.reports-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}

.report-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.25rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.15s ease;
}

.report-card:hover {
    border-color: var(--color-border-default);
    background: var(--color-bg-tertiary);
}

.report-icon {
    flex-shrink: 0;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-tertiary);
    border-radius: 10px;
}

.report-card:hover .report-icon {
    background: var(--color-accent);
}

.report-card:hover .report-icon .icon {
    color: white;
}

.icon {
    width: 24px;
    height: 24px;
    color: var(--color-text-secondary);
    transition: color 0.15s ease;
}

.report-content {
    flex: 1;
    min-width: 0;
}

.report-name {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.25rem;
}

.report-description {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    line-height: 1.4;
}

.report-arrow {
    flex-shrink: 0;
    opacity: 0;
    transform: translateX(-4px);
    transition: all 0.15s ease;
}

.report-card:hover .report-arrow {
    opacity: 1;
    transform: translateX(0);
}

.arrow-icon {
    width: 20px;
    height: 20px;
    color: var(--color-accent);
}
</style>
