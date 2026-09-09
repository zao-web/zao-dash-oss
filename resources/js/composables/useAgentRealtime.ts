import { ref, onMounted, onUnmounted } from 'vue';
import echo from '@/echo';

interface AgentRunUpdate {
    run_id: number;
    agent_id: number;
    agent_slug: string;
    agent_name: string;
    status: string;
    previous_status: string;
    started_at: string | null;
    completed_at: string | null;
    cost_usd: number | null;
    invocation_source: string;
}

interface OutputChunk {
    run_id: number;
    agent_id: number;
    chunk: string;
    is_complete: boolean;
}

/**
 * Listen for real-time agent run updates.
 */
export function useAgentRealtime(agentId?: number) {
    const latestUpdate = ref<AgentRunUpdate | null>(null);
    const outputChunks = ref<string[]>([]);
    const isConnected = ref(false);

    let agentChannel: ReturnType<typeof echo.private> | null = null;
    let publicChannel: ReturnType<typeof echo.channel> | null = null;

    const connect = () => {
        if (!echo) {
            console.warn('Echo not initialized');
            return;
        }

        // Listen to public agents channel for all updates
        publicChannel = echo.channel('agents')
            .listen('.run.status.changed', (data: AgentRunUpdate) => {
                latestUpdate.value = data;
            });

        // If specific agent, also listen to private channel
        if (agentId) {
            agentChannel = echo.private(`agents.${agentId}`)
                .listen('.run.status.changed', (data: AgentRunUpdate) => {
                    latestUpdate.value = data;
                });
        }

        isConnected.value = true;
    };

    const disconnect = () => {
        if (publicChannel) {
            echo.leave('agents');
            publicChannel = null;
        }

        if (agentChannel && agentId) {
            echo.leave(`agents.${agentId}`);
            agentChannel = null;
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
        latestUpdate,
        outputChunks,
        isConnected,
        connect,
        disconnect,
    };
}

/**
 * Listen for updates on a specific agent run.
 */
export function useAgentRunRealtime(runId: number) {
    const status = ref<string>('');
    const output = ref<string>('');
    const isComplete = ref(false);
    const isConnected = ref(false);

    let channel: ReturnType<typeof echo.private> | null = null;

    const connect = () => {
        if (!echo) {
            console.warn('Echo not initialized');
            return;
        }

        channel = echo.private(`agent-runs.${runId}`)
            .listen('.run.status.changed', (data: AgentRunUpdate) => {
                status.value = data.status;
                if (data.status === 'completed' || data.status === 'failed') {
                    isComplete.value = true;
                }
            })
            .listen('.run.output.updated', (data: OutputChunk) => {
                output.value += data.chunk;
                if (data.is_complete) {
                    isComplete.value = true;
                }
            });

        isConnected.value = true;
    };

    const disconnect = () => {
        if (channel) {
            echo.leave(`agent-runs.${runId}`);
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
        status,
        output,
        isComplete,
        isConnected,
        connect,
        disconnect,
    };
}
