<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';

interface Toast {
    id: number;
    title: string;
    message: string;
    type: 'info' | 'success' | 'warning' | 'error';
    duration?: number;
    action?: {
        label: string;
        onClick: () => void;
    };
}

const toasts = ref<Toast[]>([]);
let nextId = 0;

const addToast = (toast: Omit<Toast, 'id'>) => {
    const id = nextId++;
    const newToast: Toast = { id, ...toast };
    toasts.value.push(newToast);

    // Auto-remove after duration
    const duration = toast.duration ?? 5000;
    if (duration > 0) {
        setTimeout(() => removeToast(id), duration);
    }

    return id;
};

const removeToast = (id: number) => {
    toasts.value = toasts.value.filter(t => t.id !== id);
};

// Expose methods globally
const handleToastEvent = (event: CustomEvent<Omit<Toast, 'id'>>) => {
    addToast(event.detail);
};

onMounted(() => {
    window.addEventListener('show-toast' as any, handleToastEvent as any);
});

onUnmounted(() => {
    window.removeEventListener('show-toast' as any, handleToastEvent as any);
});

// Also expose via provide/inject for component usage
defineExpose({ addToast, removeToast });

const getIcon = (type: string) => {
    switch (type) {
        case 'success': return '✅';
        case 'warning': return '⚠️';
        case 'error': return '❌';
        default: return 'ℹ️';
    }
};

const getColor = (type: string) => {
    switch (type) {
        case 'success': return 'var(--color-status-green)';
        case 'warning': return 'var(--color-status-yellow)';
        case 'error': return 'var(--color-status-red)';
        default: return 'var(--color-accent)';
    }
};
</script>

<template>
    <Teleport to="body">
        <div class="fixed bottom-4 right-4 z-50 flex flex-col gap-3 max-w-sm">
            <TransitionGroup
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 translate-x-4"
                enter-to-class="opacity-100 translate-x-0"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100 translate-x-0"
                leave-to-class="opacity-0 translate-x-4"
                move-class="transition duration-300"
            >
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="p-4 rounded-xl shadow-lg flex gap-3"
                    style="background: var(--color-bg-elevated); border: 1px solid var(--color-border-subtle)"
                >
                    <div
                        class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-sm"
                        :style="{ background: `${getColor(toast.type)}20` }"
                    >
                        {{ getIcon(toast.type) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-sm" style="color: var(--color-text-primary)">
                            {{ toast.title }}
                        </p>
                        <p class="text-sm mt-0.5" style="color: var(--color-text-secondary)">
                            {{ toast.message }}
                        </p>
                        <button
                            v-if="toast.action"
                            @click="toast.action.onClick(); removeToast(toast.id)"
                            class="text-xs font-medium mt-2"
                            :style="{ color: getColor(toast.type) }"
                        >
                            {{ toast.action.label }}
                        </button>
                    </div>
                    <button
                        @click="removeToast(toast.id)"
                        class="p-1 rounded transition-colors flex-shrink-0"
                        style="color: var(--color-text-quaternary)"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6L6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
