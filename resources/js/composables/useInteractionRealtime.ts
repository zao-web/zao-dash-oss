import { ref, computed, onMounted, onUnmounted, reactive } from 'vue';
import echo from '@/echo';
import { useToast } from './useToast';

// CSRF token helper
const getCsrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

/**
 * Interaction states for the state machine
 */
export type InteractionState = 'idle' | 'pending' | 'submitting' | 'submitted' | 'expired' | 'error';

export interface InteractionOption {
    label: string;
    description?: string;
}

export interface InteractionContext {
    header?: string;
    multi_select?: boolean;
    all_questions?: any[];
}

export interface Interaction {
    id: number;
    run_id: number;
    agent_id?: number;
    agent_name?: string;
    agent_slug?: string;
    question_type: 'text' | 'select' | 'confirm';
    question: string;
    options?: InteractionOption[];
    context?: InteractionContext;
    response?: string;
    responded_at?: string;
    responded_via?: string;
    responded_by?: { id: number; name: string };
    expires_at: string;
    is_expired: boolean;
    is_responded: boolean;
    is_pending: boolean;
    remaining_seconds: number;
    created_at: string;
}

interface InteractionCreatedEvent {
    interaction_id: number;
    run_id: number;
    agent_id?: number;
    agent_name?: string;
    agent_slug?: string;
    question_type: string;
    question: string;
    options?: InteractionOption[];
    context?: InteractionContext;
    expires_at: string;
    created_at: string;
}

interface InteractionRespondedEvent {
    interaction_id: number;
    run_id: number;
    responded_at: string;
    responded_via: string;
    responded_by_id?: number;
}

interface InteractionExpiredEvent {
    interaction_id: number;
    run_id: number;
    expired_at: string;
    expires_at: string;
}

/**
 * Real-time agent interactions composable.
 *
 * Listens for interaction requests from agents and manages the response flow
 * with a state machine to prevent race conditions across tabs.
 */
