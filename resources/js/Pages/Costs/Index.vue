<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface CostByAgent {
    id: number;
    name: string;
    slug: string;
    runs_count: number;
    total_cost: number;
}

interface DailyCost {
    date: string;
    cost: number;
    runs: number;
}

interface CostByModel {
    model: string;
    cost: number;
    runs: number;
}

interface CostBySource {
    source: string;
    cost: number;
    runs: number;
}

interface ExpensiveRun {
    id: number;
    agent_name: string;
    agent_slug: string;
    cost_usd: number;
    tokens_used: number;
    status: string;
    created_at: string;
    invocation_source: string;
}

interface Props {
    period: number;
    overallStats: {
        total_runs: number;
        total_cost: number;
        avg_cost: number;
        max_cost: number;
        total_tokens: number;
        cost_change_percent: number;
    };
    costByAgent: CostByAgent[];
    dailyCosts: DailyCost[];
    costByModel: CostByModel[];
    costBySource: CostBySource[];
    expensiveRuns: ExpensiveRun[];
}

const props = defineProps<Props>();

const selectedPeriod = ref(props.period.toString());

const periods = [
    { value: '7', label: '7 days' },
    { value: '30', label: '30 days' },
    { value: '90', label: '90 days' },
];

const changePeriod = (period: string) => {
    selectedPeriod.value = period;
    router.get('/costs', { period }, { preserveState: true });
};

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(value);
};

const formatNumber = (value: number) => {
    return new Intl.NumberFormat('en-US').format(value);
};

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
};

const maxDailyCost = computed(() => {
    return Math.max(...props.dailyCosts.map(d => d.cost), 1);
});

const modelColors: Record<string, string> = {
    opus: 'var(--color-status-purple)',
    sonnet: 'var(--color-status-blue)',
    haiku: 'var(--color-status-green)',
};

const sourceLabels: Record<string, string> = {
    manual: 'Manual',
    api: 'API',
    webhook: 'Webhook',
    scheduled: 'Scheduled',
    chained: 'Chained',
    command_palette: 'Command Palette',
};
</script>

