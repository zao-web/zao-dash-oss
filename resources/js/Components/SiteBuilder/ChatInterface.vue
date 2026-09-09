<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import type { ChatMessage, BuildPhase } from '@/composables/useSiteBuilderChat';

// Configure marked for safe rendering
marked.setOptions({
    breaks: true,
    gfm: true,
});

// Render markdown content safely
const renderMarkdown = (content: string): string => {
    const rawHtml = marked.parse(content) as string;
    return DOMPurify.sanitize(rawHtml, {
        ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'code', 'pre', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'hr'],
        ALLOWED_ATTR: ['href', 'target', 'rel'],
    });
};

const props = defineProps<{
    messages: ChatMessage[];
    isStreaming: boolean;
    currentStreamingContent: string;
    phases: BuildPhase[];
    overallProgress: number;
    isConnected: boolean;
    error: string | null;
}>();

const emit = defineEmits<{
    (e: 'send', message: string): void;
    (e: 'retry', messageId: string): void;
}>();

const inputRef = ref<HTMLTextAreaElement | null>(null);
const messagesContainerRef = ref<HTMLDivElement | null>(null);
const messageInput = ref('');
const isInputFocused = ref(false);

const scrollToBottom = async () => {
    await nextTick();
    if (messagesContainerRef.value) {
        messagesContainerRef.value.scrollTop = messagesContainerRef.value.scrollHeight;
    }
};

watch(() => props.messages.length, scrollToBottom);
watch(() => props.currentStreamingContent, scrollToBottom);

const handleSubmit = () => {
    if (!messageInput.value.trim() || props.isStreaming) return;
    emit('send', messageInput.value);
    messageInput.value = '';
};

const handleKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSubmit();
    }
};

const formatTime = (date: Date) => {
    return new Date(date).toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
    });
};

const getPhaseIcon = (phase: BuildPhase) => {
    const icons: Record<string, string> = {
        research: '🔍',
        wordpress_setup: '🔧',
        content_generation: '✍️',
        content_sync: '🔄',
        qa: '✅',
    };
    return icons[phase.id] || '○';
};

const getPhaseStatusClass = (status: BuildPhase['status']) => {
    return {
        'phase-pending': status === 'pending',
        'phase-active': status === 'active',
        'phase-completed': status === 'completed',
        'phase-failed': status === 'failed',
    };
};

onMounted(() => {
    scrollToBottom();
});
</script>

<template>
    <div class="chat-interface">
        <!-- Progress Timeline -->
        <div class="progress-timeline">
            <div class="progress-header">
                <div class="progress-title">
                    <span class="progress-icon">🚀</span>
                    <span>Build Progress</span>
                </div>
                <div class="progress-percentage">{{ overallProgress }}%</div>
            </div>
            <div class="progress-track">
                <div class="progress-fill" :style="{ width: `${overallProgress}%` }"></div>
            </div>
            <div class="phases">
                <div
                    v-for="phase in phases"
                    :key="phase.id"
                    class="phase"
                    :class="getPhaseStatusClass(phase.status)"
                >
                    <div class="phase-indicator">
                        <span v-if="phase.status === 'completed'" class="phase-check">✓</span>
                        <span v-else-if="phase.status === 'active'" class="phase-spinner"></span>
                        <span v-else class="phase-dot"></span>
                    </div>
                    <div class="phase-info">
                        <span class="phase-name">{{ phase.name }}</span>
                        <span v-if="phase.status === 'active'" class="phase-progress">{{ phase.progress }}%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages Area -->
        <div ref="messagesContainerRef" class="messages-container">
            <!-- Welcome message when empty -->
            <div v-if="messages.length === 0 && !isStreaming" class="welcome-message">
                <div class="welcome-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M12 2L2 7l10 5 10-5-10-5z" />
                        <path d="M2 17l10 5 10-5" />
                        <path d="M2 12l10 5 10-5" />
                    </svg>
                </div>
                <h3 class="welcome-title">AI Site Builder</h3>
                <p class="welcome-text">
                    I'm building your website. You can ask questions, request changes, or provide additional guidance at any time.
                </p>
                <div class="welcome-suggestions">
                    <button class="suggestion-chip" @click="emit('send', 'What\'s the current status?')">
                        What's the current status?
                    </button>
                    <button class="suggestion-chip" @click="emit('send', 'Can you show me a preview?')">
                        Show me a preview
                    </button>
                    <button class="suggestion-chip" @click="emit('send', 'What pages are you creating?')">
                        What pages are planned?
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <TransitionGroup name="message" tag="div" class="messages-list">
                <div
                    v-for="message in messages"
                    :key="message.id"
                    class="message"
                    :class="{
                        'message-user': message.role === 'user',
                        'message-assistant': message.role === 'assistant',
                        'message-system': message.role === 'system',
                        'message-error': message.status === 'error',
                    }"
                >
                    <!-- Avatar -->
                    <div class="message-avatar">
                        <div v-if="message.role === 'user'" class="avatar avatar-user">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>
                            </svg>
                        </div>
                        <div v-else-if="message.role === 'assistant'" class="avatar avatar-ai">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2a2 2 0 012 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 017 7h1a1 1 0 011 1v3a1 1 0 01-1 1h-1v1a2 2 0 01-2 2H5a2 2 0 01-2-2v-1H2a1 1 0 01-1-1v-3a1 1 0 011-1h1a7 7 0 017-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 012-2zM7.5 13a1.5 1.5 0 100 3 1.5 1.5 0 000-3zm9 0a1.5 1.5 0 100 3 1.5 1.5 0 000-3zM12 9a5 5 0 00-5 5v1h10v-1a5 5 0 00-5-5z"/>
                            </svg>
                        </div>
                        <div v-else class="avatar avatar-system">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="message-content">
                        <div class="message-header">
                            <span class="message-role">
                                {{ message.role === 'user' ? 'You' : message.role === 'assistant' ? 'AI Builder' : 'System' }}
                            </span>
                            <span class="message-time">{{ formatTime(message.timestamp) }}</span>
                        </div>
