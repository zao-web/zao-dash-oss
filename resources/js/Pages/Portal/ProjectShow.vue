<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import FormSelect from '@/Components/FormSelect.vue'
import RichTextEditor from '@/Components/RichTextEditor.vue'
import { ref, computed, nextTick } from 'vue'

interface Assignee {
    id: number | null
    name: string
    type: string
}

interface Task {
    id: number
    title: string
    status: 'pending' | 'in_progress' | 'review' | 'completed'
    priority: 'low' | 'medium' | 'high' | 'urgent'
    milestone_id: number | null
    assignee: Assignee | null
    due_date: string | null
    estimated_hours: number | null
}

interface Comment {
    id: number
    type: string
    content: string
    metadata: Record<string, any> | null
    user: { id: number | null; name: string }
    reactions: { emoji: string; count: number; users: string[] }[]
    created_at: string
}

interface Milestone {
    id: number
    name: string
    description: string | null
    status: string
    due_date: string | null
    completed_at: string | null
    tasks_count: number
    completed_tasks_count: number
}

interface TeamMember {
    id: number
    name: string
}

interface ClientContact {
    id: number
    name: string
}

interface Project {
    id: number
    name: string
    description: string | null
    status: string
    progress: number
    start_date: string | null
    start_date_raw: string | null
    due_date: string | null
    end_date_raw: string | null
    timeline_status: string | null
    tasks: Task[]
    milestones: Milestone[]
}

interface Stats {
    total_tasks: number
    completed_tasks: number
    in_progress_tasks: number
    pending_tasks: number
    review_tasks: number
}

interface Props {
    project: Project
    stats: Stats
    team: TeamMember[]
    clientContacts: ClientContact[]
}

const props = defineProps<Props>()

const activeTab = ref<'board' | 'milestones'>('board')

// Task detail state
const selectedTask = ref<Task | null>(null)
const taskComments = ref<Comment[]>([])
const taskLoading = ref(false)
const newComment = ref('')
const commentSubmitting = ref(false)
const assigneeUpdating = ref(false)
const commentEditor = ref<InstanceType<typeof RichTextEditor> | null>(null)

const statusColumns = [
    { key: 'pending', label: 'To Do', color: 'bg-gray-400', bgColor: 'bg-gray-50' },
    { key: 'in_progress', label: 'In Progress', color: 'bg-blue-500', bgColor: 'bg-blue-50' },
    { key: 'review', label: 'In Review', color: 'bg-purple-500', bgColor: 'bg-purple-50' },
    { key: 'completed', label: 'Done', color: 'bg-green-500', bgColor: 'bg-green-50' },
]

const tasksByStatus = computed(() => {
    const grouped: Record<string, Task[]> = {}
    statusColumns.forEach(col => grouped[col.key] = [])
    props.project.tasks.forEach(t => {
        if (grouped[t.status]) grouped[t.status].push(t)
    })
    return grouped
})

const assigneeOptions = computed(() => {
    const options: { value: string | number; label: string; group?: string }[] = [
        { value: '', label: 'Unassigned' },
    ]
    props.team.forEach(m => {
        options.push({ value: `user:${m.id}`, label: m.name, group: 'Zao Team' })
    })
    props.clientContacts.forEach(c => {
        options.push({ value: `client_contact:${c.id}`, label: c.name, group: 'Your Team' })
    })
    return options
})

const selectedAssigneeValue = computed(() => {
    if (!selectedTask.value?.assignee?.id) return ''
    return `${selectedTask.value.assignee.type}:${selectedTask.value.assignee.id}`
})

const openTask = async (task: Task) => {
    selectedTask.value = task
    taskComments.value = []
    taskLoading.value = true

    try {
        const response = await fetch(`/portal/api/tasks/${task.id}`)
        if (response.ok) {
            const data = await response.json()
            taskComments.value = data.task.comments
            // Update task details from API (may have fresher data)
            if (data.task.assignee) {
                selectedTask.value = { ...task, assignee: data.task.assignee }
            }
        }
    } finally {
        taskLoading.value = false
        await nextTick()
        const el = document.querySelector('.comment-scroll')
        if (el) el.scrollTop = el.scrollHeight
    }
}

