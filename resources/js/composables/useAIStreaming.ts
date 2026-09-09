import { ref, computed } from 'vue';

export interface ToolCall {
    id: string;
    name: string;
    input: Record<string, unknown>;
    status: 'pending' | 'running' | 'completed' | 'error';
    result?: unknown;
}

export interface AIMessage {
    role: 'user' | 'assistant';
    content: string;
    timestamp: Date;
    toolCalls?: ToolCall[];
}

export interface AIStreamingState {
    isStreaming: boolean;
    currentResponse: string;
    error: string | null;
    messages: AIMessage[];
    activeToolCalls: ToolCall[];
    isThinking: boolean;
}

/**
 * Get current page context from the URL.
 */
function getPageContext(): { route: string; params: Record<string, string> } {
    const path = window.location.pathname;
    const params: Record<string, string> = {};

    // Extract slug from common routes
    const projectMatch = path.match(/^\/projects\/([^/]+)/);
    if (projectMatch) {
        params.slug = projectMatch[1];
    }

    const clientMatch = path.match(/^\/clients\/([^/]+)/);
    if (clientMatch) {
        params.slug = clientMatch[1];
    }

    const agentMatch = path.match(/^\/agents\/([^/]+)/);
    if (agentMatch) {
        params.slug = agentMatch[1];
    }

    return { route: path, params };
}

/**
 * Composable for AI streaming chat in command palette.
 *
 * Handles SSE connection to /api/command-palette/chat
 * Now supports real-time tool execution streaming.
 */
export function useAIStreaming() {
    const isStreaming = ref(false);
    const currentResponse = ref('');
    const error = ref<string | null>(null);
    const messages = ref<AIMessage[]>([]);
    const activeToolCalls = ref<ToolCall[]>([]);
    const isThinking = ref(false);

    /**
     * Send a message and stream the response.
     */
    const sendMessage = async (message: string): Promise<string> => {
        if (isStreaming.value) return '';

        isStreaming.value = true;
        currentResponse.value = '';
        error.value = null;

        // Add user message
        messages.value.push({
            role: 'user',
            content: message,
            timestamp: new Date(),
        });

        try {
            const response = await fetch('/api/command-palette/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    message,
                    context: messages.value.slice(-10).map(m => ({
                        role: m.role,
                        content: m.content,
                    })),
                    page_context: getPageContext(),
                }),
            });

            if (!response.ok) {
                throw new Error(`HTTP error: ${response.status}`);
            }

            const reader = response.body?.getReader();
            if (!reader) throw new Error('No response body');

            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                // Parse SSE events
                const lines = buffer.split('\n');
                buffer = lines.pop() || ''; // Keep incomplete line in buffer

                    let currentEventType = '';
                    for (const line of lines) {
                        if (line.startsWith('event: ')) {
                            currentEventType = line.slice(7).trim();
                        } else if (line.startsWith('data: ')) {
                            const data = line.slice(6);
                            try {
                                const parsed = JSON.parse(data);

                                switch (currentEventType) {
                                    case 'thinking':
                                        isThinking.value = true;
                                        break;

                                    case 'tool_call':
                                        isThinking.value = false;
                                        activeToolCalls.value.push({
                                            id: parsed.id,
                                            name: parsed.name,
                                            input: parsed.input,
                                            status: 'running',
                                        });
                                        break;

                                    case 'tool_result':
                                        const toolIndex = activeToolCalls.value.findIndex(t => t.id === parsed.id);
                                        if (toolIndex !== -1) {
                                            activeToolCalls.value[toolIndex].status = 'completed';
                                            activeToolCalls.value[toolIndex].result = parsed.result;
                                        }
                                        break;

                                    case 'content':
                                        isThinking.value = false;
                                        if (parsed.text) {
                                            currentResponse.value += parsed.text;
                                        }
                                        break;

                                    case 'error':
                                        isThinking.value = false;
                                        error.value = parsed.message;
                                        break;

                                    case 'done':
                                        isThinking.value = false;
                                        break;

                                    default:
                                        if (parsed.text) {
                                            currentResponse.value += parsed.text;
                                        } else if (parsed.message) {
                                            error.value = parsed.message;
                                        }
                                }
                            } catch {
                                // Ignore parse errors
                            }
                        }
                    }
            }

            // Add assistant message with any tool calls
            if (currentResponse.value || activeToolCalls.value.length > 0) {
                messages.value.push({
                    role: 'assistant',
                    content: currentResponse.value,
                    timestamp: new Date(),
                    toolCalls: activeToolCalls.value.length > 0 
                        ? [...activeToolCalls.value] 
                        : undefined,
                });
            }

            // Reset tool calls for next message
            activeToolCalls.value = [];

            return currentResponse.value;

        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Unknown error';
            return '';
        } finally {
            isStreaming.value = false;
        }
    };

    const clearMessages = () => {
        messages.value = [];
        currentResponse.value = '';
        error.value = null;
        activeToolCalls.value = [];
        isThinking.value = false;
    };

    /**
     * Get last response.
     */
    const lastResponse = computed(() => {
        const assistantMessages = messages.value.filter(m => m.role === 'assistant');
        return assistantMessages[assistantMessages.length - 1]?.content || '';
    });

    return {
        isStreaming,
        currentResponse,
        error,
        messages,
        activeToolCalls,
        isThinking,
        sendMessage,
        clearMessages,
        lastResponse,
    };
}
