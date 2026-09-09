<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useNotifications } from '@/composables/useNotifications';
import { BellIcon, CloseIcon } from '@/Components/Icons';

const page = usePage();
const userId = computed(() => (page.props.auth as any)?.user?.id);

const {
    notifications,
    unreadCount,
    isConnected,
    fetchNotifications,
    markAsRead,
    markAllAsRead,
    dismiss,
} = useNotifications(userId.value);

const isOpen = ref(false);
const loading = ref(false);

const togglePanel = () => {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        fetchNotifications();
    }
};

const handleAction = async (notification: any) => {
    await markAsRead(notification);
    if (notification.action_url) {
        isOpen.value = false;
        router.visit(notification.action_url);
    }
};

const handleClickOutside = (event: MouseEvent) => {
    const target = event.target as HTMLElement;
    if (!target.closest('.notification-panel') && !target.closest('.notification-bell')) {
        isOpen.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

const iconPaths: Record<string, string> = {
    'check-circle': 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    'calendar': 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    'exclamation-triangle': 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
    'x-circle': 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
    'information-circle': 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    'folder': 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'
};

const getIconPath = (icon: string) => {
    return iconPaths[icon] || null;
};

const getSeverityColor = (severity: string) => {
    switch (severity) {
        case 'success': return 'var(--color-status-green)';
        case 'warning': return 'var(--color-status-yellow)';
        case 'error': return 'var(--color-status-red)';
        default: return 'var(--color-accent)';
    }
};
</script>

<template>
    <div class="relative">
        <button
            @click.stop="togglePanel"
            class="notification-bell relative p-1.5 md:p-2 rounded-lg transition-colors"
            :style="{ background: isOpen ? 'var(--color-bg-tertiary)' : 'transparent' }"
        >
            <BellIcon :size="20" style="color: var(--color-text-secondary)" />
            <!-- Unread badge -->
            <span
                v-if="unreadCount > 0"
                class="absolute -top-1 -right-1 flex items-center justify-center min-w-[18px] h-[18px] text-[10px] font-semibold rounded-full"
                style="background: var(--color-status-red); color: white"
            >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
            <!-- Connection indicator -->
            <span
                v-if="isConnected"
                class="absolute bottom-1 right-1 w-2 h-2 rounded-full"
                style="background: var(--color-status-green)"
                title="Real-time connected"
            ></span>
        </button>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div
                v-if="isOpen"
                class="notification-panel absolute right-0 mt-2 w-[calc(100vw-24px)] md:w-96 max-h-[70vh] overflow-hidden rounded-xl shadow-xl z-50"
                style="background: var(--color-bg-elevated); border: 1px solid var(--color-border-subtle)"
            >
                <div class="flex items-center justify-between p-4 border-b" style="border-color: var(--color-border-subtle)">
                    <div class="flex items-center gap-2">
                        <h3 class="font-semibold" style="color: var(--color-text-primary)">Notifications</h3>
                        <span
                            v-if="isConnected"
                            class="text-[10px] px-1.5 py-0.5 rounded-full"
                            style="background: rgba(34, 197, 94, 0.12); color: var(--color-status-green)"
                        >
                            Live
                        </span>
                    </div>
                    <button
                        v-if="notifications.some(n => !n.is_read)"
                        @click="markAllAsRead"
                        class="text-xs font-medium"
                        style="color: var(--color-accent)"
                        :disabled="loading"
                    >
                        Mark all as read
                    </button>
                </div>

                <div class="overflow-y-auto max-h-[calc(70vh-60px)]">
                    <div v-if="notifications.length === 0" class="p-8 text-center">
                        <div class="text-3xl mb-2">🔔</div>
                        <p class="text-sm" style="color: var(--color-text-tertiary)">No notifications</p>
                    </div>

                    <div v-else class="flex flex-col">
                        <div
                            v-for="(notification, index) in notifications"
                            :key="notification.id"
                            class="group relative p-4 cursor-pointer transition-colors hover:bg-[var(--color-bg-tertiary)]"
                            :class="{
                                'bg-[var(--color-bg-secondary)]': !notification.is_read,
                                'bg-transparent': notification.is_read
                            }"
                            @click="handleAction(notification)"
                        >
                            <!-- Inset Separator -->
                            <div 
                                v-if="index !== notifications.length - 1" 
                                class="absolute bottom-0 left-4 right-4 h-px pointer-events-none"
                                style="background: var(--color-border-subtle)"
                            ></div>

                            <div class="flex gap-3">
                                <div
                                    class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                    :style="{ background: `${getSeverityColor(notification.severity)}20` }"
                                >
                                    <svg
                                        v-if="getIconPath(notification.icon)"
                                        class="w-5 h-5"
                                        :style="{ color: getSeverityColor(notification.severity) }"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <path :d="getIconPath(notification.icon)"></path>
                                    </svg>
                                    <span v-else class="text-sm">{{ notification.icon }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="font-medium text-sm truncate" style="color: var(--color-text-primary)">
                                            {{ notification.title }}
                                        </p>
                                        <button
                                            @click.stop="dismiss(notification)"
                                            class="opacity-0 group-hover:opacity-100 p-1 rounded transition-opacity"
                                            style="color: var(--color-text-quaternary)"
                                        >
                                            <CloseIcon :size="14" />
                                        </button>
                                    </div>
                                    <p class="text-sm mt-0.5 line-clamp-2" style="color: var(--color-text-secondary)">
                                        {{ notification.message }}
                                    </p>
                                    <div class="flex items-center gap-2 mt-2">
                                        <span class="text-xs" style="color: var(--color-text-quaternary)">
                                            {{ notification.created_at }}
                                        </span>
                                        <span
                                            v-if="notification.action_label"
                                            class="text-xs font-medium"
                                            :style="{ color: getSeverityColor(notification.severity) }"
                                        >
                                            {{ notification.action_label }}
                                        </span>
                                    </div>
                                </div>
                                <div
                                    v-if="!notification.is_read"
                                    class="w-2 h-2 rounded-full flex-shrink-0 mt-2"
                                    style="background: var(--color-accent)"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