const closeTask = () => {
    selectedTask.value = null
    taskComments.value = []
    newComment.value = ''
}

const submitComment = async () => {
    const content = newComment.value.trim()
    if (!content || content === '<p></p>' || !selectedTask.value) return
    commentSubmitting.value = true

    try {
        const response = await fetch(`/portal/api/tasks/${selectedTask.value.id}/comments`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
            },
            body: JSON.stringify({ content }),
        })

        if (response.ok) {
            const data = await response.json()
            taskComments.value.push(data.comment)
            newComment.value = ''
            commentEditor.value?.clearContent()
            await nextTick()
            const el = document.querySelector('.comment-scroll')
            if (el) el.scrollTop = el.scrollHeight
        }
    } finally {
        commentSubmitting.value = false
    }
}

const updateAssignee = async (value: string | number | null) => {
    if (!selectedTask.value) return
    assigneeUpdating.value = true

    const strValue = String(value || '')
    let assigneeId: number | null = null
    let assigneeType = 'user'

    if (strValue && strValue.includes(':')) {
        const [type, id] = strValue.split(':')
        assigneeType = type
        assigneeId = parseInt(id)
    }

    try {
        const response = await fetch(`/portal/api/tasks/${selectedTask.value.id}/assign`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
            },
            body: JSON.stringify({
                assigned_to: assigneeId,
                assignee_type: assigneeId ? assigneeType : null,
            }),
        })

        if (response.ok) {
            const data = await response.json()
            selectedTask.value = { ...selectedTask.value, assignee: data.assignee }
            // Update the task in the project list too
            const idx = props.project.tasks.findIndex(t => t.id === selectedTask.value!.id)
            if (idx !== -1) {
                props.project.tasks[idx].assignee = data.assignee
            }
        }
    } finally {
        assigneeUpdating.value = false
    }
}

const priorityColor = (priority: string) => {
    const colors: Record<string, string> = {
        urgent: 'border-l-red-500',
        high: 'border-l-orange-500',
        medium: 'border-l-yellow-400',
        low: 'border-l-gray-300',
    }
    return colors[priority] || 'border-l-gray-300'
}

const priorityBadge = (priority: string) => {
    const configs: Record<string, { label: string; class: string }> = {
        urgent: { label: 'Urgent', class: 'bg-red-100 text-red-700' },
        high: { label: 'High', class: 'bg-orange-100 text-orange-700' },
        medium: { label: 'Medium', class: 'bg-yellow-100 text-yellow-700' },
        low: { label: 'Low', class: 'bg-gray-100 text-gray-600' },
    }
    return configs[priority] || configs.medium
}

const statusBadge = (status: string) => {
    const configs: Record<string, { label: string; class: string }> = {
        pending: { label: 'To Do', class: 'bg-gray-100 text-gray-700' },
        in_progress: { label: 'In Progress', class: 'bg-blue-100 text-blue-700' },
        review: { label: 'In Review', class: 'bg-purple-100 text-purple-700' },
        completed: { label: 'Done', class: 'bg-green-100 text-green-700' },
    }
    return configs[status] || configs.pending
}

const statusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-green-100 text-green-800',
        in_progress: 'bg-blue-100 text-blue-800',
        completed: 'bg-gray-100 text-gray-800',
        pending: 'bg-orange-100 text-orange-800',
    }
    return colors[status] || 'bg-gray-100 text-gray-800'
}

const timelineStatusConfig = computed(() => {
    const status = props.project.timeline_status
    const configs: Record<string, { label: string; color: string; bg: string }> = {
        ahead: { label: 'Ahead of Schedule', color: 'text-green-700', bg: 'bg-green-100' },
        on_track: { label: 'On Track', color: 'text-blue-700', bg: 'bg-blue-100' },
        at_risk: { label: 'At Risk', color: 'text-amber-700', bg: 'bg-amber-100' },
        behind: { label: 'Behind Schedule', color: 'text-red-700', bg: 'bg-red-100' },
    }
    return status ? configs[status] : null
})

const milestoneProgress = (milestone: Milestone) => {
    if (milestone.tasks_count === 0) return 0
    return Math.round((milestone.completed_tasks_count / milestone.tasks_count) * 100)
}
</script>