<div class="message-body">
                                            <div class="message-text" v-html="renderMarkdown(message.content)"></div>
                            
                            <!-- Tool/Action indicator -->
                            <div v-if="message.metadata?.tool" class="message-tool">
                                <span class="tool-icon">⚡</span>
                                <span class="tool-name">{{ message.metadata.tool }}</span>
                            </div>
                        </div>
                        
                        <!-- Error retry button -->
                        <button 
                            v-if="message.status === 'error'" 
                            class="retry-button"
                            @click="emit('retry', message.id)"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 4v6h6M23 20v-6h-6"/>
                                <path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15"/>
                            </svg>
                            Retry
                        </button>
                    </div>
                </div>
            </TransitionGroup>

            <!-- Streaming indicator -->
            <div v-if="isStreaming" class="message message-assistant streaming">
                <div class="message-avatar">
                    <div class="avatar avatar-ai avatar-streaming">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2a2 2 0 012 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 017 7h1a1 1 0 011 1v3a1 1 0 01-1 1h-1v1a2 2 0 01-2 2H5a2 2 0 01-2-2v-1H2a1 1 0 01-1-1v-3a1 1 0 011-1h1a7 7 0 017-7h1V5.73c-.6-.34-1-.99-1-1.73a2 2 0 012-2zM7.5 13a1.5 1.5 0 100 3 1.5 1.5 0 000-3zm9 0a1.5 1.5 0 100 3 1.5 1.5 0 000-3zM12 9a5 5 0 00-5 5v1h10v-1a5 5 0 00-5-5z"/>
                        </svg>
                    </div>
                </div>
                <div class="message-content">
                    <div class="message-header">
                        <span class="message-role">AI Builder</span>
                        <span class="message-time">now</span>
                    </div>
                    <div class="message-body">
                        <div v-if="currentStreamingContent" class="message-text">
                            <span v-html="renderMarkdown(currentStreamingContent)"></span><span class="cursor">|</span>
                        </div>
                        <div v-else class="typing-indicator">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Input Area -->
        <div class="input-area" :class="{ 'input-focused': isInputFocused }">
            <div class="connection-status" :class="{ connected: isConnected }">
                <span class="status-dot"></span>
                <span class="status-text">{{ isConnected ? 'Connected' : 'Connecting...' }}</span>
            </div>
            <div class="input-wrapper">
                <textarea
                    ref="inputRef"
                    v-model="messageInput"
                    class="message-input"
                    placeholder="Ask a question or give instructions..."
                    rows="1"
                    :disabled="isStreaming"
                    @keydown="handleKeyDown"
                    @focus="isInputFocused = true"
                    @blur="isInputFocused = false"
                ></textarea>
                <button
                    class="send-button"
                    :disabled="!messageInput.trim() || isStreaming"
                    @click="handleSubmit"
                >
                    <svg v-if="!isStreaming" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                    <svg v-else class="spinner" width="20" height="20" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none" stroke-dasharray="32" stroke-dashoffset="32">
                            <animate attributeName="stroke-dashoffset" values="32;0" dur="1s" repeatCount="indefinite"/>
                        </circle>
                    </svg>
                </button>
            </div>
            <div class="input-hint">
                <kbd>Enter</kbd> to send · <kbd>Shift + Enter</kbd> for new line
            </div>
        </div>
    </div>
</template>

<style scoped>
.chat-interface {
    display: flex;
    flex-direction: column;
    height: 100%;
    background: var(--color-bg-primary);
    border-radius: 12px;
    overflow: hidden;
}