export function useInteractionRealtime(userId: number) {
    const toast = useToast();

    // State machine
    const state = ref<InteractionState>('idle');
    const currentInteraction = ref<Interaction | null>(null);
    const pendingInteractions = ref<Interaction[]>([]);
    const isConnected = ref(false);
    const error = ref<string | null>(null);

    // For idempotency
    const idempotencyKey = ref<string | null>(null);

    // Countdown timer
    const remainingSeconds = ref<number>(0);
    let countdownInterval: ReturnType<typeof setInterval> | null = null;

    // BroadcastChannel for multi-tab coordination
    let broadcastChannel: BroadcastChannel | null = null;

    // Echo channel
    let privateChannel: ReturnType<typeof echo.private> | null = null;
    let publicChannel: ReturnType<typeof echo.channel> | null = null;

    // Computed
    const hasPendingInteraction = computed(() => state.value === 'pending' && currentInteraction.value !== null);
    const isSubmitting = computed(() => state.value === 'submitting');
    const canRespond = computed(() => state.value === 'pending' && remainingSeconds.value > 0);

    /**
     * Generate a unique idempotency key for the response
     */
    const generateIdempotencyKey = () => {
        idempotencyKey.value = `${Date.now()}-${Math.random().toString(36).substring(2, 15)}`;
        return idempotencyKey.value;
    };

    /**
     * Handle incoming interaction request
     */
    const handleInteractionCreated = (event: InteractionCreatedEvent) => {
        console.log('[Interaction] New interaction request:', event);

        const interaction: Interaction = {
            id: event.interaction_id,
            run_id: event.run_id,
            agent_id: event.agent_id,
            agent_name: event.agent_name,
            agent_slug: event.agent_slug,
            question_type: event.question_type as 'text' | 'select' | 'confirm',
            question: event.question,
            options: event.options,
            context: event.context,
            expires_at: event.expires_at,
            is_expired: false,
            is_responded: false,
            is_pending: true,
            remaining_seconds: Math.max(0, Math.floor((new Date(event.expires_at).getTime() - Date.now()) / 1000)),
            created_at: event.created_at,
        };

        // Add to pending list
        pendingInteractions.value.push(interaction);

        // If no current interaction, show this one
        if (!currentInteraction.value) {
            showInteraction(interaction);
        }

        // Show toast notification
        toast.show({
            title: 'Agent needs input',
            message: event.agent_name ? `${event.agent_name} is waiting for your response` : 'An agent is waiting for your response',
            type: 'info',
            duration: 8000,
        });

        // Notify other tabs
        broadcastChannel?.postMessage({ type: 'interaction_created', interaction });
    };

    /**
     * Handle response received (from another tab/user)
     */
    const handleInteractionResponded = (event: InteractionRespondedEvent) => {
        console.log('[Interaction] Response received:', event);

        // Remove from pending list
        pendingInteractions.value = pendingInteractions.value.filter(i => i.id !== event.interaction_id);

        // If this was our current interaction, clear it
        if (currentInteraction.value?.id === event.interaction_id) {
            state.value = 'submitted';
            stopCountdown();

            // Notify other tabs
            broadcastChannel?.postMessage({ type: 'interaction_responded', interactionId: event.interaction_id });

            // Show next pending interaction if any
            setTimeout(() => {
                if (pendingInteractions.value.length > 0) {
                    showInteraction(pendingInteractions.value[0]);
                } else {
                    currentInteraction.value = null;
                    state.value = 'idle';
                }
            }, 1500);
        }
    };

    /**
     * Handle server-side expiration event
     */
    const handleInteractionExpiredEvent = (event: InteractionExpiredEvent) => {
        console.log('[Interaction] Server expired:', event);

        // Remove from pending list
        pendingInteractions.value = pendingInteractions.value.filter(i => i.id !== event.interaction_id);

        // If this was our current interaction, mark as expired
        if (currentInteraction.value?.id === event.interaction_id) {
            state.value = 'expired';
            stopCountdown();

            // Notify other tabs
            broadcastChannel?.postMessage({ type: 'interaction_expired', interactionId: event.interaction_id });

            toast.show({
                title: 'Interaction expired',
                message: 'The interaction request has timed out',
                type: 'warning',
                duration: 5000,
            });

            // Show next pending interaction if any
            setTimeout(() => {
                if (pendingInteractions.value.length > 0) {
                    showInteraction(pendingInteractions.value[0]);
                } else {
                    currentInteraction.value = null;
                    state.value = 'idle';
                }
            }, 3000);
        }
    };

    /**
     * Show an interaction (modal)
     */
    const showInteraction = (interaction: Interaction) => {
        currentInteraction.value = interaction;
        state.value = 'pending';
        error.value = null;

        // Start countdown
        remainingSeconds.value = interaction.remaining_seconds;
        startCountdown();

        // Generate new idempotency key
        generateIdempotencyKey();
    };

    /**
     * Start countdown timer
     */
    const startCountdown = () => {
        stopCountdown();
        countdownInterval = setInterval(() => {
            if (remainingSeconds.value > 0) {
                remainingSeconds.value--;
            } else {
                handleExpired();
            }
        }, 1000);
    };

    /**
     * Stop countdown timer
     */
    const stopCountdown = () => {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    };

    /**
     * Handle interaction expiration
     */
    const handleExpired = () => {
        state.value = 'expired';
        stopCountdown();

        if (currentInteraction.value) {
            // Remove from pending
            pendingInteractions.value = pendingInteractions.value.filter(
                i => i.id !== currentInteraction.value?.id
            );
        }

        toast.show({
            title: 'Interaction expired',
            message: 'The interaction request has timed out',
            type: 'warning',
            duration: 5000,
        });
    };

    /**
     * Submit a response
     */
    const submitResponse = async (response: string): Promise<boolean> => {
        if (!currentInteraction.value || !canRespond.value) {
            return false;
        }

        state.value = 'submitting';
        error.value = null;

        try {
            const res = await fetch(`/api/interactions/${currentInteraction.value.id}/respond`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    response,
                    idempotency_key: idempotencyKey.value,
                }),
            });

            const data = await res.json();

            if (!res.ok) {
                throw new Error(data.message || 'Failed to submit response');
            }

            // Check if already responded by another user/tab
            if (data.already_responded) {
                state.value = 'submitted';
                stopCountdown();
                toast.show({
                    title: 'Already responded',
                    message: 'Another user/tab already responded to this interaction',
                    type: 'info',
                    duration: 3000,
                });
            } else {
                state.value = 'submitted';
                stopCountdown();
                toast.show({
                    title: 'Response submitted',
                    message: 'The agent will continue with your input',
                    type: 'success',
                    duration: 3000,
                });
            }

            // Remove from pending
            pendingInteractions.value = pendingInteractions.value.filter(
                i => i.id !== currentInteraction.value?.id
            );

            // Show next pending or clear
            setTimeout(() => {
                if (pendingInteractions.value.length > 0) {
                    showInteraction(pendingInteractions.value[0]);
                } else {
                    currentInteraction.value = null;
                    state.value = 'idle';
                }
            }, 1500);

            return true;
        } catch (e: any) {
            console.error('[Interaction] Submit failed:', e);
            error.value = e.message || 'Failed to submit response';
            state.value = 'error';

            toast.show({
                title: 'Submission failed',
                message: error.value,
                type: 'error',
                duration: 5000,
            });

            return false;
        }
    };

    /**
     * Dismiss current interaction (skip)
     */
    const dismissInteraction = () => {
        if (!currentInteraction.value) return;

        stopCountdown();

        // Remove from pending
        pendingInteractions.value = pendingInteractions.value.filter(
            i => i.id !== currentInteraction.value?.id
        );

        // Show next or clear
        if (pendingInteractions.value.length > 0) {
            showInteraction(pendingInteractions.value[0]);
        } else {
            currentInteraction.value = null;
            state.value = 'idle';
        }
    };

    /**
     * Retry after error
     */
    const retry = () => {
        if (currentInteraction.value && state.value === 'error') {
            state.value = 'pending';
            error.value = null;
            generateIdempotencyKey();
        }
    };

    /**
     * Fetch pending interactions from server
     */
    const fetchPending = async () => {
        try {
            const res = await fetch('/api/interactions/pending', {
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();

            if (data.interactions?.length > 0) {
                pendingInteractions.value = data.interactions;

                // Show first one if not already showing something
                if (!currentInteraction.value) {
                    showInteraction(pendingInteractions.value[0]);
                }
            }
        } catch (e) {
            console.error('[Interaction] Failed to fetch pending:', e);
        }
    };

    /**
     * Connect to real-time channels
     */
    const connect = () => {
        if (!echo) {
            console.warn('[Interaction] Echo not initialized');
            return;
        }

        // Private channel for user-specific interactions
        privateChannel = echo.private(`user.${userId}.interactions`)
            .listen('.interaction.created', handleInteractionCreated);

        // Public channel for response notifications (multi-tab coordination)
        publicChannel = echo.channel('interactions')
            .listen('.interaction.responded', handleInteractionResponded)
            .listen('.interaction.expired', handleInteractionExpiredEvent);

        // BroadcastChannel for same-origin tab communication
        if (typeof BroadcastChannel !== 'undefined') {
            broadcastChannel = new BroadcastChannel('zao_interactions');
            broadcastChannel.onmessage = (event) => {
                const interactionId = event.data.interactionId;

                if (event.data.type === 'interaction_responded') {
                    // Another tab responded - close our modal if showing same interaction
                    if (currentInteraction.value?.id === interactionId) {
                        state.value = 'submitted';
                        stopCountdown();
                        setTimeout(() => {
                            if (pendingInteractions.value.length > 0) {
                                showInteraction(pendingInteractions.value[0]);
                            } else {
                                currentInteraction.value = null;
                                state.value = 'idle';
                            }
                        }, 1000);
                    }
                } else if (event.data.type === 'interaction_expired') {
                    // Another tab detected expiration - update our state
                    pendingInteractions.value = pendingInteractions.value.filter(i => i.id !== interactionId);
                    if (currentInteraction.value?.id === interactionId) {
                        state.value = 'expired';
                        stopCountdown();
                        setTimeout(() => {
                            if (pendingInteractions.value.length > 0) {
                                showInteraction(pendingInteractions.value[0]);
                            } else {
                                currentInteraction.value = null;
                                state.value = 'idle';
                            }
                        }, 2000);
                    }
                }
            };
        }

        isConnected.value = true;
    };

    /**
     * Disconnect from channels
     */
    const disconnect = () => {
        if (privateChannel) {
            echo.leave(`user.${userId}.interactions`);
            privateChannel = null;
        }

        if (publicChannel) {
            echo.leave('interactions');
            publicChannel = null;
        }

        if (broadcastChannel) {
            broadcastChannel.close();
            broadcastChannel = null;
        }

        stopCountdown();
        isConnected.value = false;
    };

    onMounted(() => {
        connect();
        fetchPending();
    });

    onUnmounted(() => {
        disconnect();
    });

    return {
        // State
        state,
        currentInteraction,
        pendingInteractions,
        remainingSeconds,
        error,
        isConnected,

        // Computed
        hasPendingInteraction,
        isSubmitting,
        canRespond,

        // Actions
        submitResponse,
        dismissInteraction,
        retry,
        fetchPending,
        connect,
        disconnect,
    };
}
