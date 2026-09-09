<script setup lang="ts">
import { ref, watch } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Placeholder from '@tiptap/extension-placeholder'
import Mention from '@tiptap/extension-mention'
import { VueRenderer } from '@tiptap/vue-3'
import tippy, { Instance as TippyInstance } from 'tippy.js'
import MentionList from './MentionList.vue'

interface MentionItem {
    id: number
    label: string
    type: 'user' | 'project' | 'task' | 'client' | 'lead'
    url: string
}

const props = withDefaults(defineProps<{
    modelValue: string
    placeholder?: string
    minHeight?: string
    mentionUrl?: string
}>(), {
    placeholder: 'Write a comment...',
    minHeight: '80px',
    mentionUrl: '/api/mentions/search',
})

const emit = defineEmits<{
    'update:modelValue': [value: string]
    'submit': []
}>()

const mentionItems = ref<MentionItem[]>([])
const loadingMentions = ref(false)

const fetchMentions = async (query: string): Promise<MentionItem[]> => {
    if (!query) return []
    loadingMentions.value = true

    try {
        const response = await fetch(`${props.mentionUrl}?q=${encodeURIComponent(query)}`)
        const data = await response.json()
        return data.items || []
    } catch (e) {
        console.error('Failed to fetch mentions:', e)
        return []
    } finally {
        loadingMentions.value = false
    }
}

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit.configure({
            heading: false,
            codeBlock: false,
        }),
        Placeholder.configure({
            placeholder: props.placeholder,
        }),
        Mention.extend({
            addAttributes() {
                return {
                    id: { default: null },
                    label: { default: null },
                    type: { default: 'user' },
                    url: { default: '#' },
                }
            },
        }).configure({
            HTMLAttributes: {
                class: 'mention',
            },
            renderHTML({ options, node }) {
                return [
                    'a',
                    {
                        ...options.HTMLAttributes,
                        href: node.attrs.url || '#',
                        'data-mention-type': node.attrs.type,
                        'data-mention-id': node.attrs.id,
                    },
                    `@${node.attrs.label ?? node.attrs.id}`,
                ]
            },
            suggestion: {
                items: async ({ query }) => {
                    return await fetchMentions(query)
                },
                render: () => {
                    let component: VueRenderer
                    let popup: TippyInstance[]

                    return {
                        onStart: (props) => {
                            component = new VueRenderer(MentionList, {
                                props,
                                editor: props.editor,
                            })

                            if (!props.clientRect) return

                            popup = tippy('body', {
                                getReferenceClientRect: props.clientRect as () => DOMRect,
                                appendTo: () => document.body,
                                content: component.element,
                                showOnCreate: true,
                                interactive: true,
                                trigger: 'manual',
                                placement: 'bottom-start',
                            })
                        },
                        onUpdate: (props) => {
                            component.updateProps(props)

                            if (!props.clientRect) return

                            popup[0].setProps({
                                getReferenceClientRect: props.clientRect as () => DOMRect,
                            })
                        },
                        onKeyDown: (props) => {
                            if (props.event.key === 'Escape') {
                                popup[0].hide()
                                return true
                            }
                            return component.ref?.onKeyDown(props.event)
                        },
                        onExit: () => {
                            popup[0].destroy()
                            component.destroy()
                        },
                    }
                },
            },
        }),
    ],
    editorProps: {
        attributes: {
            class: 'editor-content',
            style: `min-height: ${props.minHeight}`,
        },
    },
    onUpdate: ({ editor }) => {
        emit('update:modelValue', editor.getHTML())
    },
})

watch(() => props.modelValue, (value) => {
    if (editor.value && editor.value.getHTML() !== value) {
        editor.value.commands.setContent(value, false)
    }
})

const handleKeyDown = (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        e.preventDefault()
        emit('submit')
    }
}

const clearContent = () => {
    editor.value?.commands.clearContent()
}

const focus = () => {
    editor.value?.commands.focus()
}

defineExpose({ clearContent, focus })
</script>