/* Progress Timeline */
.progress-timeline {
    padding: 1rem 1.25rem;
    background: var(--color-bg-secondary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.progress-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.progress-icon {
    font-size: 1rem;
}

.progress-percentage {
    font-family: var(--font-mono);
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-accent);
}

.progress-track {
    height: 4px;
    background: var(--color-bg-elevated);
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 1rem;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--color-accent) 0%, #ec4899 100%);
    border-radius: 2px;
    transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

.phases {
    display: flex;
    gap: 0.25rem;
}

.phase {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.5rem;
    background: var(--color-bg-tertiary);
    border-radius: 6px;
    transition: all 0.2s ease;
}

.phase-indicator {
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.phase-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--color-text-quaternary);
}

.phase-check {
    font-size: 0.75rem;
    color: var(--color-status-green);
}

.phase-spinner {
    width: 12px;
    height: 12px;
    border: 2px solid var(--color-border-default);
    border-top-color: var(--color-accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.phase-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.phase-name {
    font-size: 0.625rem;
    font-weight: 500;
    color: var(--color-text-tertiary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.phase-progress {
    font-family: var(--font-mono);
    font-size: 0.625rem;
    color: var(--color-accent);
}

.phase-pending { opacity: 0.5; }
.phase-active { 
    background: rgba(139, 92, 246, 0.15);
    border: 1px solid rgba(139, 92, 246, 0.3);
}
.phase-completed { 
    background: rgba(34, 197, 94, 0.1);
}
.phase-failed { 
    background: rgba(239, 68, 68, 0.1);
}

/* Messages Container */
.messages-container {
    flex: 1;
    overflow-y: auto;
    padding: 1.25rem;
    scroll-behavior: smooth;
}

.messages-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* Welcome Message */
.welcome-message {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 3rem 2rem;
    min-height: 300px;
}

.welcome-icon {
    width: 80px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(236, 72, 153, 0.2) 100%);
    border-radius: 20px;
    margin-bottom: 1.5rem;
    color: var(--color-accent);
}

.welcome-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
}

.welcome-text {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    max-width: 320px;
    margin-bottom: 1.5rem;
    line-height: 1.5;
}

.welcome-suggestions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: center;
}

.suggestion-chip {
    padding: 0.5rem 0.875rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 20px;
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.suggestion-chip:hover {
    background: var(--color-bg-elevated);
    border-color: var(--color-accent);
    color: var(--color-text-primary);
}

/* Message */
.message {
    display: flex;
    gap: 0.75rem;
    animation: messageIn 0.3s ease-out;
}

@keyframes messageIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.message-user {
    flex-direction: row-reverse;
}

.message-avatar {
    flex-shrink: 0;
}

.avatar {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.avatar-user {
    background: var(--color-bg-elevated);
    color: var(--color-text-secondary);
}

.avatar-ai {
    background: linear-gradient(135deg, var(--color-accent) 0%, #ec4899 100%);
    color: white;
}

.avatar-streaming {
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.avatar-system {
    background: var(--color-bg-tertiary);
    color: var(--color-status-green);
    width: 28px;
    height: 28px;
    border-radius: 8px;
}

.message-content {
    max-width: 70%;
    min-width: 0;
}

.message-user .message-content {
    align-items: flex-end;
}

.message-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
}

.message-user .message-header {
    flex-direction: row-reverse;
}

.message-role {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--color-text-tertiary);
}

.message-time {
    font-size: 0.625rem;
    color: var(--color-text-quaternary);
}

.message-body {
    padding: 0.75rem 1rem;
    border-radius: 12px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
}

.message-user .message-body {
    background: var(--color-accent);
    border-color: transparent;
}

.message-user .message-body .message-text {
    color: white;
}

.message-assistant .message-body {
    background: var(--color-bg-secondary);
    border-left: 3px solid var(--color-accent);
}

.message-system .message-body {
    background: var(--color-bg-tertiary);
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
}

.message-text {
    font-size: 0.875rem;
    line-height: 1.6;
    color: var(--color-text-primary);
    word-break: break-word;
}

/* Markdown content styling */
.message-text :deep(p) {
    margin: 0 0 0.5rem 0;
}

.message-text :deep(p:last-child) {
    margin-bottom: 0;
}

.message-text :deep(code) {
    background: var(--color-bg-tertiary);
    padding: 0.125rem 0.375rem;
    border-radius: 4px;
    font-family: var(--font-mono);
    font-size: 0.8125rem;
}

.message-text :deep(pre) {
    background: var(--color-bg-tertiary);
    padding: 0.75rem 1rem;
    border-radius: 6px;
    overflow-x: auto;
    margin: 0.5rem 0;
}

.message-text :deep(pre code) {
    background: transparent;
    padding: 0;
}

.message-text :deep(ul),
.message-text :deep(ol) {
    margin: 0.5rem 0;
    padding-left: 1.5rem;
}

.message-text :deep(li) {
    margin: 0.25rem 0;
}

.message-text :deep(strong) {
    font-weight: 600;
}

.message-text :deep(a) {
    color: var(--color-accent);
    text-decoration: underline;
}

.message-text :deep(a:hover) {
    text-decoration: none;
}

.message-text :deep(blockquote) {
    border-left: 3px solid var(--color-border-default);
    padding-left: 1rem;
    margin: 0.5rem 0;
    color: var(--color-text-secondary);
}

.message-text :deep(h1),
.message-text :deep(h2),
.message-text :deep(h3),
.message-text :deep(h4) {
    margin: 0.75rem 0 0.5rem 0;
    font-weight: 600;
}

.message-text :deep(h1) { font-size: 1.25rem; }
.message-text :deep(h2) { font-size: 1.125rem; }
.message-text :deep(h3) { font-size: 1rem; }
.message-text :deep(h4) { font-size: 0.875rem; }

.message-text :deep(hr) {
    border: none;
    border-top: 1px solid var(--color-border-subtle);
    margin: 0.75rem 0;
}

.message-system .message-text {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.message-tool {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin-top: 0.5rem;
    padding: 0.25rem 0.5rem;
    background: rgba(139, 92, 246, 0.1);
    border-radius: 4px;
    font-size: 0.6875rem;
    color: var(--color-accent);
}

.tool-icon {
    font-size: 0.75rem;
}

.message-error .message-body {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.3);
}

.message-error .message-text {
    color: var(--color-status-red);
}

.retry-button {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    margin-top: 0.5rem;
    padding: 0.375rem 0.75rem;
    background: transparent;
    border: 1px solid var(--color-status-red);
    border-radius: 6px;
    font-size: 0.75rem;
    color: var(--color-status-red);
    cursor: pointer;
    transition: all 0.15s ease;
}

.retry-button:hover {
    background: rgba(239, 68, 68, 0.1);
}

/* Streaming & Typing */
.cursor {
    animation: blink 1s step-end infinite;
    color: var(--color-accent);
    font-weight: 600;
}

@keyframes blink {
    0%, 50% { opacity: 1; }
    51%, 100% { opacity: 0; }
}

.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 4px 0;
}

.typing-indicator span {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--color-accent);
    animation: typing 1.4s ease-in-out infinite;
}

.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
    0%, 60%, 100% { 
        transform: translateY(0);
        opacity: 0.4;
    }
    30% { 
        transform: translateY(-4px);
        opacity: 1;
    }
}

