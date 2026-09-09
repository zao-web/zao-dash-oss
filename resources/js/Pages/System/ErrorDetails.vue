<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface HealingAttempt {
    id: number;
    status: string;
    commit_sha: string | null;
    commit_url: string | null;
    fix_description: string | null;
    failure_reason: string | null;
    created_at: string;
    completed_at: string | null;
}

interface SelfHealingStatus {
    enabled: boolean;
    rate_limit: {
        hourly: { remaining: number };
        daily: { remaining: number };
    };
    circuit_breaker_open: boolean;
}

interface Props {
    notification: {
        id: number;
        type: string;
        title: string;
        message: string;
        severity: string;
        created_at: string;
        created_at_human: string;
    };
    error: {
        message: string;
        exception_class: string | null;
        file: string | null;
        line: number | null;
        stack_trace: string | null;
        batch_id: string | null;
        source_job: string | null;
    };
    healingAttempts: HealingAttempt[];
    selfHealingStatus: SelfHealingStatus;
}

const props = defineProps<Props>();
const page = usePage();

watch(() => page.props.flash, (flash: any) => {
    if (flash?.success) {
        window.dispatchEvent(new CustomEvent('show-toast', {
            detail: {
                title: 'Success',
                message: flash.success,
                type: 'success',
            }
        }));
    }
    if (flash?.error) {
        window.dispatchEvent(new CustomEvent('show-toast', {
            detail: {
                title: 'Error',
                message: flash.error,
                type: 'error',
            }
        }));
    }
    if (flash?.warning) {
        window.dispatchEvent(new CustomEvent('show-toast', {
            detail: {
                title: 'Warning',
                message: flash.warning,
                type: 'warning',
            }
        }));
    }
    if (flash?.info) {
        window.dispatchEvent(new CustomEvent('show-toast', {
            detail: {
                title: 'Info',
                message: flash.info,
                type: 'info',
            }
        }));
    }
}, { deep: true });

const isSubmitting = ref(false);

const isHealingInProgress = computed(() => {
    return props.healingAttempts.some(attempt => 
        ['pending', 'in_progress'].includes(attempt.status)
    );
});

const canTriggerFix = computed(() => {
    if (!props.selfHealingStatus.enabled) return false;
    if (props.selfHealingStatus.circuit_breaker_open) return false;
    if (props.selfHealingStatus.rate_limit.hourly.remaining === 0) return false;
    if (isHealingInProgress.value) return false;
    return true;
});

