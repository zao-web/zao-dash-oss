<script setup lang="ts">
import { ref, watch, nextTick, onMounted } from 'vue';
import { useGlobalCommandPalette } from '@/composables/useGlobalCommandPalette';
import { useCommandPalette, type Command } from '@/composables/useCommandPalette';
import { useAIStreaming } from '@/composables/useAIStreaming';

const { isOpen, closePalette } = useGlobalCommandPalette();
const {
    query,
    selectedIndex,
    results,
    groupedResults,
    isAIMode,
    shouldShowAISuggestion,
    isLoadingObjects,
    selectPrevious,
    selectNext,
    executeSelected,
    clearQuery,
    switchToAIMode,
} = useCommandPalette();

const {
    isStreaming,
    currentResponse,
    error: aiError,
    messages: aiMessages,
    activeToolCalls,
    isThinking,
    sendMessage,
    clearMessages,
} = useAIStreaming();

const inputRef = ref<HTMLInputElement | null>(null);

// Command history for AI mode (like terminal up/down arrow)
const HISTORY_KEY = 'command-palette-history';
const MAX_HISTORY = 50;
const commandHistory = ref<string[]>([]);
const historyIndex = ref(-1);
const currentInput = ref(''); // Store current input when navigating history

onMounted(() => {
    // Load history from localStorage
    try {
        const stored = localStorage.getItem(HISTORY_KEY);
        if (stored) {
            commandHistory.value = JSON.parse(stored);
        }
    } catch {
        commandHistory.value = [];
    }
});

const addToHistory = (command: string) => {
    const trimmed = command.trim();
    if (!trimmed) return;
    
    // Remove duplicate if exists
    const existingIndex = commandHistory.value.indexOf(trimmed);
    if (existingIndex !== -1) {
        commandHistory.value.splice(existingIndex, 1);
    }
    
    // Add to beginning
    commandHistory.value.unshift(trimmed);
    
    // Limit size
    if (commandHistory.value.length > MAX_HISTORY) {
        commandHistory.value = commandHistory.value.slice(0, MAX_HISTORY);
    }
    
    // Save to localStorage
    try {
        localStorage.setItem(HISTORY_KEY, JSON.stringify(commandHistory.value));
    } catch {
        // Ignore storage errors
    }
};

const navigateHistory = (direction: 'up' | 'down') => {
    if (commandHistory.value.length === 0) return;
    
    const currentQuery = query.value.replace(/^[>?]\s*/, '');
    
    if (direction === 'up') {
        if (historyIndex.value === -1) {
            // Save current input before navigating
            currentInput.value = currentQuery;
        }
        if (historyIndex.value < commandHistory.value.length - 1) {
            historyIndex.value++;
            query.value = '> ' + commandHistory.value[historyIndex.value];
        }
    } else {
        if (historyIndex.value > 0) {
            historyIndex.value--;
            query.value = '> ' + commandHistory.value[historyIndex.value];
        } else if (historyIndex.value === 0) {
            historyIndex.value = -1;
            query.value = '> ' + currentInput.value;
        }
    }
};

const resetHistoryNavigation = () => {
    historyIndex.value = -1;
    currentInput.value = '';
};

// Focus input when opened
watch(isOpen, async (open) => {
    if (open) {
        await nextTick();
        inputRef.value?.focus();
    } else {
        clearQuery();
        clearMessages();
        resetHistoryNavigation();
    }
    document.body.style.overflow = open ? 'hidden' : '';
});

const handleKeyDown = (e: KeyboardEvent) => {
    if (isAIMode.value) {
        // In AI mode, handle history navigation with up/down arrows
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            navigateHistory('up');
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            navigateHistory('down');
        } else if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleAISubmit();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closePalette();
        }
        return;
    }

    switch (e.key) {
        case 'ArrowUp':
            e.preventDefault();
            selectPrevious();
            break;
        case 'ArrowDown':
            e.preventDefault();
            selectNext();
            break;
        case 'Enter':
            e.preventDefault();
            handleExecute();
            break;
        case 'Escape':
            e.preventDefault();
            closePalette();
            break;
    }
};