/* Input Area */
.input-area {
    padding: 1rem 1.25rem;
    background: var(--color-bg-secondary);
    border-top: 1px solid var(--color-border-subtle);
    transition: border-color 0.2s ease;
}

.input-focused {
    border-top-color: var(--color-accent);
}

.connection-status {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    margin-bottom: 0.75rem;
    font-size: 0.6875rem;
    color: var(--color-text-tertiary);
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--color-status-yellow);
    transition: background 0.3s ease;
}

.connection-status.connected .status-dot {
    background: var(--color-status-green);
    box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2);
}

.input-wrapper {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
}

.message-input {
    flex: 1;
    padding: 0.75rem 1rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    font-size: 0.875rem;
    color: var(--color-text-primary);
    resize: none;
    min-height: 44px;
    max-height: 120px;
    transition: all 0.2s ease;
}

.message-input::placeholder {
    color: var(--color-text-quaternary);
}

.message-input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
}

.message-input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.send-button {
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-accent);
    border: none;
    border-radius: 12px;
    color: white;
    cursor: pointer;
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.send-button:hover:not(:disabled) {
    background: var(--color-accent-hover);
    transform: scale(1.05);
}

.send-button:active:not(:disabled) {
    transform: scale(0.95);
}

.send-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.input-hint {
    margin-top: 0.5rem;
    font-size: 0.625rem;
    color: var(--color-text-quaternary);
    text-align: center;
}

.input-hint kbd {
    padding: 0.125rem 0.375rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 3px;
    font-family: inherit;
    font-size: 0.625rem;
}

/* Transitions */
.message-enter-active {
    transition: all 0.3s ease-out;
}

.message-leave-active {
    transition: all 0.2s ease-in;
}

.message-enter-from {
    opacity: 0;
    transform: translateY(20px);
}

.message-leave-to {
    opacity: 0;
    transform: translateX(-20px);
}

/* Scrollbar */
.messages-container::-webkit-scrollbar {
    width: 6px;
}

.messages-container::-webkit-scrollbar-track {
    background: transparent;
}

.messages-container::-webkit-scrollbar-thumb {
    background: var(--color-border-strong);
    border-radius: 3px;
}

.messages-container::-webkit-scrollbar-thumb:hover {
    background: var(--color-text-quaternary);
}
</style>
