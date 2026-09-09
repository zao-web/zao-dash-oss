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

export interface SiteBuilderState {
    status: string;
    progress: number;
    currentPhase: string;
    stagingUrl?: string;
    productionUrl?: string;
    error?: string;
}

export function useSiteBuilderChat(projectId: number) {
    const messages = ref<ChatMessage[]>([]);
    const isStreaming = ref(false);
    const currentStreamingContent = ref('');
    const error = ref<string | null>(null);
    
    const buildState = ref<SiteBuilderState>({
        status: 'created',
        progress: 0,
        currentPhase: 'initializing',
    });
    
    const phases = ref<BuildPhase[]>([
        { id: 'research', name: 'Research', status: 'pending', progress: 0, description: 'Analyzing domain and gathering information' },
        { id: 'design_blueprint', name: 'Design', status: 'pending', progress: 0, description: 'Creating design blueprint' },
        { id: 'theme_generation', name: 'Theme', status: 'pending', progress: 0, description: 'Generating theme files' },
        { id: 'page_home', name: 'Home', status: 'pending', progress: 0, description: 'Building homepage' },
        { id: 'page_about', name: 'About', status: 'pending', progress: 0, description: 'Building about page' },
        { id: 'page_services', name: 'Services', status: 'pending', progress: 0, description: 'Building services page' },
        { id: 'page_contact', name: 'Contact', status: 'pending', progress: 0, description: 'Building contact page' },
        { id: 'qa', name: 'QA', status: 'pending', progress: 0, description: 'Quality assurance checks' },
        { id: 'finalization', name: 'Done', status: 'pending', progress: 0, description: 'Finalizing site' },
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
            });
        
        isConnected.value = true;
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
            const response = await fetch(`/api/site-builder/projects/${projectId}/chat`, {
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
    
    onMounted(() => connect());
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