const handleExecute = async () => {
    const success = await executeSelected();
    if (success) {
        closePalette();
    }
};

const handleAISubmit = async () => {
    if (isStreaming.value) return;

    // Get the query without the > prefix
    const aiQuery = query.value.replace(/^[>?]\s*/, '').trim();
    if (!aiQuery) return;

    // Add to history before sending
    addToHistory(aiQuery);
    resetHistoryNavigation();

    await sendMessage(aiQuery);
    query.value = '>'; // Reset to AI mode prefix
};

const handleItemClick = async (command: Command, index: number) => {
    selectedIndex.value = index;
    await command.handler();
    closePalette();
};

const getCategoryLabel = (category: string): string => {
    const labels: Record<string, string> = {
        navigation: 'Navigation',
        action: 'Actions',
        agent: 'Agents',
        object: 'Search Results',
        ai: 'AI',
    };
    return labels[category] || category;
};

const renderMarkdown = (text: string): string => {
    if (!text) return '';

    // Sanitize HTML entities first to prevent XSS
    let html = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    // Fenced code blocks (```...```)
    html = html.replace(/```(?:\w*\n)?([\s\S]*?)```/g, (_match, code) => {
        return `<code class="ai-code-block">${code.trim()}</code>`;
    });

    // Inline code (`...`)
    html = html.replace(/`([^`]+)`/g, '<code class="ai-code-inline">$1</code>');

    // Bold (**...**)
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

    // Italic (*...*)
    html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');

    // Strip markdown headers (# / ## / ###) — just keep the text
    html = html.replace(/^#{1,3}\s+(.+)$/gm, '<strong>$1</strong>');

    // Newlines to <br>
    html = html.replace(/\n/g, '<br>');

    return html;
};

const getIcon = (iconName?: string) => {
    // Simple icon mapping - could be replaced with a proper icon library
    const icons: Record<string, string> = {
        'grid': '⊞',
        'folder': '📁',
        'check-square': '☑',
        'users': '👥',
        'trending-up': '📈',
        'user': '👤',
        'cpu': '🤖',
        'shield': '🛡',
        'lock': '🔒',
        'plus': '+',
        'folder-plus': '📁+',
        'user-plus': '👤+',
        'wallet': '💳',
        'document': '📄',
        'calculator': '🧮',
        'lightbulb': '💡',
    };
    return icons[iconName || ''] || '○';
};
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            leave-active-class="transition-opacity duration-100"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="isOpen" class="command-palette-backdrop" @click.self="closePalette">
                <Transition
                    enter-active-class="transition-all duration-150"
                    leave-active-class="transition-all duration-100"
                    enter-from-class="opacity-0 scale-95 -translate-y-2"
                    leave-to-class="opacity-0 scale-95 -translate-y-2"
                >
                    <div v-if="isOpen" class="command-palette">
                        <!-- Search Input -->
                        <div class="command-input-wrapper">
                            <span class="command-icon">⌘</span>
                            <input
                                ref="inputRef"
                                v-model="query"
                                type="text"
                                class="command-input"
                                placeholder="Search commands, navigate, or type > for AI..."
                                @keydown="handleKeyDown"
                            />
                            <kbd class="command-hint">ESC</kbd>
                        </div>

                        <!-- Results -->
                        <div class="command-results">
                            <!-- AI Mode Chat -->
                            <div v-if="isAIMode" class="ai-chat">
                                <!-- Messages -->
                                <div v-if="aiMessages.length > 0" class="ai-messages">
                                    <div
                                        v-for="(msg, idx) in aiMessages"
                                        :key="idx"
                                        class="ai-message"
                                        :class="msg.role === 'user' ? 'ai-message-user' : 'ai-message-assistant'"
                                    >
                                        <div class="ai-message-role">{{ msg.role === 'user' ? 'You' : 'AI' }}</div>
                                        <!-- Tool calls for this message -->
                                        <div v-if="msg.toolCalls?.length" class="ai-tool-calls">
                                            <div 
                                                v-for="tool in msg.toolCalls" 
                                                :key="tool.id" 
                                                class="ai-tool-call"
                                                :class="{ 'ai-tool-completed': tool.status === 'completed' }"
                                            >
                                                <span class="ai-tool-icon">{{ tool.status === 'completed' ? '✓' : '⚡' }}</span>
                                                <span class="ai-tool-name">{{ tool.name }}</span>
                                            </div>
                                        </div>
                                        <div
                                            v-if="msg.role === 'assistant'"
                                            class="ai-message-content"
                                            v-html="renderMarkdown(msg.content)"
                                        ></div>
                                        <div v-else class="ai-message-content">{{ msg.content }}</div>
                                    </div>
                                </div>

                                <!-- Active tool calls (during streaming) -->
                                <div v-if="isStreaming && activeToolCalls.length > 0" class="ai-active-tools">
                                    <div 
                                        v-for="tool in activeToolCalls" 
                                        :key="tool.id" 
                                        class="ai-tool-call"
                                        :class="{ 
                                            'ai-tool-running': tool.status === 'running',
                                            'ai-tool-completed': tool.status === 'completed' 
                                        }"
                                    >
                                        <span class="ai-tool-icon">
                                            <span v-if="tool.status === 'running'" class="ai-tool-spinner"></span>
                                            <span v-else>✓</span>
                                        </span>
                                        <span class="ai-tool-name">{{ tool.name }}</span>
                                    </div>
                                </div>

                                <!-- Streaming response -->
                                <div v-if="isStreaming && currentResponse" class="ai-message ai-message-assistant">
                                    <div class="ai-message-role">AI</div>
                                    <div class="ai-message-content"><span v-html="renderMarkdown(currentResponse)"></span><span class="ai-cursor">|</span></div>
                                </div>

                                <!-- Thinking indicator -->
                                <div v-if="isStreaming && isThinking && !currentResponse && activeToolCalls.length === 0" class="ai-thinking">
                                    <span class="ai-thinking-icon">🤔</span>
                                    <span>Thinking...</span>
                                </div>

                                <!-- Loading indicator (legacy fallback) -->
                                <div v-else-if="isStreaming && !currentResponse && activeToolCalls.length === 0" class="ai-loading">
                                    <span class="ai-loading-dot"></span>
                                    <span class="ai-loading-dot"></span>
                                    <span class="ai-loading-dot"></span>
                                </div>

                                <!-- Error -->
                                <div v-if="aiError" class="ai-error">
                                    {{ aiError }}
                                </div>

                                <!-- Empty state -->
                                <div v-if="!aiMessages.length && !isStreaming && !aiError" class="ai-mode-message">
                                    <div class="ai-icon">✨</div>
                                    <p>Ask me anything about your projects, tasks, or clients</p>
                                    <p class="ai-hint">Press Enter to send your question</p>
                                </div>
                            </div>

                            <!-- Command Results -->
                            <template v-else>
                                <!-- Loading indicator -->
                                <div v-if="isLoadingObjects && results.length === 0" class="search-loading">
                                    <span class="loading-spinner"></span>
                                    Searching...
                                </div>

                                <!-- No results - show AI suggestion -->
                                <div v-else-if="results.length === 0 && query && shouldShowAISuggestion" class="no-results-ai-suggestion">
                                    <div class="no-results-icon">🤔</div>
                                    <p class="no-results-title">No commands or items found</p>
                                    <p class="no-results-hint">Your query looks like a question.</p>
                                    <button class="ai-suggestion-button" @click="switchToAIMode">
                                        <span class="ai-suggestion-icon">✨</span>
                                        Ask AI instead
                                    </button>
                                </div>

                                <!-- No results - generic -->
                                <div v-else-if="results.length === 0 && query" class="no-results">
                                    No commands or items found for "{{ query }}"
                                </div>

                                <!-- Results grouped by category -->
                                <template v-for="(commands, category) in groupedResults" :key="category">
                                    <div v-if="commands.length > 0" class="command-group">
                                        <div class="command-group-label">
                                            {{ getCategoryLabel(category) }}
                                        </div>
                                        <div class="command-list">
                                            <button
                                                v-for="(command, idx) in commands"
                                                :key="command.id"
                                                class="command-item"
                                                :class="{ 'command-item-selected': results.indexOf(command) === selectedIndex }"
                                                @click="handleItemClick(command, results.indexOf(command))"
                                                @mouseenter="selectedIndex = results.indexOf(command)"
                                            >
                                                <span class="command-item-icon">{{ getIcon(command.icon) }}</span>
                                                <div class="command-item-content">
                                                    <span class="command-item-name">{{ command.name }}</span>
                                                    <span class="command-item-desc">{{ command.description }}</span>
                                                </div>
                                                <kbd v-if="command.shortcut" class="command-item-shortcut">
                                                    {{ command.shortcut }}
                                                </kbd>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </template>
                        </div>

                        <!-- Footer -->
                        <div class="command-footer">
                            <div class="command-footer-hints">
                                <template v-if="isAIMode">
                                    <span><kbd>↑↓</kbd> History</span>
                                    <span><kbd>↵</kbd> Send</span>
                                    <span><kbd>ESC</kbd> Close</span>
                                </template>
                                <template v-else>
                                    <span><kbd>↑↓</kbd> Navigate</span>
                                    <span><kbd>↵</kbd> Select</span>
                                    <span><kbd>ESC</kbd> Close</span>
                                </template>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.command-palette-backdrop {
    position: fixed;
    inset: 0;
    z-index: 200;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 15vh;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
}

.command-palette {
    width: 100%;
    max-width: 640px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    overflow: hidden;
}

.command-input-wrapper {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.command-icon {
    font-size: 1.25rem;
    color: var(--color-accent);
}

.command-input {
    flex: 1;
    background: transparent;
    border: none;
    outline: none;
    font-size: 1rem;
    color: var(--color-text-primary);
}

.command-input::placeholder {
    color: var(--color-text-tertiary);
}

.command-hint {
    padding: 0.25rem 0.5rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 4px;
    font-size: 0.7rem;
    color: var(--color-text-tertiary);
    font-family: inherit;
}

.command-results {
    max-height: 400px;
    overflow-y: auto;
    padding: 0.5rem;
}

.command-group {
    margin-bottom: 0.5rem;
}

.command-group-label {
    padding: 0.5rem 0.75rem;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
}

.command-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.command-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.625rem 0.75rem;
    background: transparent;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.1s ease;
    text-align: left;
}

.command-item:hover,
.command-item-selected {
    background: var(--color-bg-tertiary);
}

.command-item-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.command-item-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    min-width: 0;
}

.command-item-name {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.command-item-desc {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.command-item-shortcut {
    padding: 0.125rem 0.375rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-subtle);
    border-radius: 4px;
    font-size: 0.65rem;
    color: var(--color-text-tertiary);
    font-family: inherit;
}

.search-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 2rem;
    color: var(--color-text-secondary);
    font-size: 0.875rem;
}

.loading-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid var(--color-border-default);
    border-top-color: var(--color-accent);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.no-results {
    padding: 2rem;
    text-align: center;
    color: var(--color-text-tertiary);
}

.no-results-ai-suggestion {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 2rem;
    text-align: center;
}

.no-results-icon {
    font-size: 2.5rem;
    margin-bottom: 1rem;
}

.no-results-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
}

.no-results-hint {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 1.5rem;
}

.ai-suggestion-button {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    background: var(--color-accent);
    border: none;
    border-radius: 8px;
    color: white;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}

.ai-suggestion-button:hover {
    background: var(--color-accent-hover, #5558e3);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.ai-suggestion-icon {
    font-size: 1rem;
}

.ai-mode-message {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 2rem;
    text-align: center;
    color: var(--color-text-secondary);
}

.ai-icon {
    font-size: 2rem;
    margin-bottom: 1rem;
}

.ai-hint {
    margin-top: 0.5rem;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.ai-chat {
    padding: 0.5rem;
}

.ai-messages {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.ai-message {
    padding: 0.75rem;
    border-radius: 8px;
}

.ai-message-user {
    background: var(--color-bg-tertiary);
    margin-left: 2rem;
}

.ai-message-assistant {
    background: var(--color-accent-subtle, rgba(99, 102, 241, 0.1));
    margin-right: 2rem;
}

.ai-message-role {
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--color-text-tertiary);
    margin-bottom: 0.25rem;
}

.ai-message-content {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    line-height: 1.5;
    white-space: pre-wrap;
}

.ai-message-content :deep(.ai-code-inline) {
    padding: 0.125rem 0.375rem;
    background: var(--color-bg-tertiary);
    border-radius: 3px;
    font-family: var(--font-mono, monospace);
    font-size: 0.8em;
}

.ai-message-content :deep(.ai-code-block) {
    display: block;
    padding: 0.5rem 0.75rem;
    margin: 0.375rem 0;
    background: var(--color-bg-tertiary);
    border-radius: 6px;
    font-family: var(--font-mono, monospace);
    font-size: 0.8em;
    white-space: pre-wrap;
}

.ai-cursor {
    animation: blink 1s infinite;
}

@keyframes blink {
    0%, 50% { opacity: 1; }
    51%, 100% { opacity: 0; }
}

.ai-loading {
    display: flex;
    justify-content: center;
    gap: 0.25rem;
    padding: 1rem;
}

.ai-loading-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--color-accent);
    animation: pulse 1.4s infinite ease-in-out;
}

.ai-loading-dot:nth-child(1) { animation-delay: -0.32s; }
.ai-loading-dot:nth-child(2) { animation-delay: -0.16s; }

@keyframes pulse {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
    40% { transform: scale(1); opacity: 1; }
}

.ai-error {
    padding: 0.75rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 8px;
    color: #ef4444;
    font-size: 0.875rem;
}

.ai-tool-calls,
.ai-active-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.ai-tool-call {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.25rem 0.625rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 4px;
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.ai-tool-call.ai-tool-running {
    border-color: var(--color-accent);
    background: rgba(99, 102, 241, 0.1);
}

.ai-tool-call.ai-tool-completed {
    border-color: #22c55e;
    background: rgba(34, 197, 94, 0.1);
}

.ai-tool-icon {
    font-size: 0.75rem;
}

.ai-tool-completed .ai-tool-icon {
    color: #22c55e;
}

.ai-tool-name {
    font-family: var(--font-mono, monospace);
}

.ai-tool-spinner {
    width: 10px;
    height: 10px;
    border: 2px solid transparent;
    border-top-color: var(--color-accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.ai-thinking {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    color: var(--color-text-secondary);
    font-size: 0.875rem;
}

.ai-thinking-icon {
    animation: thinking-bob 1.5s ease-in-out infinite;
}

@keyframes thinking-bob {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-3px); }
}

.command-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 0.75rem 1rem;
    border-top: 1px solid var(--color-border-subtle);
    background: var(--color-bg-tertiary);
}

.command-footer-hints {
    display: flex;
    gap: 1rem;
    font-size: 0.7rem;
    color: var(--color-text-tertiary);
}

.command-footer-hints kbd {
    padding: 0.125rem 0.25rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-subtle);
    border-radius: 3px;
    font-family: inherit;
    margin-right: 0.25rem;
}
</style>