const triggerFix = () => {
    if (!canTriggerFix.value) return;
    
    isSubmitting.value = true;
    router.post(`/system/errors/${props.notification.id}/fix`, {}, {
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const getSeverityColor = (severity: string) => {
    const colors: Record<string, string> = {
        info: 'bg-blue-500/20 text-blue-400',
        warning: 'bg-amber-500/20 text-amber-400',
        error: 'bg-red-500/20 text-red-400',
        critical: 'bg-red-600/30 text-red-300',
    };
    return colors[severity] || colors.error;
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        pending: 'bg-amber-500/20 text-amber-400',
        in_progress: 'bg-blue-500/20 text-blue-400',
        success: 'bg-emerald-500/20 text-emerald-400',
        failed: 'bg-red-500/20 text-red-400',
        escalated: 'bg-orange-500/20 text-orange-400',
    };
    return colors[status] || 'bg-zinc-500/20 text-zinc-400';
};
</script>

<template>
    <AppLayout>
        <Head title="Error Details" />

        <div class="p-6 max-w-7xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <Link href="/" class="text-zinc-400 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </Link>
                        <h1 class="text-2xl font-semibold text-white">Error Details</h1>
                        <span :class="[getSeverityColor(notification.severity), 'px-2 py-0.5 rounded text-xs font-medium uppercase tracking-wider']">
                            {{ notification.severity }}
                        </span>
                    </div>
                    <div class="flex items-center gap-4 text-sm text-zinc-400">
                        <span>{{ notification.created_at }}</span>
                        <span>•</span>
                        <span>{{ notification.type }}</span>
                    </div>
                </div>

                <div>
                    <button
                        @click="triggerFix"
                        :disabled="!canTriggerFix || isSubmitting"
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2 font-medium"
                    >
                        <svg v-if="isSubmitting" class="animate-spin -ml-1 mr-1 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        {{ isHealingInProgress ? 'Fix in Progress' : 'Trigger Auto-Fix' }}
                    </button>
                    <div v-if="!canTriggerFix && !isSubmitting" class="mt-2 text-xs text-right text-zinc-500">
                        <span v-if="!selfHealingStatus.enabled">Self-healing disabled</span>
                        <span v-else-if="selfHealingStatus.circuit_breaker_open">Circuit breaker open</span>
                        <span v-else-if="selfHealingStatus.rate_limit.hourly.remaining === 0">Rate limit reached</span>
                        <span v-else-if="isHealingInProgress">Fix already running</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Error Message -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-sm">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">Error Message</h2>
                        <div class="p-4 bg-zinc-950/50 rounded-lg border border-zinc-800/50">
                            <p class="text-white text-lg font-medium leading-relaxed font-mono">{{ error.message }}</p>
                            <div class="mt-3 flex flex-wrap gap-4 text-sm text-zinc-500 font-mono">
                                <div v-if="error.exception_class">
                                    <span class="text-zinc-600">Exception:</span>
                                    <span class="ml-2 text-zinc-400">{{ error.exception_class }}</span>
                                </div>
                                <div v-if="error.file">
                                    <span class="text-zinc-600">File:</span>
                                    <span class="ml-2 text-zinc-400">{{ error.file }}:{{ error.line }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Context -->
                    <div v-if="error.source_job || error.batch_id" class="grid grid-cols-2 gap-4">
                        <div v-if="error.source_job" class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                            <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-2">Source Job</h2>
                            <p class="text-white font-mono text-sm">{{ error.source_job }}</p>
                        </div>
                        <div v-if="error.batch_id" class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                            <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-2">Batch ID</h2>
                            <p class="text-white font-mono text-sm">{{ error.batch_id }}</p>
                        </div>
                    </div>

                    <!-- Stack Trace -->
                    <div v-if="error.stack_trace" class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-sm">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">Stack Trace</h2>
                        <pre class="bg-zinc-950 rounded-lg p-4 overflow-x-auto text-xs text-zinc-400 font-mono leading-relaxed h-[500px] scrollbar-thin scrollbar-thumb-zinc-700 scrollbar-track-transparent">{{ error.stack_trace }}</pre>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Self-Healing Status -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-sm">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-4">System Status</h2>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-zinc-400">Self-Healing</span>
                                <span :class="[
                                    selfHealingStatus.enabled ? 'bg-emerald-500/20 text-emerald-400' : 'bg-zinc-500/20 text-zinc-400',
                                    'px-2 py-0.5 rounded text-xs font-medium'
                                ]">
                                    {{ selfHealingStatus.enabled ? 'ENABLED' : 'DISABLED' }}
                                </span>
                            </div>
                            
                            <div class="flex items-center justify-between">
                                <span class="text-zinc-400">Circuit Breaker</span>
                                <span :class="[
                                    selfHealingStatus.circuit_breaker_open ? 'bg-red-500/20 text-red-400' : 'bg-emerald-500/20 text-emerald-400',
                                    'px-2 py-0.5 rounded text-xs font-medium'
                                ]">
                                    {{ selfHealingStatus.circuit_breaker_open ? 'OPEN' : 'CLOSED' }}
                                </span>
                            </div>

                            <div class="pt-4 border-t border-zinc-800">
                                <h3 class="text-xs font-medium text-zinc-500 uppercase mb-3">Rate Limits</h3>
                                <div class="space-y-3">
                                    <div>
                                        <div class="flex justify-between text-xs mb-1">
                                            <span class="text-zinc-400">Hourly Remaining</span>
                                            <span class="text-white">{{ selfHealingStatus.rate_limit.hourly.remaining }}</span>
                                        </div>
                                        <div class="h-1.5 bg-zinc-800 rounded-full overflow-hidden">
                                            <div class="h-full bg-blue-500 rounded-full" :style="{ width: `${Math.min(100, (selfHealingStatus.rate_limit.hourly.remaining / 10) * 100)}%` }"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-xs mb-1">
                                            <span class="text-zinc-400">Daily Remaining</span>
                                            <span class="text-white">{{ selfHealingStatus.rate_limit.daily.remaining }}</span>
                                        </div>
                                        <div class="h-1.5 bg-zinc-800 rounded-full overflow-hidden">
                                            <div class="h-full bg-purple-500 rounded-full" :style="{ width: `${Math.min(100, (selfHealingStatus.rate_limit.daily.remaining / 50) * 100)}%` }"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Healing Attempts -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 shadow-sm">
                        <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-4">Healing Attempts</h2>
                        
                        <div v-if="healingAttempts.length === 0" class="text-center py-8">
                            <p class="text-zinc-500 text-sm">No healing attempts recorded.</p>
                        </div>

                        <div v-else class="space-y-4">
                            <div v-for="attempt in healingAttempts" :key="attempt.id" class="relative pl-4 border-l border-zinc-800">
                                <div class="absolute -left-1.5 top-1.5 w-3 h-3 rounded-full border-2 border-zinc-900" :class="getStatusColor(attempt.status).split(' ')[0].replace('/20', '')"></div>
                                
                                <div class="mb-1 flex items-center justify-between">
                                    <span :class="[getStatusColor(attempt.status), 'px-2 py-0.5 rounded text-[10px] font-medium uppercase']">
                                        {{ attempt.status }}
                                    </span>
                                    <span class="text-zinc-500 text-xs">{{ attempt.created_at }}</span>
                                </div>

                                <div v-if="attempt.fix_description" class="mt-2 text-sm text-zinc-300">
                                    {{ attempt.fix_description }}
                                </div>
                                
                                <div v-if="attempt.failure_reason" class="mt-2 text-xs text-red-400 bg-red-500/5 p-2 rounded border border-red-500/10">
                                    {{ attempt.failure_reason }}
                                </div>

                                <div v-if="attempt.commit_sha" class="mt-2 flex items-center gap-2">
                                    <a v-if="attempt.commit_url" :href="attempt.commit_url" target="_blank" class="text-xs text-blue-400 hover:text-blue-300 flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                                        </svg>
                                        {{ attempt.commit_sha.substring(0, 7) }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