<template>
    <Head :title="project.name" />

    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow-sm">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <div class="flex items-center gap-4">
                    <a href="/portal/projects" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    <div class="flex-1">
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-bold text-gray-900">{{ project.name }}</h1>
                            <span :class="statusColor(project.status)" class="text-xs px-2.5 py-1 rounded-full font-medium">
                                {{ project.status.replace('_', ' ') }}
                            </span>
                            <span v-if="timelineStatusConfig" :class="[timelineStatusConfig.bg, timelineStatusConfig.color]" class="text-xs px-2.5 py-1 rounded-full font-medium">
                                {{ timelineStatusConfig.label }}
                            </span>
                        </div>
                        <p v-if="project.description" class="text-sm text-gray-500 mt-1">{{ project.description }}</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-6">
            <!-- Stats Bar -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-sm p-4 text-center">
                    <div class="text-2xl font-bold text-gray-900">{{ stats.total_tasks }}</div>
                    <div class="text-xs text-gray-500 mt-1">Total Tasks</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ stats.in_progress_tasks }}</div>
                    <div class="text-xs text-gray-500 mt-1">In Progress</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-4 text-center">
                    <div class="text-2xl font-bold text-purple-600">{{ stats.review_tasks }}</div>
                    <div class="text-xs text-gray-500 mt-1">In Review</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-4 text-center">
                    <div class="text-2xl font-bold text-green-600">{{ stats.completed_tasks }}</div>
                    <div class="text-xs text-gray-500 mt-1">Completed</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-4 text-center col-span-2 sm:col-span-1">
                    <div class="text-2xl font-bold text-gray-900">{{ project.progress }}%</div>
                    <div class="text-xs text-gray-500 mt-1">Complete</div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                <div class="flex justify-between text-sm text-gray-500 mb-2">
                    <span>Overall Progress</span>
                    <div class="flex gap-4 text-xs">
                        <span v-if="project.start_date">Started: {{ project.start_date }}</span>
                        <span v-if="project.due_date">Due: {{ project.due_date }}</span>
                    </div>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <div
                        class="bg-blue-600 h-3 rounded-full transition-all"
                        :style="{ width: `${project.progress}%` }"
                    />
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="flex gap-1 bg-white rounded-lg shadow-sm p-1 mb-6">
                <button
                    v-for="tab in [
                        { key: 'board', label: 'Board' },
                        { key: 'milestones', label: 'Milestones' },
                    ]"
                    :key="tab.key"
                    @click="activeTab = tab.key as 'board' | 'milestones'"
                    :class="[
                        'flex-1 py-2 px-4 text-sm font-medium rounded-md transition-colors',
                        activeTab === tab.key
                            ? 'bg-blue-50 text-blue-700'
                            : 'text-gray-500 hover:text-gray-700',
                    ]"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Board View -->
            <div v-if="activeTab === 'board'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div
                    v-for="column in statusColumns"
                    :key="column.key"
                    :class="column.bgColor"
                    class="rounded-lg p-3 min-h-[200px]"
                >
                    <div class="flex items-center gap-2 mb-3 px-1">
                        <div :class="column.color" class="w-2.5 h-2.5 rounded-full" />
                        <h3 class="text-sm font-semibold text-gray-700">{{ column.label }}</h3>
                        <span class="text-xs text-gray-400 ml-auto">
                            {{ tasksByStatus[column.key]?.length || 0 }}
                        </span>
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="task in tasksByStatus[column.key]"
                            :key="task.id"
                            :class="priorityColor(task.priority)"
                            class="bg-white rounded-lg shadow-sm p-3 border-l-3 cursor-pointer hover:shadow-md transition-shadow"
                            @click="openTask(task)"
                        >
                            <div class="text-sm font-medium text-gray-900 mb-2">
                                {{ task.title }}
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span v-if="task.assignee" class="inline-flex items-center gap-1 text-gray-500">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    {{ task.assignee.name }}
                                </span>
                                <span v-if="task.due_date" class="inline-flex items-center gap-1 text-gray-500">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    {{ task.due_date }}
                                </span>
                                <span
                                    v-if="task.priority === 'urgent' || task.priority === 'high'"
                                    :class="{
                                        'text-red-600': task.priority === 'urgent',
                                        'text-orange-600': task.priority === 'high',
                                    }"
                                    class="font-medium"
                                >
                                    {{ priorityBadge(task.priority).label }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div v-if="!tasksByStatus[column.key]?.length" class="text-center text-xs text-gray-400 py-8">
                        No tasks
                    </div>
                </div>
            </div>

            <!-- Milestones View -->
            <div v-if="activeTab === 'milestones'" class="space-y-4">
                <div
                    v-for="milestone in project.milestones"
                    :key="milestone.id"
                    class="bg-white rounded-lg shadow-sm overflow-hidden"
                >
                    <div class="p-5">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h3 class="font-semibold text-gray-900 text-lg">{{ milestone.name }}</h3>
                                <p v-if="milestone.description" class="text-sm text-gray-500 mt-1">
                                    {{ milestone.description }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span v-if="milestone.due_date" class="text-xs text-gray-500">
                                    Due {{ milestone.due_date }}
                                </span>
                                <span :class="statusColor(milestone.status)" class="text-xs px-2.5 py-1 rounded-full font-medium">
                                    {{ milestone.status }}
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <div class="flex-1 bg-gray-200 rounded-full h-2">
                                <div
                                    class="bg-green-500 h-2 rounded-full transition-all"
                                    :style="{ width: `${milestoneProgress(milestone)}%` }"
                                />
                            </div>
                            <span class="text-xs text-gray-500 whitespace-nowrap">
                                {{ milestone.completed_tasks_count }} / {{ milestone.tasks_count }} tasks
                            </span>
                        </div>

                        <div v-if="milestone.tasks_count > 0" class="mt-4 space-y-1.5">
                            <div
                                v-for="task in project.tasks.filter(t => t.milestone_id === milestone.id)"
                                :key="task.id"
                                class="flex items-center gap-3 px-3 py-2 rounded-lg hover:bg-gray-50 cursor-pointer"
                                @click="openTask(task)"
                            >
                                <div :class="{
                                    'text-green-500': task.status === 'completed',
                                    'text-blue-500': task.status === 'in_progress',
                                    'text-purple-500': task.status === 'review',
                                    'text-gray-300': task.status === 'pending',
                                }">
                                    <svg v-if="task.status === 'completed'" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <circle cx="12" cy="12" r="9" />
                                    </svg>
                                </div>
                                <span :class="{ 'line-through text-gray-400': task.status === 'completed' }" class="flex-1 text-sm text-gray-700">
                                    {{ task.title }}
                                </span>
                                <span v-if="task.assignee" class="text-xs text-gray-400">
                                    {{ task.assignee.name }}
                                </span>
                                <span v-if="task.due_date" class="text-xs text-gray-400">
                                    {{ task.due_date }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="!project.milestones.length" class="bg-white rounded-lg shadow-sm p-8 text-center text-gray-500">
                    No milestones defined yet
                </div>
            </div>
        </main>

        <!-- Task Detail Slide-over -->
        <Teleport to="body">
            <Transition name="slideover">
                <div v-if="selectedTask" class="fixed inset-0 z-50 flex justify-end" @click.self="closeTask">
                    <div class="fixed inset-0 bg-black/30" @click="closeTask" />
                    <div class="relative w-full max-w-lg bg-white shadow-xl flex flex-col h-full portal-light-vars">
                        <!-- Header -->
                        <div class="px-6 py-4 border-b border-gray-200 shrink-0">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 pr-4">
                                    <h2 class="text-lg font-semibold text-gray-900">{{ selectedTask.title }}</h2>
                                    <div class="flex items-center gap-2 mt-2">
                                        <span :class="statusBadge(selectedTask.status).class" class="text-xs px-2 py-0.5 rounded-full font-medium">
                                            {{ statusBadge(selectedTask.status).label }}
                                        </span>
                                        <span :class="priorityBadge(selectedTask.priority).class" class="text-xs px-2 py-0.5 rounded-full font-medium">
                                            {{ priorityBadge(selectedTask.priority).label }}
                                        </span>
                                        <span v-if="selectedTask.due_date" class="text-xs text-gray-500">
                                            Due {{ selectedTask.due_date }}
                                        </span>
                                    </div>
                                </div>
                                <button @click="closeTask" class="text-gray-400 hover:text-gray-600 p-1">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Assignee -->
                        <div class="px-6 py-3 border-b border-gray-100 shrink-0">
                            <label class="text-xs font-medium text-gray-500 mb-1 block">Assigned To</label>
                            <FormSelect
                                :modelValue="selectedAssigneeValue"
                                :options="assigneeOptions"
                                placeholder="Unassigned"
                                size="sm"
                                searchable
                                :disabled="assigneeUpdating"
                                @update:modelValue="updateAssignee"
                            />
                        </div>

                        <!-- Comments -->
                        <div class="flex-1 overflow-y-auto comment-scroll px-6 py-4">
                            <h3 class="text-sm font-medium text-gray-700 mb-3">Comments</h3>

                            <div v-if="taskLoading" class="space-y-3">
                                <div v-for="i in 3" :key="i" class="animate-pulse">
                                    <div class="h-3 bg-gray-200 rounded w-24 mb-2" />
                                    <div class="h-4 bg-gray-200 rounded w-full" />
                                </div>
                            </div>

                            <div v-else-if="taskComments.length === 0" class="text-center py-8 text-gray-400 text-sm">
                                No comments yet. Start the conversation!
                            </div>

                            <div v-else class="space-y-4">
                                <div
                                    v-for="comment in taskComments"
                                    :key="comment.id"
                                    :class="comment.type === 'comment' ? '' : 'opacity-60'"
                                    class="text-sm"
                                >
                                    <div v-if="comment.type === 'comment'">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="font-medium text-gray-900">{{ comment.user.name }}</span>
                                            <span class="text-xs text-gray-400">{{ comment.created_at }}</span>
                                        </div>
                                        <div class="text-gray-700 bg-gray-50 rounded-lg px-3 py-2" v-html="comment.content" />
                                    </div>
                                    <div v-else class="flex items-center gap-2 text-xs text-gray-500 py-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span v-html="comment.content" />
                                        <span class="text-gray-400">{{ comment.created_at }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Comment Input -->
                        <div class="px-6 py-4 border-t border-gray-200 shrink-0">
                            <RichTextEditor
                                ref="commentEditor"
                                v-model="newComment"
                                placeholder="Write a comment... Use @ to mention someone"
                                min-height="60px"
                                mention-url="/portal/api/mentions/search"
                                @submit="submitComment"
                            />
                            <div class="flex justify-end mt-2">
                                <button
                                    type="button"
                                    @click="submitComment"
                                    :disabled="!newComment.trim() || newComment === '<p></p>' || commentSubmitting"
                                    class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                >
                                    {{ commentSubmitting ? 'Sending...' : 'Send' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<style scoped>
/* Force light-mode CSS variables for shared components (FormSelect, RichTextEditor)
   that rely on --color-* vars which default to dark theme values */
.portal-light-vars {
    --color-bg-primary: #ffffff;
    --color-bg-secondary: #f9fafb;
    --color-bg-tertiary: #f3f4f6;
    --color-bg-elevated: #e5e7eb;
    --color-border-subtle: rgba(0, 0, 0, 0.06);
    --color-border-default: rgba(0, 0, 0, 0.1);
    --color-border-hover: rgba(0, 0, 0, 0.15);
    --color-border-strong: rgba(0, 0, 0, 0.15);
    --color-text-primary: #111827;
    --color-text-secondary: #4b5563;
    --color-text-tertiary: #6b7280;
    --color-text-quaternary: #9ca3af;
    --color-accent: #7c3aed;
    --color-accent-hover: #6d28d9;
    --color-status-green: #16a34a;
    --color-status-yellow: #ca8a04;
    --color-status-blue: #2563eb;
    --color-status-red: #dc2626;
    --color-status-orange: #ea580c;
}

.slideover-enter-active,
.slideover-leave-active {
    transition: all 0.3s ease;
}
.slideover-enter-active > div:last-child,
.slideover-leave-active > div:last-child {
    transition: transform 0.3s ease;
}
.slideover-enter-from > div:last-child,
.slideover-leave-to > div:last-child {
    transform: translateX(100%);
}
.slideover-enter-from > div:first-child,
.slideover-leave-to > div:first-child {
    opacity: 0;
}
</style>
