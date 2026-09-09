import { ref, computed, onMounted, onUnmounted } from 'vue';
import echo from '@/echo';

export interface ChatMessage {
    id: string;
    role: 'user' | 'assistant' | 'system';
    content: string;
    timestamp: Date;
    status?: 'sending' | 'sent' | 'error';
    metadata?: {
        phase?: string;
        progress?: number;
        action?: string;
        tool?: string;
        isStreaming?: boolean;
    };
}

export interface BuildPhase {
    id: string;
    name: string;
    status: 'pending' | 'active' | 'completed' | 'failed';
    progress: number;
    startedAt?: Date;
    completedAt?: Date;
    description?: string;
}

export interface WebsiteBuilderState {
    status: string;
    progress: number;
    currentPhase: string;
    stagingUrl?: string;
    productionUrl?: string;
    error?: string;
}

export function useWebsiteBuilder(projectId: number) {
    const messages = ref<ChatMessage[]>([]);
    const isStreaming = ref(false);
    const currentStreamingContent = ref('');
    const error = ref<string | null>(null);
    
    const buildState = ref<WebsiteBuilderState>({
        status: 'created',
        progress: 0,
        currentPhase: 'initializing',
    });
    
    // Generic phases that adapt based on project type
    const phases = ref<BuildPhase[]>([
        { id: 'research', name: 'Research & Analysis', status: 'pending', progress: 0, description: 'Analyzing project requirements' },
        { id: 'design', name: 'Design System', status: 'pending', progress: 0, description: 'Creating design system' },
        { id: 'content', name: 'Content Generation', status: 'pending', progress: 0, description: 'Building content' },
        { id: 'implementation', name: 'Implementation', status: 'pending', progress: 0, description: 'Building site' },
        { id: 'qa', name: 'Quality Assurance', status: 'pending', progress: 0, description: 'Testing and review' },
    ]);
    
    const isConnected = ref(false);
    let channel: ReturnType<typeof echo.private> | null = null;
    
    const generateId = () => `msg_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    
    const updatePhaseStatus = (phaseId: string, status: BuildPhase['status'], progress: number) => {
        const phaseIndex = phases.value.findIndex(p => p.id === phaseId);
        if (phaseIndex !== -1) {
            phases.value[phaseIndex] = {
                ...phases.value[phaseIndex],
                status,
                progress,
                startedAt: status === 'active' && !phases.value[phaseIndex].startedAt 
                    ? new Date() 
                    : phases.value[phaseIndex].startedAt,
                completedAt: status === 'completed' ? new Date() : undefined,
            };
        }
    };
    
    const addMessage = (message: ChatMessage) => {
        if (!messages.value.find(m => m.id === message.id)) {
            messages.value.push(message);
        }
    };
    
    const connect = () => {
        if (!echo || !projectId) {
            console.warn('Echo not initialized or no project ID');
            return;
        }
        
        channel = echo.private(`website-builder.${projectId}`)
            .subscribed(() => {
                console.log(`[WebsiteBuilder] Connected to channel website-builder.${projectId}`);
                isConnected.value = true;
            })
            .error((err: any) => {
                console.error(`[WebsiteBuilder] Channel subscription error:`, err);
                isConnected.value = false;
                error.value = 'Failed to connect to real-time updates';
            })
            .listen('.status.updated', (data: any) => {
                buildState.value = {
                    ...buildState.value,
                    status: data.status,
                    progress: data.progress || buildState.value.progress,
                    currentPhase: data.phase || buildState.value.currentPhase,
                    stagingUrl: data.staging_url || buildState.value.stagingUrl,
                    productionUrl: data.production_url || buildState.value.productionUrl,
                    error: data.error,
                };
                
                if (data.phase) {
                    updatePhaseStatus(data.phase, data.phase_status || 'active', data.phase_progress || 0);
                }
            })
            .listen('.phase.progress', (data: any) => {
                updatePhaseStatus(data.phase, data.status, data.progress);
            })
            .listen('.message.received', (data: any) => {
                addMessage({
                    id: data.id || generateId(),
                    role: 'assistant',
                    content: data.content,
                    timestamp: new Date(data.timestamp || Date.now()),
                    metadata: data.metadata,
                });
            })
            .listen('.message.chunk', (data: any) => {
                if (data.is_start) {
                    isStreaming.value = true;
                    currentStreamingContent.value = '';
                }
                
                currentStreamingContent.value += data.chunk;
                
                if (data.is_end) {
                    isStreaming.value = false;
                    addMessage({
                        id: data.message_id || generateId(),
                        role: 'assistant',
                        content: currentStreamingContent.value,
                        timestamp: new Date(),
                        metadata: data.metadata,
                    });
                    currentStreamingContent.value = '';
                }
            })
            .listen('.tool.executed', (data: any) => {
                addMessage({
                    id: generateId(),
                    role: 'system',
                    content: `Executed: ${data.tool_name}`,
                    timestamp: new Date(),
                    metadata: {
                        tool: data.tool_name,
                        action: data.action,
                    },
                });
            })
            .listen('.error', (data: any) => {
                error.value = data.message;
                buildState.value.error = data.message;
                
                addMessage({
                    id: generateId(),
                    role: 'system',
                    content: `Error: ${data.message}`,
                    timestamp: new Date(),
                    metadata: { action: 'error' },
                });
            })
            .listen('.token.usage', (data: any) => {
                if (data.current && data.budget) {
                    console.log(`[WebsiteBuilder] Token usage: ${data.current} / ${data.budget}`);
                }
            });
    };
    
    const disconnect = () => {
        if (channel && projectId) {
            echo.leave(`website-builder.${projectId}`);
            channel = null;
        }
        isConnected.value = false;
    };
    
    const sendMessage = async (content: string): Promise<void> => {
        if (!content.trim()) return;
        
        const userMessage: ChatMessage = {
            id: generateId(),
            role: 'user',
            content: content.trim(),
            timestamp: new Date(),
            status: 'sending',
        };
        
        messages.value.push(userMessage);
        
        try {
            const response = await fetch(`/api/website-builder/projects/${projectId}/chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    message: content,
                    context: messages.value.slice(-10).map(m => ({
                        role: m.role,
                        content: m.content,
                    })),
                }),
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error: ${response.status}`);
            }
            
            const msgIndex = messages.value.findIndex(m => m.id === userMessage.id);
            if (msgIndex !== -1) {
                messages.value[msgIndex].status = 'sent';
            }
            
        } catch (e) {
            const msgIndex = messages.value.findIndex(m => m.id === userMessage.id);
            if (msgIndex !== -1) {
                messages.value[msgIndex].status = 'error';
            }
            error.value = e instanceof Error ? e.message : 'Failed to send message';
        }
    };
    
    const clearMessages = () => {
        messages.value = [];
        currentStreamingContent.value = '';
        error.value = null;
    };
    
    const retryMessage = async (messageId: string) => {
        const message = messages.value.find(m => m.id === messageId);
        if (message && message.role === 'user' && message.status === 'error') {
            messages.value = messages.value.filter(m => m.id !== messageId);
            await sendMessage(message.content);
        }
    };
    
    const currentPhase = computed(() => {
        return phases.value.find(p => p.status === 'active') || phases.value[0];
    });
    
    const overallProgress = computed(() => {
        const totalWeight = phases.value.length;
        const completedWeight = phases.value.reduce((acc, phase) => {
            if (phase.status === 'completed') return acc + 1;
            if (phase.status === 'active') return acc + (phase.progress / 100);
            return acc;
        }, 0);
        return Math.round((completedWeight / totalWeight) * 100);
    });
    
    const isBuilding = computed(() => {
        return ['analyzing', 'designing', 'building', 'reviewing', 'deploying'].includes(buildState.value.status);
    });
    
    const isComplete = computed(() => buildState.value.status === 'complete');
    
    const hasFailed = computed(() => buildState.value.status === 'failed');
    
    const fetchInitialMessages = async () => {
        try {
            const response = await fetch(`/api/website-builder/projects/${projectId}/messages`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.messages?.length > 0) {
                    data.messages.forEach((msg: any) => {
                        addMessage({
                            id: msg.id,
                            role: msg.role,
                            content: msg.content,
                            timestamp: new Date(msg.timestamp),
                            status: msg.status,
                            metadata: msg.metadata,
                        });
                    });
                }
            }
        } catch (e) {
            console.warn('Failed to fetch messages:', e);
        }
    };
    
    const fetchInitialStatus = async () => {
        try {
            const response = await fetch(`/api/website-builder/projects/${projectId}/status`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    buildState.value = {
                        ...buildState.value,
                        status: data.status || buildState.value.status,
                        progress: data.progress_percentage || buildState.value.progress,
                        currentPhase: data.progress_data?.current_phase || buildState.value.currentPhase,
                        stagingUrl: data.staging_url || buildState.value.stagingUrl,
                        productionUrl: data.production_url || buildState.value.productionUrl,
                        error: data.last_error,
                    };
                    
                    if (data.progress_percentage > 0 && messages.value.length === 0) {
                        const statusMessage = data.status === 'complete' 
                            ? 'Website build complete! Your site is ready.'
                            : data.status === 'failed'
                            ? `Build encountered an error: ${data.last_error || 'Unknown error'}`
                            : `Build in progress (${data.progress_percentage}%)... ${data.status || 'working'}`;
                        
                        addMessage({
                            id: 'initial_status',
                            role: 'system',
                            content: statusMessage,
                            timestamp: new Date(),
                            metadata: { phase: data.progress_data?.current_phase },
                        });
                    }
                }
            }
        } catch (e) {
            console.warn('Failed to fetch initial status:', e);
        }
    };
    
    onMounted(async () => {
        connect();
        await fetchInitialMessages();
        await fetchInitialStatus();
    });
    onUnmounted(() => disconnect());
    
    return {
        messages,
        isStreaming,
        currentStreamingContent,
        error,
        sendMessage,
        clearMessages,
        retryMessage,
        buildState,
        phases,
        currentPhase,
        overallProgress,
        isBuilding,
        isComplete,
        hasFailed,
        isConnected,
        connect,
        disconnect,
    };
}