<template>
    <div class="rich-editor" @keydown="handleKeyDown">
        <!-- Toolbar -->
        <div v-if="editor" class="editor-toolbar">
            <button
                type="button"
                :class="['toolbar-btn', { active: editor.isActive('bold') }]"
                @click="editor.chain().focus().toggleBold().run()"
                title="Bold (Ctrl+B)"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h8a4 4 0 014 4 4 4 0 01-4 4H6z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 12h9a4 4 0 014 4 4 4 0 01-4 4H6z" />
                </svg>
            </button>
            <button
                type="button"
                :class="['toolbar-btn', { active: editor.isActive('italic') }]"
                @click="editor.chain().focus().toggleItalic().run()"
                title="Italic (Ctrl+I)"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 4h4m-2 0v16m-4 0h8" transform="skewX(-10)" />
                </svg>
            </button>
            <button
                type="button"
                :class="['toolbar-btn', { active: editor.isActive('strike') }]"
                @click="editor.chain().focus().toggleStrike().run()"
                title="Strikethrough"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 4H9a3 3 0 000 6h6a3 3 0 010 6H8m8 0v2M8 10V8" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12h16" />
                </svg>
            </button>
            <div class="toolbar-divider"></div>
            <button
                type="button"
                :class="['toolbar-btn', { active: editor.isActive('bulletList') }]"
                @click="editor.chain().focus().toggleBulletList().run()"
                title="Bullet List"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <button
                type="button"
                :class="['toolbar-btn', { active: editor.isActive('orderedList') }]"
                @click="editor.chain().focus().toggleOrderedList().run()"
                title="Numbered List"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h10M7 16h10M3 8h.01M3 12h.01M3 16h.01" />
                </svg>
            </button>
            <button
                type="button"
                :class="['toolbar-btn', { active: editor.isActive('code') }]"
                @click="editor.chain().focus().toggleCode().run()"
                title="Inline Code"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                </svg>
            </button>
            <div class="toolbar-hint">
                <span class="hint-text">@ to mention</span>
            </div>
        </div>

        <!-- Editor -->
        <EditorContent :editor="editor" class="editor-wrapper" />
    </div>
</template>

<style scoped>
.rich-editor {
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    overflow: hidden;
}

.rich-editor:focus-within {
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
}

.editor-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 6px 8px;
    border-bottom: 1px solid var(--color-border-subtle);
    background: var(--color-bg-secondary);
}

.toolbar-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: none;
    border: none;
    border-radius: 4px;
    color: var(--color-text-tertiary);
    cursor: pointer;
    transition: all 0.1s ease;
}

.toolbar-btn:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.toolbar-btn.active {
    background: var(--color-accent);
    color: white;
}

.toolbar-divider {
    width: 1px;
    height: 20px;
    background: var(--color-border-default);
    margin: 0 4px;
}

.toolbar-hint {
    margin-left: auto;
}

.hint-text {
    font-size: 11px;
    color: var(--color-text-quaternary);
}

.editor-wrapper {
    padding: 12px;
}

:deep(.editor-content) {
    outline: none;
    font-size: 14px;
    line-height: 1.5;
    color: var(--color-text-primary);
}

:deep(.editor-content p) {
    margin: 0 0 0.5em;
}

:deep(.editor-content p:last-child) {
    margin-bottom: 0;
}

:deep(.editor-content ul),
:deep(.editor-content ol) {
    padding-left: 1.5em;
    margin: 0.5em 0;
}

:deep(.editor-content code) {
    background: var(--color-bg-secondary);
    border-radius: 4px;
    padding: 2px 4px;
    font-family: 'SF Mono', Consolas, monospace;
    font-size: 0.9em;
}

:deep(.editor-content .mention) {
    background: rgba(139, 92, 246, 0.15);
    border-radius: 4px;
    padding: 1px 4px;
    color: var(--color-accent);
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.15s ease;
}

:deep(.editor-content .mention:hover) {
    background: rgba(139, 92, 246, 0.25);
}

:deep(.ProseMirror p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    color: var(--color-text-quaternary);
    pointer-events: none;
    float: left;
    height: 0;
}
</style>
