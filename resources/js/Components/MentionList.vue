<script setup lang="ts">
import { ref, watch } from 'vue'

interface MentionItem {
    id: number
    label: string
    type: 'user' | 'project' | 'task' | 'client' | 'lead'
    url: string
}

const props = defineProps<{
    items: MentionItem[]
    command: (item: { id: number; label: string; type: string; url: string }) => void
}>()

const selectedIndex = ref(0)

watch(() => props.items, () => {
    selectedIndex.value = 0
})

const selectItem = (index: number) => {
    const item = props.items[index]
    if (item) {
        props.command({ id: item.id, label: item.label, type: item.type, url: item.url })
    }
}

const onKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowUp') {
        selectedIndex.value = (selectedIndex.value + props.items.length - 1) % props.items.length
        return true
    }
    if (event.key === 'ArrowDown') {
        selectedIndex.value = (selectedIndex.value + 1) % props.items.length
        return true
    }
    if (event.key === 'Enter') {
        selectItem(selectedIndex.value)
        return true
    }
    return false
}

defineExpose({ onKeyDown })

const getTypeIcon = (type: string) => {
    switch (type) {
        case 'user': return 'user'
        case 'project': return 'folder'
        case 'task': return 'task'
        case 'client': return 'building'
        case 'lead': return 'sparkle'
        default: return 'hash'
    }
}

const getTypeColor = (type: string) => {
    switch (type) {
        case 'user': return 'var(--color-accent)'
        case 'project': return 'var(--color-status-blue)'
        case 'task': return 'var(--color-status-green)'
        case 'client': return 'var(--color-status-orange)'
        case 'lead': return 'var(--color-status-yellow)'
        default: return 'var(--color-text-tertiary)'
    }
}
</script>

<template>
    <div class="mention-list">
        <template v-if="items.length">
            <button
                v-for="(item, index) in items"
                :key="item.id"
                :class="['mention-item', { 'is-selected': index === selectedIndex }]"
                @click="selectItem(index)"
            >
                <div class="mention-icon" :style="{ background: getTypeColor(item.type) }">
                    <svg v-if="item.type === 'user'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <svg v-else-if="item.type === 'project'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                    </svg>
                    <svg v-else-if="item.type === 'task'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                    </svg>
                    <svg v-else-if="item.type === 'client'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <svg v-else-if="item.type === 'lead'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                    </svg>
                </div>
                <span class="mention-label">{{ item.label }}</span>
                <span class="mention-type">{{ item.type }}</span>
            </button>
        </template>
        <div v-else class="mention-empty">
            No results found
        </div>
    </div>
</template>

<style scoped>
.mention-list {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.25);
    overflow: hidden;
    min-width: 200px;
    max-width: 320px;
    max-height: 280px;
    overflow-y: auto;
}

.mention-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 8px 12px;
    background: none;
    border: none;
    text-align: left;
    cursor: pointer;
    transition: background 0.1s ease;
}

.mention-item:hover,
.mention-item.is-selected {
    background: var(--color-bg-tertiary);
}

.mention-icon {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.mention-label {
    flex: 1;
    font-size: 13px;
    color: var(--color-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.mention-type {
    font-size: 11px;
    color: var(--color-text-quaternary);
    text-transform: capitalize;
}

.mention-empty {
    padding: 12px;
    text-align: center;
    color: var(--color-text-tertiary);
    font-size: 13px;
}
</style>
