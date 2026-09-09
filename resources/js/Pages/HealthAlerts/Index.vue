<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Client {
    id: number;
    name: string;
    slug: string;
}

interface HealthAlert {
    id: number;
    client: Client | null;
    alert_type: string;
    severity: string;
    severity_color: string;
    health_score: number;
    previous_score: number;
    description: string;
    status: string;
    escalation_level: number;
    escalation_level_name: string;
    acknowledged_by: string | null;
    acknowledged_at: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
    next_escalation_at: string | null;
    created_at: string;
}

interface Summary {
    open_alerts: number;
    critical_alerts: number;
    pending_escalation: number;
    escalated_today: number;
    resolved_today: number;
    by_severity: Record<string, number>;
    at_risk_clients: number;
}

interface PaginatedAlerts {
    data: HealthAlert[];
    current_page: number;
    last_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    alerts: PaginatedAlerts;
    summary: Summary;
    filters: {
        status: string | null;
        severity: string | null;
        client_id: number | null;
    };
    clients: Client[];
}>();

const localFilters = ref({
    status: props.filters.status || '',
    severity: props.filters.severity || '',
    client_id: props.filters.client_id || '',
});

const applyFilters = () => {
    router.get(route('health-alerts.index'), {
        status: localFilters.value.status || undefined,
        severity: localFilters.value.severity || undefined,
        client_id: localFilters.value.client_id || undefined,
    }, { preserveState: true });
};

const acknowledgeAlert = (alertId: number) => {
    router.post(route('health-alerts.acknowledge', alertId), {}, {
        preserveScroll: true,
    });
};

const showResolveModal = ref(false);
const resolveAlertId = ref<number | null>(null);
const resolveNote = ref('');

const openResolveModal = (alertId: number) => {
    resolveAlertId.value = alertId;
    resolveNote.value = '';
    showResolveModal.value = true;
};

const submitResolve = () => {
    if (!resolveAlertId.value) return;
    router.post(route('health-alerts.resolve', resolveAlertId.value), {
        note: resolveNote.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showResolveModal.value = false;
            resolveAlertId.value = null;
        },
    });
};

const getSeverityBadge = (severity: string) => {
    const badges: Record<string, string> = {
        critical: 'bg-red-500/20 text-red-400',
        high: 'bg-orange-500/20 text-orange-400',
        medium: 'bg-amber-500/20 text-amber-400',
        low: 'bg-emerald-500/20 text-emerald-400',
    };
    return badges[severity] || 'bg-zinc-500/20 text-zinc-400';
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        open: 'bg-red-500/20 text-red-400',
        acknowledged: 'bg-amber-500/20 text-amber-400',
        escalated: 'bg-orange-500/20 text-orange-400',
        resolved: 'bg-emerald-500/20 text-emerald-400',
    };
    return badges[status] || 'bg-zinc-500/20 text-zinc-400';
};
</script>

