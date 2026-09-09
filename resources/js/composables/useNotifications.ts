import { ref, onMounted, onUnmounted } from 'vue';
import echo from '@/echo';
import { useToast } from './useToast';

// Get CSRF token from meta tag
const getCsrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

// Fetch helper with CSRF token
const postFetch = (url: string) => fetch(url, {
    method: 'POST',
    headers: {
        'X-CSRF-TOKEN': getCsrfToken(),
        'Accept': 'application/json',
    },
});

interface Notification {
    id: number;
    type: string;
    title: string;
    message: string;
    icon: string;
    severity: 'info' | 'success' | 'warning' | 'error';
    action_url?: string;
    action_label?: string;
    is_read: boolean;
    created_at: string;
}

/**
 * Real-time notifications composable.
 * Listens for new notifications via Reverb and shows toasts.
 */
export function useNotifications(userId?: number) {
    const notifications = ref<Notification[]>([]);
    const unreadCount = ref(0);
    const isConnected = ref(false);
    const toast = useToast();

    let publicChannel: ReturnType<typeof echo.channel> | null = null;
    let privateChannel: ReturnType<typeof echo.private> | null = null;

    const handleNewNotification = (notification: Notification) => {
        // Add to beginning of list
        notifications.value.unshift(notification);
        unreadCount.value++;

        // Show toast for the notification
        toast.show({
            title: notification.title,
            message: notification.message,
            type: notification.severity,
            duration: notification.severity === 'error' ? 8000 : 5000,
            action: notification.action_url ? {
                label: notification.action_label || 'View',
                onClick: () => {
                    window.location.href = notification.action_url!;
                },
            } : undefined,
        });
    };

    const connect = () => {
        if (!echo) {
            console.warn('Echo not initialized');
            return;
        }

        // Listen to public notifications channel
        publicChannel = echo.channel('notifications')
            .listen('.notification.created', handleNewNotification);

        // If user ID provided, also listen to private channel
        if (userId) {
            privateChannel = echo.private(`notifications.${userId}`)
                .listen('.notification.created', handleNewNotification);
        }

        isConnected.value = true;
    };

    const disconnect = () => {
        if (publicChannel) {
            echo.leave('notifications');
            publicChannel = null;
        }

        if (privateChannel && userId) {
            echo.leave(`notifications.${userId}`);
            privateChannel = null;
        }

        isConnected.value = false;
    };

    const fetchNotifications = async () => {
        try {
            const response = await fetch('/api/notifications');
            const data = await response.json();
            notifications.value = data.notifications;
            unreadCount.value = data.unread_count;
        } catch (e) {
            console.error('Failed to fetch notifications:', e);
        }
    };

    const fetchUnreadCount = async () => {
        try {
            const response = await fetch('/api/notifications/unread-count');
            const data = await response.json();
            unreadCount.value = data.count;
        } catch (e) {
            console.error('Failed to fetch unread count:', e);
        }
    };

    const markAsRead = async (notification: Notification) => {
        if (notification.is_read) return;
        try {
            await postFetch(`/api/notifications/${notification.id}/read`);
            notification.is_read = true;
            unreadCount.value = Math.max(0, unreadCount.value - 1);
        } catch (e) {
            console.error('Failed to mark as read:', e);
        }
    };

    const markAllAsRead = async () => {
        try {
            await postFetch('/api/notifications/mark-all-read');
            notifications.value.forEach(n => n.is_read = true);
            unreadCount.value = 0;
        } catch (e) {
            console.error('Failed to mark all as read:', e);
        }
    };

    const dismiss = async (notification: Notification) => {
        try {
            await postFetch(`/api/notifications/${notification.id}/dismiss`);
            notifications.value = notifications.value.filter(n => n.id !== notification.id);
            if (!notification.is_read) {
                unreadCount.value = Math.max(0, unreadCount.value - 1);
            }
        } catch (e) {
            console.error('Failed to dismiss notification:', e);
        }
    };

    onMounted(() => {
        connect();
        fetchUnreadCount();
    });

    onUnmounted(() => {
        disconnect();
    });

    return {
        notifications,
        unreadCount,
        isConnected,
        fetchNotifications,
        fetchUnreadCount,
        markAsRead,
        markAllAsRead,
        dismiss,
        connect,
        disconnect,
    };
}
