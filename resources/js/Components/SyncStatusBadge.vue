<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';

interface SyncStatus {
    connected: boolean;
    status: 'pending' | 'syncing' | 'completed' | 'failed' | null;
    progress: number;
    started_at?: string;
    completed_at?: string;
    last_synced?: string;
    error?: string;
}

const props = withDefaults(defineProps<{
    service: string;
    pollInterval?: number;
    showWhenIdle?: boolean;
}>(), {
    pollInterval: 2000,
    showWhenIdle: false,
});

const emit = defineEmits<{
    (e: 'complete'): void;
    (e: 'error', error: string): void;
}>();

const status = ref<SyncStatus | null>(null);
const polling = ref(false);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const isSyncing = computed(() => status.value?.status === 'syncing');
const isComplete = computed(() => status.value?.status === 'completed');
const isFailed = computed(() => status.value?.status === 'failed');
const progress = computed(() => status.value?.progress ?? 0);

const fetchStatus = async () => {
    try {
        const response = await fetch(`/api/integrations/sync-status/${props.service}`);
        if (response.ok) {
            const data = await response.json();
            const wasSyncing = isSyncing.value;
            status.value = data;

            // Emit events on state changes
            if (wasSyncing && isComplete.value) {
                emit('complete');
            }
            if (isFailed.value && data.error) {
                emit('error', data.error);
            }
        }
    } catch (error) {
        console.error('Failed to fetch sync status:', error);
    }
};

const startPolling = () => {
    if (polling.value) return;
    polling.value = true;
    fetchStatus();
    pollTimer = setInterval(fetchStatus, props.pollInterval);
};

const stopPolling = () => {
    polling.value = false;
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

// Auto-start/stop polling based on sync status
watch(isSyncing, (syncing) => {
    if (syncing && !polling.value) {
        startPolling();
    } else if (!syncing && polling.value) {
        // Keep polling for a bit after completion to ensure we catch the final state
        setTimeout(stopPolling, 3000);
    }
});

onMounted(() => {
    fetchStatus();
    // Start polling to catch background syncs
    startPolling();
});

onUnmounted(() => {
    stopPolling();
});

// Expose method to force refresh
defineExpose({ refresh: fetchStatus });
</script>

<template>
    <div v-if="status" class="inline-flex items-center gap-2">
        <!-- Syncing badge with progress -->
        <span
            v-if="isSyncing"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full bg-accent/10 text-accent"
        >
            <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-opacity="0.25" />
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            Syncing {{ progress }}%
        </span>

        <!-- Completed badge (briefly shown) -->
        <span
            v-else-if="isComplete && !showWhenIdle"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full bg-status-running/10 text-status-running"
        >
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            Synced
        </span>

        <!-- Failed badge -->
        <span
            v-else-if="isFailed"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full bg-status-failed/10 text-status-failed cursor-help"
            :title="status.error || 'Sync failed'"
        >
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Failed
        </span>

        <!-- Last synced (when idle and showWhenIdle is true) -->
        <span
            v-else-if="showWhenIdle && status.last_synced"
            class="text-xs"
            style="color: var(--color-text-quaternary)"
        >
            Last synced {{ status.last_synced }}
        </span>
    </div>
</template>