<template>
    <AppLayout>
        <Head title="Health Alerts" />

        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-white">Health Alerts</h1>
                    <p class="text-zinc-400 text-sm mt-1">Monitor and respond to client health issues</p>
                </div>
                <Link
                    :href="route('health-alerts.settings')"
                    class="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-white rounded-lg transition-colors"
                >
                    Escalation Settings
                </Link>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wider mb-1">Open Alerts</div>
                    <div class="text-2xl font-semibold" :class="summary.open_alerts > 0 ? 'text-red-400' : 'text-white'">
                        {{ summary.open_alerts }}
                    </div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wider mb-1">Critical</div>
                    <div class="text-2xl font-semibold" :class="summary.critical_alerts > 0 ? 'text-red-400' : 'text-white'">
                        {{ summary.critical_alerts }}
                    </div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wider mb-1">Pending Escalation</div>
                    <div class="text-2xl font-semibold" :class="summary.pending_escalation > 0 ? 'text-amber-400' : 'text-white'">
                        {{ summary.pending_escalation }}
                    </div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wider mb-1">Escalated Today</div>
                    <div class="text-2xl font-semibold text-orange-400">{{ summary.escalated_today }}</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wider mb-1">Resolved Today</div>
                    <div class="text-2xl font-semibold text-emerald-400">{{ summary.resolved_today }}</div>
                </div>
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wider mb-1">At-Risk Clients</div>
                    <div class="text-2xl font-semibold" :class="summary.at_risk_clients > 0 ? 'text-red-400' : 'text-white'">
                        {{ summary.at_risk_clients }}
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex gap-4 flex-wrap">
                <select
                    v-model="localFilters.status"
                    @change="applyFilters"
                    class="bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2 text-white"
                >
                    <option value="">All Status</option>
                    <option value="open">Open</option>
                    <option value="acknowledged">Acknowledged</option>
                    <option value="escalated">Escalated</option>
                    <option value="resolved">Resolved</option>
                </select>
                <select
                    v-model="localFilters.severity"
                    @change="applyFilters"
                    class="bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2 text-white"
                >
                    <option value="">All Severity</option>
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
                <select
                    v-model="localFilters.client_id"
                    @change="applyFilters"
                    class="bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2 text-white"
                >
                    <option value="">All Clients</option>
                    <option v-for="client in clients" :key="client.id" :value="client.id">
                        {{ client.name }}
                    </option>
                </select>
            </div>

            <!-- Alerts List -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
                <div class="border-b border-zinc-800 px-6 py-4">
                    <h2 class="text-lg font-medium text-white">Alerts</h2>
                </div>

                <div class="divide-y divide-zinc-800">
                    <div
                        v-for="alert in alerts.data"
                        :key="alert.id"
                        class="px-6 py-4 hover:bg-zinc-800/50 transition-colors"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-3 mb-2">
                                    <Link
                                        v-if="alert.client"
                                        :href="`/clients/${alert.client.slug}`"
                                        class="text-white font-medium hover:text-blue-400 transition-colors"
                                    >
                                        {{ alert.client.name }}
                                    </Link>
                                    <span :class="[getSeverityBadge(alert.severity), 'px-2 py-0.5 rounded text-xs font-medium']">
                                        {{ alert.severity }}
                                    </span>
                                    <span :class="[getStatusBadge(alert.status), 'px-2 py-0.5 rounded text-xs font-medium']">
                                        {{ alert.status }}
                                    </span>
                                    <span v-if="alert.escalation_level > 0" class="text-xs text-orange-400">
                                        Escalated to {{ alert.escalation_level_name }}
                                    </span>
                                </div>

                                <p class="text-zinc-400 text-sm mb-2">{{ alert.description }}</p>

                                <div class="flex items-center gap-4 text-xs text-zinc-500">
                                    <span>Health: {{ alert.previous_score }} → {{ alert.health_score }}</span>
                                    <span>{{ alert.created_at }}</span>
                                    <span v-if="alert.next_escalation_at && alert.status !== 'resolved'" class="text-amber-400">
                                        Escalates {{ alert.next_escalation_at }}
                                    </span>
                                    <span v-if="alert.acknowledged_by" class="text-emerald-400">
                                        Acknowledged by {{ alert.acknowledged_by }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <Link
                                    :href="route('health-alerts.show', alert.id)"
                                    class="px-3 py-1.5 text-sm text-zinc-400 hover:text-white transition-colors"
                                >
                                    Details
                                </Link>
                                <template v-if="alert.status !== 'resolved'">
                                    <button
                                        v-if="alert.status === 'open'"
                                        @click="acknowledgeAlert(alert.id)"
                                        class="px-3 py-1.5 text-sm bg-amber-600 hover:bg-amber-500 text-white rounded-lg transition-colors"
                                    >
                                        Acknowledge
                                    </button>
                                    <button
                                        @click="openResolveModal(alert.id)"
                                        class="px-3 py-1.5 text-sm bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg transition-colors"
                                    >
                                        Resolve
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div v-if="alerts.data.length === 0" class="px-6 py-12 text-center">
                        <div class="text-4xl mb-3">✅</div>
                        <p class="text-zinc-400">No alerts match your filters</p>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="alerts.last_page > 1" class="border-t border-zinc-800 px-6 py-4 flex justify-between items-center">
                    <span class="text-sm text-zinc-500">
                        Showing {{ (alerts.current_page - 1) * 20 + 1 }}–{{ Math.min(alerts.current_page * 20, alerts.total) }} of {{ alerts.total }}
                    </span>
                    <div class="flex gap-1">
                        <Link
                            v-for="link in alerts.links"
                            :key="link.label"
                            :href="link.url || ''"
                            class="px-3 py-1 text-sm rounded"
                            :class="link.active ? 'bg-blue-600 text-white' : link.url ? 'bg-zinc-800 text-zinc-400 hover:bg-zinc-700' : 'bg-zinc-800/50 text-zinc-600 cursor-not-allowed'"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Resolve Modal -->
        <div v-if="showResolveModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl w-full max-w-md">
                <div class="p-4 border-b border-zinc-800">
                    <h2 class="text-lg font-medium text-white">Resolve Alert</h2>
                </div>
                <div class="p-4 space-y-4">
                    <div>
                        <label class="block text-sm text-zinc-400 mb-2">Resolution Note (optional)</label>
                        <textarea
                            v-model="resolveNote"
                            rows="3"
                            placeholder="Describe how this was resolved..."
                            class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white placeholder-zinc-500"
                        ></textarea>
                    </div>
                </div>
                <div class="p-4 border-t border-zinc-800 flex justify-end gap-3">
                    <button
                        @click="showResolveModal = false"
                        class="px-4 py-2 text-zinc-400 hover:text-white"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitResolve"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg"
                    >
                        Resolve Alert
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