<template>
    <AppLayout>
        <Head title="Cost Tracking" />

        <div class="page-container">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Cost Tracking</h1>
                    <p class="page-subtitle">Monitor agent spending and resource usage</p>
                </div>
                <div class="period-selector">
                    <button
                        v-for="p in periods"
                        :key="p.value"
                        :class="['period-btn', { active: selectedPeriod === p.value }]"
                        @click="changePeriod(p.value)"
                    >
                        {{ p.label }}
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Cost</div>
                    <div class="stat-value">{{ formatCurrency(overallStats.total_cost) }}</div>
                    <div :class="['stat-change', overallStats.cost_change_percent >= 0 ? 'increase' : 'decrease']">
                        <span v-if="overallStats.cost_change_percent >= 0">+</span>{{ overallStats.cost_change_percent }}% vs previous period
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Runs</div>
                    <div class="stat-value">{{ formatNumber(overallStats.total_runs) }}</div>
                    <div class="stat-sub">{{ formatCurrency(overallStats.avg_cost) }} avg per run</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Tokens</div>
                    <div class="stat-value">{{ formatNumber(overallStats.total_tokens) }}</div>
                    <div class="stat-sub">Max run: {{ formatCurrency(overallStats.max_cost) }}</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="charts-row">
                <!-- Daily Cost Chart -->
                <div class="card chart-card">
                    <div class="card-header">
                        <span class="card-title">Daily Costs</span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <div class="bar-chart">
                                <div
                                    v-for="day in dailyCosts"
                                    :key="day.date"
                                    class="bar-item"
                                    :title="`${formatDate(day.date)}: ${formatCurrency(day.cost)} (${day.runs} runs)`"
                                >
                                    <div
                                        class="bar"
                                        :style="{ height: `${(day.cost / maxDailyCost) * 100}%` }"
                                    ></div>
                                </div>
                            </div>
                            <div class="chart-labels">
                                <span>{{ formatDate(dailyCosts[0]?.date || '') }}</span>
                                <span>{{ formatDate(dailyCosts[dailyCosts.length - 1]?.date || '') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cost by Model -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Cost by Model</span>
                    </div>
                    <div class="card-body">
                        <div v-if="costByModel.length > 0" class="breakdown-list">
                            <div v-for="item in costByModel" :key="item.model" class="breakdown-item">
                                <div class="breakdown-info">
                                    <span
                                        class="model-dot"
                                        :style="{ backgroundColor: modelColors[item.model] || 'var(--color-text-tertiary)' }"
                                    ></span>
                                    <span class="breakdown-label">{{ item.model }}</span>
                                </div>
                                <div class="breakdown-stats">
                                    <span class="breakdown-value">{{ formatCurrency(item.cost) }}</span>
                                    <span class="breakdown-sub">{{ item.runs }} runs</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="empty-state">No data for this period</div>
                    </div>
                </div>
            </div>

            <!-- Agent Costs & Expensive Runs -->
            <div class="tables-row">
                <!-- Cost by Agent -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Cost by Agent</span>
                    </div>
                    <div class="card-body table-container">
                        <table v-if="costByAgent.length > 0" class="data-table">
                            <thead>
                                <tr>
                                    <th>Agent</th>
                                    <th class="text-right">Runs</th>
                                    <th class="text-right">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="agent in costByAgent" :key="agent.id">
                                    <td>
                                        <a :href="`/agents/${agent.slug}`" class="agent-link">
                                            {{ agent.name }}
                                        </a>
                                    </td>
                                    <td class="text-right">{{ agent.runs_count }}</td>
                                    <td class="text-right">{{ formatCurrency(agent.total_cost) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-else class="empty-state">No agent costs for this period</div>
                    </div>
                </div>

                <!-- Expensive Runs -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Most Expensive Runs</span>
                    </div>
                    <div class="card-body table-container">
                        <table v-if="expensiveRuns.length > 0" class="data-table">
                            <thead>
                                <tr>
                                    <th>Agent</th>
                                    <th>Source</th>
                                    <th class="text-right">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="run in expensiveRuns" :key="run.id">
                                    <td>
                                        <a :href="`/agents/${run.agent_slug}`" class="agent-link">
                                            {{ run.agent_name }}
                                        </a>
                                    </td>
                                    <td>
                                        <span class="source-badge">
                                            {{ sourceLabels[run.invocation_source] || run.invocation_source }}
                                        </span>
                                    </td>
                                    <td class="text-right cost-cell">{{ formatCurrency(run.cost_usd) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-else class="empty-state">No expensive runs recorded</div>
                    </div>
                </div>
            </div>

            <!-- Cost by Source -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Cost by Invocation Source</span>
                </div>
                <div class="card-body">
                    <div v-if="costBySource.length > 0" class="source-grid">
                        <div v-for="item in costBySource" :key="item.source" class="source-card">
                            <div class="source-label">{{ sourceLabels[item.source] || item.source }}</div>
                            <div class="source-cost">{{ formatCurrency(item.cost) }}</div>
                            <div class="source-runs">{{ item.runs }} runs</div>
                        </div>
                    </div>
                    <div v-else class="empty-state">No data for this period</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.page-container {
    padding: 2rem;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}

.page-subtitle {
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.period-selector {
    display: flex;
    gap: 0.5rem;
    background: var(--color-bg-tertiary);
    padding: 0.25rem;
    border-radius: 8px;
}

.period-btn {
    padding: 0.5rem 1rem;
    background: transparent;
    border: none;
    border-radius: 6px;
    color: var(--color-text-secondary);
    cursor: pointer;
    font-size: 0.875rem;
    transition: all 0.15s ease;
}

.period-btn:hover {
    color: var(--color-text-primary);
}

.period-btn.active {
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 12px;
    padding: 1.25rem;
}

.stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 600;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

.stat-change {
    font-size: 0.8125rem;
    margin-top: 0.5rem;
}

.stat-change.increase {
    color: var(--color-status-red);
}

.stat-change.decrease {
    color: var(--color-status-green);
}

.stat-sub {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    margin-top: 0.5rem;
}

.charts-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.chart-card {
    min-height: 280px;
}

.chart-container {
    height: 200px;
    display: flex;
    flex-direction: column;
}

.bar-chart {
    flex: 1;
    display: flex;
    align-items: flex-end;
    gap: 2px;
    padding-bottom: 0.5rem;
}

.bar-item {
    flex: 1;
    height: 100%;
    display: flex;
    align-items: flex-end;
}

.bar {
    width: 100%;
    min-height: 2px;
    background: var(--color-accent-primary);
    border-radius: 2px 2px 0 0;
    transition: height 0.3s ease;
}

.bar-item:hover .bar {
    background: var(--color-accent-secondary);
}

.chart-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.breakdown-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.breakdown-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.model-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.breakdown-label {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    text-transform: capitalize;
}

.breakdown-stats {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

.breakdown-value {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.breakdown-sub {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.tables-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.table-container {
    max-height: 300px;
    overflow-y: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border-subtle);
}

.data-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    font-weight: 500;
}

.data-table td {
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.text-right {
    text-align: right;
}

.agent-link {
    color: var(--color-accent-primary);
    text-decoration: none;
}

.agent-link:hover {
    text-decoration: underline;
}

.source-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    color: var(--color-text-secondary);
}

.cost-cell {
    font-variant-numeric: tabular-nums;
    font-weight: 500;
}

.source-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 1rem;
}

.source-card {
    padding: 1rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    text-align: center;
}

.source-label {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    margin-bottom: 0.5rem;
}

.source-cost {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.source-runs {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--color-text-tertiary);
}

@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .charts-row,
    .tables-row {
        grid-template-columns: 1fr;
    }
}
</style>
