import { ref, onMounted, onUnmounted } from 'vue';
import echo from '@/echo';
import { useToast } from './useToast';

interface InvitationAcceptedEvent {
    invitation_id: number;
    client_id: number;
    client_name: string;
    contact_name: string;
    contact_email: string;
    accepted_at: string;
    client_url: string;
}

/**
 * Real-time client events composable.
 * Listens for client invitation acceptance and other client-related events.
 */
export function useClientEvents(userId: number) {
    const isConnected = ref(false);
    const toast = useToast();

    let channel: ReturnType<typeof echo.private> | null = null;

    const handleInvitationAccepted = (event: InvitationAcceptedEvent) => {
        toast.show({
            title: 'Invitation Accepted',
            message: `${event.contact_name} has joined the ${event.client_name} portal`,
            type: 'success',
            duration: 8000,
            action: {
                label: 'View Client',
                onClick: () => {
                    window.location.href = event.client_url;
                },
            },
        });
    };

    const connect = () => {
        if (!echo || !userId) {
            console.warn('Echo not initialized or no user ID');
            return;
        }

        channel = echo.private(`user.${userId}`)
            .listen('.client.invitation.accepted', handleInvitationAccepted);

        isConnected.value = true;
    };

    const disconnect = () => {
        if (channel && userId) {
            echo.leave(`user.${userId}`);
            channel = null;
        }
        isConnected.value = false;
    };

    onMounted(() => {
        connect();
    });

    onUnmounted(() => {
        disconnect();
    });

    return {
        isConnected,
        connect,
        disconnect,
    };
}
