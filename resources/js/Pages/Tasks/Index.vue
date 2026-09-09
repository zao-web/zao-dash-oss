<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormTextarea from '@/Components/FormTextarea.vue'
import FormCheckbox from '@/Components/FormCheckbox.vue'
import InlineSelect from '@/Components/InlineSelect.vue'
import RichTextEditor from '@/Components/RichTextEditor.vue'
import { PlusIcon, DocumentIcon, ExternalLinkIcon, TrashIcon, VideoIcon, EditIcon, RefreshIcon, CheckIcon, UserIcon, ChevronIcon, PlayIcon, CloseIcon, ArrowRightIcon, CpuIcon } from '@/Components/Icons'
import draggable from 'vuedraggable'
import { marked } from 'marked'
import { Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, reactive, watch, onMounted, onUnmounted } from 'vue'
import { triggerConfetti, triggerPoof } from '@/composables/useAnimations'
import { getPreference, savePreference } from '@/composables/usePreferences'

interface TaskVideo {
    id: number
    title: string
    duration: number | null
    view_count: number
    status: 'processing' | 'ready' | 'failed'
    share_url: string
    thumbnail_url: string | null
    created_at: string
}

interface Reaction {
    emoji: string
    count: number
    users: string[]
    user_ids: number[]
}

interface TaskComment {
    id: number
    type: 'comment' | 'status_change' | 'assignment' | 'system' | 'external'
    content: string
    metadata: Record<string, any> | null
    user: { id: number | null; name: string }
    reactions: Reaction[]
    created_at: string
}

interface Assignee {
    id: number
    name: string
    type: 'user' | 'agent' | 'client_contact'
}

interface Task {
    id: number
    title: string
    description: string | null
    status: 'pending' | 'in_progress' | 'review' | 'completed'
    priority: 'low' | 'medium' | 'high' | 'urgent'
    position: number
    project: { id: number; name: string; slug: string; client_id: number; client_name: string } | null
    assignee: Assignee | null
    due_date: string | null
    estimated_hours?: number
    source: string
    external_url: string | null
    external_platform: string | null
    created_at: string
    videos?: TaskVideo[]
    metadata?: {
        preview_url?: string
        pr_url?: string
        cloud_environment_id?: string
        preview_branch?: string
        preview_status?: string
    } | null
}

interface Project {
    id: number
    name: string
    slug: string
    client_id: number
    client_name: string
}

interface Stats {
    total: number
    pending: number
    in_progress: number
    review: number
    completed_today: number
    overdue: number
}

const props = defineProps<{
    tasks: Task[]
    stats: Stats
    team: { id: number; name: string }[]
    projects?: { id: number; name: string; client_id: number; client_name: string }[]
    clients?: { id: number; name: string }[]
    agents?: { id: number; name: string; slug: string }[]
    clientContacts?: { id: number; name: string; client_id: number; client_name: string }[]
}>()

const viewMode = ref<'list' | 'board'>(getPreference('tasks.viewMode', 'list'))
const filterStatus = ref<string>('all')

// Save view mode when changed
watch(viewMode, (newMode) => {
    savePreference('tasks.viewMode', newMode)
})
const filterAssignee = ref<string>('all')
const filterPriority = ref<string>('all')
const filterClient = ref<string>('all')
const filterProject = ref<string>('all')

// Modal State
const showTaskModal = ref(false)
const showTaskDetailModal = ref(false)
const isEditing = ref(false)
const selectedTask = ref<Task | null>(null)
const isSubmitting = ref(false)
const taskVideos = ref<TaskVideo[]>([])
const loadingVideos = ref(false)
const deletingVideoId = ref<number | null>(null)
const taskComments = ref<TaskComment[]>([])
const newComment = ref('')
const submittingComment = ref(false)
const deletingCommentId = ref<number | null>(null)
const richEditorRef = ref<InstanceType<typeof RichTextEditor> | null>(null)
const showEmojiPicker = ref<number | null>(null)
const currentUserId = computed(() => (usePage().props.auth as any)?.user?.id)

const quickEmojis = ['👍', '❤️', '🎉', '😄', '🤔', '👀']

// Dev Agent state
const triggeringDevAgent = ref(false)
const devAgentResult = ref<{ success: boolean; message: string } | null>(null)

const taskForm = reactive({
    title: '',
    description: '',
    status: 'pending',
    priority: 'medium',
    project_id: null as number | null,
    assigned_to: null as number | null,
    assignee_type: null as string | null,
    due_date: '',
    estimated_hours: '',
})

const resetForm = () => {
    taskForm.title = ''
    taskForm.description = ''
    taskForm.status = 'pending'
    taskForm.priority = 'medium'
    taskForm.project_id = null
    taskForm.assigned_to = null
    taskForm.assignee_type = null
    taskForm.due_date = ''
    taskForm.estimated_hours = ''
}

const openNewTaskModal = (status?: string) => {
    isEditing.value = false
    selectedTask.value = null
    resetForm()
    if (status) taskForm.status = status
    showTaskModal.value = true
}

const openEditTaskModal = (task: Task) => {
    isEditing.value = true
    selectedTask.value = task
    taskForm.title = task.title
    taskForm.description = task.description || ''
    taskForm.status = task.status
    taskForm.priority = task.priority
    taskForm.project_id = task.project?.id || null
    taskForm.assigned_to = task.assignee?.id || null
    taskForm.assignee_type = task.assignee?.type || null
    taskForm.due_date = task.due_date || ''
    taskForm.estimated_hours = task.estimated_hours?.toString() || ''
    showTaskModal.value = true
}

const openTaskDetail = async (task: Task, pushState = true) => {
    selectedTask.value = task
    showTaskDetailModal.value = true
    taskVideos.value = []
    taskComments.value = []
    newComment.value = ''
    loadingVideos.value = true

    if (pushState) {
        history.pushState({ modal: 'task', taskId: task.id }, '', `#task-${task.id}`)
    }

    try {
        const response = await fetch(`/api/tasks/${task.id}`)
        const data = await response.json()
        taskVideos.value = data.task.videos || []
        taskComments.value = data.task.comments || []
    } catch (e) {
        console.error('Failed to load task data:', e)
    } finally {
        loadingVideos.value = false
    }
}

const closeTaskDetail = (goBack = false) => {
    showTaskDetailModal.value = false
    selectedTask.value = null
    taskVideos.value = []
    taskComments.value = []

    if (goBack && window.location.hash.startsWith('#task-')) {
        history.back()
    } else if (window.location.hash.startsWith('#task-')) {
        history.replaceState({}, '', window.location.pathname)
    }
}

const formatDuration = (seconds: number | null) => {
    if (!seconds) return '0:00'
    const mins = Math.floor(seconds / 60)
    const secs = seconds % 60
    return `${mins}:${secs.toString().padStart(2, '0')}`
}

const triggerRecordVideo = (taskId: number) => {
    console.log('[Zao Dash] Triggering record video for task:', taskId)
    // Send message to Chrome extension to start recording with this task
    window.postMessage({
        type: 'ZAO_RECORD_VIDEO',
        taskId: taskId,
    }, '*')
    console.log('[Zao Dash] postMessage sent')
}

const deleteVideo = async (videoId: number, event: MouseEvent) => {
    if (!confirm('Delete this video?')) return
    deletingVideoId.value = videoId

    // Get the video element for poof animation
    const videoElement = (event.target as HTMLElement).closest('.video-item') as HTMLElement

    try {
        const response = await fetch(`/api/videos/${videoId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        })
        if (response.ok) {
            // Poof animation before removing
            if (videoElement) {
                await triggerPoof(videoElement)
            }
            taskVideos.value = taskVideos.value.filter(v => v.id !== videoId)
        }
    } catch (e) {
        console.error('Failed to delete video:', e)
    } finally {
        deletingVideoId.value = null
    }
}

const isCommentEmpty = computed(() => {
    // Strip HTML tags and check if there's actual content
    const text = newComment.value.replace(/<[^>]*>/g, '').trim()
    return !text
})

const submitComment = async () => {
    if (!selectedTask.value || isCommentEmpty.value) return
    submittingComment.value = true

    try {
        const response = await fetch(`/tasks/${selectedTask.value.id}/comments`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ content: newComment.value }),
        })
        if (response.ok) {
            const data = await response.json()
            taskComments.value.unshift(data.comment)
            newComment.value = ''
            richEditorRef.value?.clearContent()
        }
    } catch (e) {
        console.error('Failed to add comment:', e)
    } finally {
        submittingComment.value = false
    }
}

const toggleReaction = async (comment: TaskComment, emoji: string) => {
    if (!selectedTask.value) return
    showEmojiPicker.value = null

    // Optimistic update
    const existingReaction = comment.reactions.find(r => r.emoji === emoji)
    const userId = currentUserId.value

    if (existingReaction) {
        if (existingReaction.user_ids.includes(userId)) {
            // Remove user's reaction
            existingReaction.count--
            existingReaction.user_ids = existingReaction.user_ids.filter(id => id !== userId)
            if (existingReaction.count === 0) {
                comment.reactions = comment.reactions.filter(r => r.emoji !== emoji)
            }
        } else {
            // Add user to existing reaction
            existingReaction.count++
            existingReaction.user_ids.push(userId)
        }
    } else {
        // New reaction
        comment.reactions.push({
            emoji,
            count: 1,
            users: [],
            user_ids: [userId],
        })
    }

    try {
        const response = await fetch(`/tasks/${selectedTask.value.id}/comments/${comment.id}/react`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ emoji }),
        })
        if (response.ok) {
            const data = await response.json()
            comment.reactions = data.reactions
        }
    } catch (e) {
        console.error('Failed to toggle reaction:', e)
    }
}

const hasUserReacted = (reaction: Reaction): boolean => {
    return reaction.user_ids.includes(currentUserId.value)
}

const deleteComment = async (commentId: number) => {
    if (!selectedTask.value) return
    deletingCommentId.value = commentId

    try {
        const response = await fetch(`/tasks/${selectedTask.value.id}/comments/${commentId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        })
        if (response.ok) {
            taskComments.value = taskComments.value.filter(c => c.id !== commentId)
        }
    } catch (e) {
        console.error('Failed to delete comment:', e)
    } finally {
        deletingCommentId.value = null
    }
}

const getCommentIcon = (type: string) => {
    switch (type) {
        case 'status_change': return 'status'
        case 'assignment': return 'user'
        default: return 'comment'
    }
}

const triggerDevAgent = async () => {
    if (!selectedTask.value) return
    triggeringDevAgent.value = true
    devAgentResult.value = null

    try {
        const response = await fetch('/agents/dev/trigger', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                prompt: `Work on task: ${selectedTask.value.title}\n\n${selectedTask.value.description || 'No additional description.'}`,
                context: {
                    task_id: selectedTask.value.id,
                    project_id: selectedTask.value.project?.id,
                },
            }),
        })

        if (response.ok) {
            devAgentResult.value = {
                success: true,
                message: 'Dev Agent started! Check the Agents page for progress.',
            }
            // Refresh to show updated task status
            setTimeout(() => {
                router.reload()
            }, 2000)
        } else {
            const data = await response.json()
            devAgentResult.value = {
                success: false,
                message: data.message || 'Failed to trigger Dev Agent',
            }
        }
    } catch (e) {
        devAgentResult.value = {
            success: false,
            message: 'Network error. Please try again.',
        }
    } finally {
        triggeringDevAgent.value = false
    }
}

// Composite value for the create/edit form assignee select
const formAssigneeComposite = computed({
    get: () => {
        if (!taskForm.assigned_to || !taskForm.assignee_type) return null
        return buildAssigneeValue(taskForm.assignee_type, taskForm.assigned_to)
    },
    set: (value: string | null) => {
        const { type, id } = parseAssigneeValue(value)
        taskForm.assigned_to = id
        taskForm.assignee_type = type
    },
})

const submitTask = () => {
    isSubmitting.value = true
    const url = isEditing.value ? `/tasks/${selectedTask.value?.id}` : '/tasks'
    const method = isEditing.value ? 'put' : 'post'

    router[method](url, {
        ...taskForm,
        estimated_hours: parseInt(taskForm.estimated_hours) || null,
    }, {
        onSuccess: () => {
            showTaskModal.value = false
            resetForm()
        },
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

const updateTaskStatus = (task: Task, newStatus: string, event?: MouseEvent) => {
    // Update local state optimistically
    if (selectedTask.value && selectedTask.value.id === task.id) {
        selectedTask.value = { ...selectedTask.value, status: newStatus as Task['status'] }
    }

    // Confetti when completing a task!
    if (newStatus === 'completed' && task.status !== 'completed') {
        triggerConfetti(event)
    }

    router.post(`/tasks/${task.id}/status`, {
        status: newStatus,
    }, {
        preserveScroll: true,
    })
}

const updateTaskPriority = (task: Task, newPriority: string) => {
    // Update local state optimistically
    if (selectedTask.value && selectedTask.value.id === task.id) {
        selectedTask.value = { ...selectedTask.value, priority: newPriority as Task['priority'] }
    }

    router.post(`/tasks/${task.id}/priority`, {
        priority: newPriority,
    }, {
        preserveScroll: true,
    })
}

const renderSystemComment = (content: string): string => {
    return marked.parse(content, { async: false }) as string
}

/**
 * Parse a composite assignee value like "user:5" or "agent:3" into its parts.
 */
const parseAssigneeValue = (value: string | null): { type: string | null; id: number | null } => {
    if (!value) return { type: null, id: null }
    const [type, idStr] = value.split(':')
    return { type, id: parseInt(idStr) }
}

/**
 * Build a composite assignee value from type and id.
 */
const buildAssigneeValue = (type: string, id: number): string => `${type}:${id}`

/**
 * Resolve an assignee name from the composite value for optimistic updates.
 */
const resolveAssigneeName = (type: string, id: number): string | null => {
    if (type === 'user') return props.team.find(m => m.id === id)?.name ?? null
    if (type === 'agent') return (props.agents ?? []).find(a => a.id === id)?.name ?? null
    if (type === 'client_contact') return (props.clientContacts ?? []).find(c => c.id === id)?.name ?? null
    return null
}

const updateTaskAssignee = (task: Task, compositeValue: string | null) => {
    const { type, id } = parseAssigneeValue(compositeValue)

    // Update local state optimistically
    if (selectedTask.value && selectedTask.value.id === task.id) {
        if (id && type) {
            const name = resolveAssigneeName(type, id)
            selectedTask.value = {
                ...selectedTask.value,
                assignee: name ? { id, name, type: type as Assignee['type'] } : null,
            }
        } else {
            selectedTask.value = { ...selectedTask.value, assignee: null }
        }
    }

    router.post(`/tasks/${task.id}/assign`, {
        assigned_to: id,
        assignee_type: type,
    }, {
        preserveScroll: true,
    })
}

// Options for inline selects
const statusColors: Record<string, string> = {
    pending: 'var(--color-text-tertiary)',
    in_progress: 'var(--color-status-blue)',
    review: 'var(--color-status-orange)',
    completed: 'var(--color-status-green)',
}

const statusSelectOptions = computed(() => [
    { value: 'pending', label: 'To Do', color: statusColors.pending },
    { value: 'in_progress', label: 'In Progress', color: statusColors.in_progress },
    { value: 'review', label: 'Review', color: statusColors.review },
    { value: 'completed', label: 'Completed', color: statusColors.completed },
])

const prioritySelectOptions = computed(() => [
    { value: 'low', label: 'Low', color: 'var(--color-text-secondary)' },
    { value: 'medium', label: 'Medium', color: 'var(--color-status-blue)' },
    { value: 'high', label: 'High', color: 'var(--color-status-orange)' },
    { value: 'urgent', label: 'Urgent', color: 'var(--color-status-red)' },
])

const assigneeSelectOptions = computed(() => {
    const options: { value: string | null; label: string; group?: string }[] = [
        { value: null, label: 'Unassigned' },
    ]

    // Team members
    props.team.forEach(m => {
        options.push({ value: buildAssigneeValue('user', m.id), label: m.name, group: 'Team' })
    })

    // Agents
    ;(props.agents ?? []).forEach(a => {
        options.push({ value: buildAssigneeValue('agent', a.id), label: a.name, group: 'Agents' })
    })

    // Client contacts — filter to the selected task's project client
    const taskClientId = selectedTask.value?.project?.client_id
    ;(props.clientContacts ?? [])
        .filter(c => !taskClientId || c.client_id === taskClientId)
        .forEach(c => {
            options.push({
                value: buildAssigneeValue('client_contact', c.id),
                label: `${c.name} (${c.client_name})`,
                group: 'Clients',
            })
        })

    return options
})

const deleteTask = () => {
    if (!selectedTask.value) return
    isSubmitting.value = true

    router.delete(`/tasks/${selectedTask.value.id}`, {
        onSuccess: () => {
            showTaskDetailModal.value = false
            showTaskModal.value = false
            selectedTask.value = null
        },
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

const projectOptions = computed(() =>
    (props.projects || []).map(p => ({ value: p.id, label: `${p.client_name} / ${p.name}` }))
)

const assigneeOptions = computed(() => {
    const options: { value: string; label: string; group?: string }[] = []

    // Team members
    props.team.forEach(t => {
        options.push({ value: buildAssigneeValue('user', t.id), label: t.name, group: 'Team' })
    })

    // Agents
    ;(props.agents ?? []).forEach(a => {
        options.push({ value: buildAssigneeValue('agent', a.id), label: a.name, group: 'Agents' })
    })

    // Client contacts — filter to the form's selected project client
    const formClientId = props.projects?.find(p => p.id === taskForm.project_id)?.client_id
    ;(props.clientContacts ?? [])
        .filter(c => !formClientId || c.client_id === formClientId)
        .forEach(c => {
            options.push({
                value: buildAssigneeValue('client_contact', c.id),
                label: `${c.name} (${c.client_name})`,
                group: 'Clients',
            })
        })

    return options
})

const statusOptions = [
    { value: 'pending', label: 'To Do' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'review', label: 'Review' },
    { value: 'completed', label: 'Completed' },
]

const priorityOptions = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'urgent', label: 'Urgent' },
]

// Filter dropdown options (include "All" option)
const filterStatusOptions = computed(() => [
    { value: 'all', label: 'All Status' },
    { value: 'pending', label: 'To Do' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'review', label: 'Review' },
    { value: 'completed', label: 'Completed' },
])

const filterAssigneeOptions = computed(() => {
    const options: { value: string; label: string }[] = [
        { value: 'all', label: 'All Assignees' },
    ]
    props.team.forEach(m => {
        options.push({ value: buildAssigneeValue('user', m.id), label: m.name })
    })
    ;(props.agents ?? []).forEach(a => {
        options.push({ value: buildAssigneeValue('agent', a.id), label: a.name })
    })
    ;(props.clientContacts ?? []).forEach(c => {
        options.push({ value: buildAssigneeValue('client_contact', c.id), label: `${c.name} (${c.client_name})` })
    })
    return options
})

const filterPriorityOptions = computed(() => [
    { value: 'all', label: 'All Priority' },
    { value: 'urgent', label: 'Urgent' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
])

const filterClientOptions = computed(() => [
    { value: 'all', label: 'All Clients' },
    ...(props.clients || []).map(c => ({ value: c.id, label: c.name })),
])

const filterProjectOptions = computed(() => {
    let projects = props.projects || []
    if (filterClient.value !== 'all') {
        projects = projects.filter(p => p.client_id === parseInt(filterClient.value))
    }
    return [
        { value: 'all', label: 'All Projects' },
        ...projects.map(p => ({ value: p.id, label: p.name })),
    ]
})

const filteredTasks = computed(() => {
    let result = props.tasks

    if (filterStatus.value !== 'all') {
        result = result.filter(t => t.status === filterStatus.value)
    }
    if (filterAssignee.value !== 'all') {
        const fv = parseAssigneeValue(filterAssignee.value)
        result = result.filter(t => t.assignee?.id === fv.id && (t.assignee?.type ?? 'user') === fv.type)
    }
    if (filterPriority.value !== 'all') {
        result = result.filter(t => t.priority === filterPriority.value)
    }
    if (filterClient.value !== 'all') {
        const clientProjects = props.projects?.filter(p => p.client_id === parseInt(filterClient.value)).map(p => p.id) || []
        result = result.filter(t => t.project && clientProjects.includes(t.project.id))
    }
    if (filterProject.value !== 'all') {
        result = result.filter(t => t.project?.id === parseInt(filterProject.value))
    }

    return result
})

// Local tasks state for optimistic drag-and-drop
const localTasks = ref<Task[]>([...props.tasks])

// Mutable arrays for each status that vuedraggable can modify directly
const statusArrays = reactive<Record<string, Task[]>>({})

// Status keys for iteration
const statusKeys = ['pending', 'in_progress', 'review', 'completed'] as const

// Get filtered tasks based on current filters
const getFilteredLocalTasks = () => {
    let result = localTasks.value

    if (filterClient.value !== 'all') {
        const clientProjects = props.projects?.filter(p => p.client_id === parseInt(filterClient.value)).map(p => p.id) || []
        result = result.filter(t => t.project && clientProjects.includes(t.project.id))
    }
    if (filterProject.value !== 'all') {
        result = result.filter(t => t.project?.id === parseInt(filterProject.value))
    }
    if (filterAssignee.value !== 'all') {
        const fv = parseAssigneeValue(filterAssignee.value)
        result = result.filter(t => t.assignee?.id === fv.id && (t.assignee?.type ?? 'user') === fv.type)
    }
    if (filterPriority.value !== 'all') {
        result = result.filter(t => t.priority === filterPriority.value)
    }

    return result
}

// Initialize status arrays from filtered tasks
const initStatusArrays = () => {
    const filtered = getFilteredLocalTasks()
    for (const status of statusKeys) {
        statusArrays[status] = filtered
            .filter(t => t.status === status)
            .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
    }
}

// Initialize on mount
initStatusArrays()

// Sync with props when they change (e.g., after server response)
watch(() => props.tasks, (newTasks) => {
    localTasks.value = [...newTasks]
    initStatusArrays()
}, { deep: true })

// Re-init when filters change
watch([filterClient, filterProject, filterAssignee, filterPriority], () => {
    initStatusArrays()
})

const localFilteredTasks = computed(() => {
    let result = localTasks.value

    if (filterClient.value !== 'all') {
        const clientProjects = props.projects?.filter(p => p.client_id === parseInt(filterClient.value)).map(p => p.id) || []
        result = result.filter(t => t.project && clientProjects.includes(t.project.id))
    }
    if (filterProject.value !== 'all') {
        result = result.filter(t => t.project?.id === parseInt(filterProject.value))
    }
    if (filterStatus.value !== 'all') {
        result = result.filter(t => t.status === filterStatus.value)
    }
    if (filterAssignee.value !== 'all') {
        const fv = parseAssigneeValue(filterAssignee.value)
        result = result.filter(t => t.assignee?.id === fv.id && (t.assignee?.type ?? 'user') === fv.type)
    }
    if (filterPriority.value !== 'all') {
        result = result.filter(t => t.priority === filterPriority.value)
    }

    return result
})

// Computed for board view - groups filtered tasks by status
const filteredTasksByStatus = computed(() => {
    const result: Record<string, Task[]> = {}
    for (const status of statusKeys) {
        result[status] = localFilteredTasks.value
            .filter(t => t.status === status)
            .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
    }
    return result
})

// Computed for reading (used in template)
const tasksByStatus = computed(() => statusArrays)

// Drag handler for optimistic updates
const onTaskDragChange = (evt: any, targetStatus: string) => {
    // Only process if item was moved or added (not removed - that's handled by the target column)
    if (!evt.moved && !evt.added) {
        return
    }

    const movedTask = evt.moved?.element || evt.added?.element
    if (!movedTask) return

    // Confetti when dragging to completed!
    if (evt.added && targetStatus === 'completed') {
        // Get position of the completed column for confetti origin
        const completedColumn = document.querySelector('[data-status="completed"]')
        if (completedColumn) {
            const rect = completedColumn.getBoundingClientRect()
            triggerConfetti({ x: rect.left + rect.width / 2, y: rect.top + 100 })
        } else {
            triggerConfetti()
        }
    }

    // If added from another column, update the task's status in the element
    if (evt.added) {
        movedTask.status = targetStatus
    }

    // Collect all tasks to update - use the current order in statusArrays (which vuedraggable has mutated)
    const tasksToUpdate: { id: number; position: number; status: string }[] = []

    for (const status of statusKeys) {
        const statusTasks = statusArrays[status] || []
        statusTasks.forEach((task, index) => {
            // Update position in the task object
            task.position = index
            task.status = status as Task['status']

            tasksToUpdate.push({
                id: task.id,
                position: index,
                status: status
            })
        })
    }

    // Also update localTasks to stay in sync
    localTasks.value = statusKeys.flatMap(s => statusArrays[s] || [])

    // Send all positions to server
    router.post('/tasks/reorder', { tasks: tasksToUpdate }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            // Revert on error
            localTasks.value = [...props.tasks]
            initStatusArrays()
        }
    })
}

const getInitials = (name: string) => {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getAssigneeAvatarStyle = (assignee: Assignee) => {
    const type = assignee.type ?? 'user'
    if (type === 'agent') {
        return { background: 'var(--color-status-purple, #8b5cf6)' }
    }
    if (type === 'client_contact') {
        return { background: 'var(--color-status-orange, #f59e0b)' }
    }
    return { background: `hsl(${assignee.id * 40}, 60%, 50%)` }
}

const getAssigneeLabel = (assignee: Assignee) => {
    const type = assignee.type ?? 'user'
    if (type === 'agent') return 'AI'
    return getInitials(assignee.name)
}

const platformNames: Record<string, string> = {
    clickup: 'ClickUp',
    notion: 'Notion',
    asana: 'Asana',
}

const getPlatformName = (platform: string | null) => {
    return platform ? (platformNames[platform] || platform) : 'External'
}

const statusLabels: Record<string, string> = {
    pending: 'To Do',
    in_progress: 'In Progress',
    review: 'Review',
    completed: 'Done'
}

// History state management
const handleHashNavigation = () => {
    const hash = window.location.hash
    if (hash.startsWith('#task-')) {
        const taskId = parseInt(hash.replace('#task-', ''), 10)
        const task = props.tasks.find(t => t.id === taskId)
        if (task && !showTaskDetailModal.value) {
            openTaskDetail(task, false)
        }
    } else if (showTaskDetailModal.value) {
        // Close modal without navigation
        showTaskDetailModal.value = false
        selectedTask.value = null
        taskVideos.value = []
        taskComments.value = []
    }
}

const handlePopState = () => {
    handleHashNavigation()
}

onMounted(() => {
    handleHashNavigation()
    window.addEventListener('popstate', handlePopState)
})

onUnmounted(() => {
    window.removeEventListener('popstate', handlePopState)
})
</script>

<template>
    <AppLayout title="Tasks">
        <!-- Stats -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6 mb-6">
            <div class="metric-card">
                <div class="metric-label">TOTAL</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TO DO</div>
                <div class="metric-value" style="color: var(--color-text-tertiary);">{{ stats.pending }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">IN PROGRESS</div>
                <div class="metric-value" style="color: var(--color-status-blue);">{{ stats.in_progress }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">REVIEW</div>
                <div class="metric-value" style="color: var(--color-accent);">{{ stats.review }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">DONE TODAY</div>
                <div class="metric-value" style="color: var(--color-status-green);">{{ stats.completed_today }}</div>
            </div>
            <div v-if="stats.overdue > 0" class="metric-card">
                <div class="metric-label">OVERDUE</div>
                <div class="metric-value" style="color: var(--color-status-red);">{{ stats.overdue }}</div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar mb-4">
            <div class="toolbar-left">
                <div class="view-toggle">
                    <button :class="['toggle-btn', { active: viewMode === 'list' }]" @click="viewMode = 'list'">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </button>
                    <button :class="['toggle-btn', { active: viewMode === 'board' }]" @click="viewMode = 'board'">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <rect x="2" y="2" width="4" height="12" rx="1" stroke="currentColor" stroke-width="1.5"/>
                            <rect x="10" y="2" width="4" height="8" rx="1" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>
                </div>

                <div class="hidden sm:flex items-center gap-3">
                    <InlineSelect
                        v-model="filterClient"
                        :options="filterClientOptions"
                        :searchable="filterClientOptions.length > 10"
                    />

                    <InlineSelect
                        v-model="filterProject"
                        :options="filterProjectOptions"
                        :searchable="filterProjectOptions.length > 10"
                    />

                    <InlineSelect
                        v-model="filterStatus"
                        :options="filterStatusOptions"
                    />

                    <InlineSelect
                        v-model="filterAssignee"
                        :options="filterAssigneeOptions"
                        searchable
                    />

                    <InlineSelect
                        v-model="filterPriority"
                        :options="filterPriorityOptions"
                    />
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Link href="/invoices/create" class="btn btn-secondary hidden sm:flex">
                    <DocumentIcon :size="16" />
                    Invoice
                </Link>
                <button class="btn btn-primary" @click="openNewTaskModal()">
                    <PlusIcon :size="16" />
                    <span class="hidden sm:inline">New Task</span>
                    <span class="sm:hidden">New</span>
                </button>
            </div>
        </div>

        <!-- Mobile Filters -->
        <div class="sm:hidden flex items-center gap-2 mb-4 overflow-x-auto pb-2">
            <InlineSelect
                v-model="filterClient"
                :options="filterClientOptions"
                :searchable="filterClientOptions.length > 10"
            />
            <InlineSelect
                v-model="filterProject"
                :options="filterProjectOptions"
                :searchable="filterProjectOptions.length > 10"
            />
            <InlineSelect
                v-model="filterStatus"
                :options="filterStatusOptions"
            />
            <InlineSelect
                v-model="filterAssignee"
                :options="filterAssigneeOptions"
                searchable
            />
            <InlineSelect
                v-model="filterPriority"
                :options="filterPriorityOptions"
            />
        </div>

        <!-- List View -->
        <div v-if="viewMode === 'list'" class="card">
            <div class="task-table">
                <!-- Desktop table header -->
                <div class="table-header hidden md:grid">
                    <div class="col-check"></div>
                    <div class="col-status">Status</div>
                    <div class="col-task">Task</div>
                    <div class="col-project">Project</div>
                    <div class="col-priority">Priority</div>
                    <div class="col-assignee">Assignee</div>
                    <div class="col-due">Due</div>
                </div>

                <!-- Desktop rows -->
                <div
                    v-for="task in filteredTasks"
                    :key="task.id"
                    class="table-row hidden md:grid"
                    @click="openTaskDetail(task)"
                >
                    <div class="col-check" @click.stop="(e: MouseEvent) => { if (task.status !== 'completed') updateTaskStatus(task, 'completed', e); else updateTaskStatus(task, 'pending', e); }">
                        <FormCheckbox
                            :model-value="task.status === 'completed'"
                        />
                    </div>
                    <div class="col-status">
                        <div class="status-dot" :style="{ background: statusColors[task.status] }"></div>
                    </div>
                    <div class="col-task">
                        <div class="task-title-row">
                            <div :class="['task-title', { 'task-completed': task.status === 'completed' }]">{{ task.title }}</div>
                            <a v-if="task.external_url" :href="task.external_url" target="_blank" @click.stop class="external-link" :title="`Open in ${getPlatformName(task.external_platform)}`">
                                <ExternalLinkIcon :size="16" />
                            </a>
                        </div>
                        <div v-if="task.description" class="task-desc">{{ task.description }}</div>
                    </div>
                    <div class="col-project">
                        <Link v-if="task.project" :href="`/clients/${task.project.client_slug}/projects/${task.project.slug}`" class="project-link" @click.stop>
                            <span class="project-client">{{ task.project.client_name }}</span>
                            <span class="project-name">{{ task.project.name }}</span>
                        </Link>
                        <span v-else class="no-project">No project</span>
                    </div>
                    <div class="col-priority">
                        <span :class="['priority-badge', `priority-${task.priority}`]">{{ task.priority }}</span>
                    </div>
                    <div class="col-assignee">
                        <div v-if="task.assignee" class="assignee">
                            <div class="avatar avatar-sm" :style="getAssigneeAvatarStyle(task.assignee)">
                                {{ getAssigneeLabel(task.assignee) }}
                            </div>
                            <span class="assignee-name">{{ task.assignee.name }}</span>
                        </div>
                        <span v-else class="unassigned">Unassigned</span>
                    </div>
                    <div class="col-due">
                        <span v-if="task.due_date" class="due-date">{{ task.due_date }}</span>
                        <span v-else class="no-due">—</span>
                    </div>
                </div>

                <!-- Mobile rows -->
                <div
                    v-for="task in filteredTasks"
                    :key="`mobile-${task.id}`"
                    class="mobile-task-row md:hidden"
                    @click="openTaskDetail(task)"
                >
                    <div class="flex items-start gap-3">
                        <div class="mt-1" @click.stop="(e: MouseEvent) => { if (task.status !== 'completed') updateTaskStatus(task, 'completed', e); else updateTaskStatus(task, 'pending', e); }">
                            <FormCheckbox
                                :model-value="task.status === 'completed'"
                            />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <div class="status-dot" :style="{ background: statusColors[task.status] }"></div>
                                <div :class="['task-title flex-1 truncate', { 'task-completed': task.status === 'completed' }]">{{ task.title }}</div>
                                <span :class="['priority-badge', `priority-${task.priority}`]">{{ task.priority }}</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs" style="color: var(--color-text-tertiary)">
                                <span v-if="task.project">{{ task.project.client_name }} / {{ task.project.name }}</span>
                                <span v-if="task.assignee" class="flex items-center gap-1">
                                    <div class="avatar avatar-xs" :style="getAssigneeAvatarStyle(task.assignee)">
                                        {{ getAssigneeLabel(task.assignee) }}
                                    </div>
                                    {{ task.assignee.name }}
                                </span>
                                <span v-if="task.due_date">Due {{ task.due_date }}</span>
                            </div>
                        </div>
                        <ChevronIcon :size="16" direction="right" class="flex-shrink-0 mt-1" style="color: var(--color-text-quaternary)" />
                    </div>
                </div>

                <div v-if="filteredTasks.length === 0" class="empty-state">
                    No tasks match the current filters
                </div>
            </div>
        </div>

        <!-- Board View -->
        <div v-if="viewMode === 'board'" class="board">
            <div
                v-for="(tasks, key) in tasksByStatus"
                :key="key"
                class="board-column"
                :data-status="key"
            >
                <div class="column-header">
                    <div class="column-title">
                        <div class="status-dot" :style="{ background: statusColors[key] }"></div>
                        {{ statusLabels[key] }}
                    </div>
                    <span class="column-count">{{ tasks.length }}</span>
                </div>
                <draggable
                    :list="tasks"
                    group="tasks"
                    item-key="id"
                    class="column-cards"
                    ghost-class="task-ghost"
                    drag-class="task-dragging"
                    :animation="150"
                    @change="(evt: any) => onTaskDragChange(evt, key as string)"
                >
                    <template #item="{ element: task }">
                        <div class="task-card" @click="openTaskDetail(task)">
                            <div class="card-header">
                                <span :class="['priority-dot', `priority-${task.priority}`]"></span>
                                <span v-if="task.due_date" class="card-due">{{ task.due_date }}</span>
                                <a v-if="task.external_url" :href="task.external_url" target="_blank" @click.stop class="card-external-link" :title="`Open in ${getPlatformName(task.external_platform)}`">
                                    <ExternalLinkIcon :size="12" />
                                </a>
                            </div>
                            <div class="card-title">{{ task.title }}</div>
                            <div v-if="task.project" class="card-project">
                                {{ task.project.client_name }} / {{ task.project.name }}
                            </div>
                            <div class="card-footer">
                                <div v-if="task.assignee" class="avatar avatar-xs" :style="getAssigneeAvatarStyle(task.assignee)">
                                    {{ getAssigneeLabel(task.assignee) }}
                                </div>
                                <span class="card-source">{{ task.source }}</span>
                            </div>
                        </div>
                    </template>
                    <template #footer>
                        <button class="add-task-btn" @click="openNewTaskModal(key as string)">
                            <PlusIcon :size="14" />
                            Add task
                        </button>
                    </template>
                </draggable>
            </div>
        </div>

        <!-- New/Edit Task Modal -->
        <Modal
            :show="showTaskModal"
            :title="isEditing ? 'Edit Task' : 'New Task'"
            size="md"
            @close="showTaskModal = false"
        >
            <form @submit.prevent="submitTask" class="space-y-4">
                <FormInput
                    v-model="taskForm.title"
                    label="Task Title"
                    placeholder="What needs to be done?"
                    required
                />

                <FormTextarea
                    v-model="taskForm.description"
                    label="Description"
                    placeholder="Add more details..."
                    :rows="3"
                />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormSelect
                        v-model="taskForm.status"
                        label="Status"
                        :options="statusOptions"
                    />
                    <FormSelect
                        v-model="taskForm.priority"
                        label="Priority"
                        :options="priorityOptions"
                    />
                </div>

                <FormSelect
                    v-model="taskForm.project_id"
                    label="Project"
                    :options="projectOptions"
                    placeholder="Select a project (optional)"
                />

                <FormSelect
                    v-model="formAssigneeComposite"
                    label="Assignee"
                    :options="assigneeOptions"
                    placeholder="Select an assignee (optional)"
                    searchable
                />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormInput
                        v-model="taskForm.due_date"
                        label="Due Date"
                        type="date"
                    />
                    <FormInput
                        v-model="taskForm.estimated_hours"
                        label="Estimated Hours"
                        type="number"
                        placeholder="0"
                        suffix="hrs"
                    />
                </div>
            </form>

            <template #footer>
                <button class="btn btn-secondary" @click="showTaskModal = false">
                    Cancel
                </button>
                <button
                    class="btn btn-primary"
                    :disabled="isSubmitting || !taskForm.title"
                    @click="submitTask"
                >
                    {{ isSubmitting ? 'Saving...' : (isEditing ? 'Save Changes' : 'Create Task') }}
                </button>
            </template>
        </Modal>

        <!-- Task Detail Modal -->
        <Modal
            :show="showTaskDetailModal"
            :title="selectedTask?.title || 'Task Details'"
            size="lg"
            @close="closeTaskDetail(true)"
        >
            <div v-if="selectedTask" class="task-detail">
                <!-- Header Actions -->
                <div class="detail-actions">
                    <InlineSelect
                        :model-value="selectedTask.status"
                        :options="statusSelectOptions"
                        variant="status"
                        @update:model-value="(v: string) => updateTaskStatus(selectedTask!, v)"
                    />
                    <InlineSelect
                        :model-value="selectedTask.priority"
                        :options="prioritySelectOptions"
                        variant="status"
                        @update:model-value="(v: string) => updateTaskPriority(selectedTask!, v)"
                    />
                    <div class="flex-1"></div>
                    <button
                        class="btn btn-sm btn-secondary"
                        :disabled="triggeringDevAgent || selectedTask.status === 'completed'"
                        @click="triggerDevAgent"
                        :title="selectedTask.status === 'completed' ? 'Task already completed' : 'Assign to Dev Agent'"
                    >
                        <CpuIcon :size="16" />
                        {{ triggeringDevAgent ? 'Starting...' : 'Dev Agent' }}
                    </button>
                </div>

                <!-- Dev Agent Result -->
                <div
                    v-if="devAgentResult"
                    :class="['dev-agent-result', devAgentResult.success ? 'success' : 'error']"
                >
                    {{ devAgentResult.message }}
                </div>

                <!-- Description -->
                <div class="detail-section" v-if="selectedTask.description">
                    <h4 class="detail-label">Description</h4>
                    <p class="detail-text">{{ selectedTask.description }}</p>
                </div>

                <!-- Meta Grid -->
                <div class="detail-grid grid-cols-1 sm:grid-cols-2">
                    <div class="detail-item">
                        <h4 class="detail-label">Project</h4>
                        <Link v-if="selectedTask.project" :href="`/clients/${selectedTask.project.client_slug}/projects/${selectedTask.project.slug}`" class="detail-link">
                            {{ selectedTask.project.client_name }} / {{ selectedTask.project.name }}
                        </Link>
                        <span v-else class="detail-empty">No project</span>
                    </div>
                    <div class="detail-item">
                        <h4 class="detail-label">Assignee</h4>
                        <InlineSelect
                            :model-value="selectedTask.assignee ? buildAssigneeValue(selectedTask.assignee.type || 'user', selectedTask.assignee.id) : null"
                            :options="assigneeSelectOptions"
                            placeholder="Unassigned"
                            searchable
                            @update:model-value="(v: string | null) => updateTaskAssignee(selectedTask!, v)"
                        />
                    </div>
                    <div class="detail-item">
                        <h4 class="detail-label">Due Date</h4>
                        <span v-if="selectedTask.due_date" class="detail-text">{{ selectedTask.due_date }}</span>
                        <span v-else class="detail-empty">No due date</span>
                    </div>
                    <div class="detail-item">
                        <h4 class="detail-label">Source</h4>
                        <span class="detail-source">{{ selectedTask.source }}</span>
                    </div>
                </div>

                <!-- Preview Environment -->
                <div v-if="selectedTask.metadata?.preview_url || selectedTask.metadata?.pr_url" class="detail-section preview-section">
                    <h4 class="detail-label">Preview & Review</h4>
                    <div class="preview-links">
                        <a
                            v-if="selectedTask.metadata?.preview_url"
                            :href="selectedTask.metadata.preview_url"
                            target="_blank"
                            class="preview-link"
                        >
                            <ExternalLinkIcon :size="16" />
                            <span>Preview Environment</span>
                        </a>
                        <a
                            v-if="selectedTask.metadata?.pr_url"
                            :href="selectedTask.metadata.pr_url"
                            target="_blank"
                            class="preview-link pr-link"
                        >
                            <DocumentIcon :size="16" />
                            <span>Pull Request</span>
                        </a>
                    </div>
                    <p v-if="selectedTask.metadata?.preview_branch" class="preview-branch">
                        Branch: <code>{{ selectedTask.metadata.preview_branch }}</code>
                    </p>
                </div>

                <!-- Videos Section -->
                <div class="detail-section">
                    <div class="videos-header">
                        <h4 class="detail-label">Videos</h4>
                        <button
                            class="btn btn-sm btn-primary"
                            @click="triggerRecordVideo(selectedTask.id)"
                        >
                            <VideoIcon :size="16" />
                            Record Video
                        </button>
                    </div>

                    <div v-if="loadingVideos" class="videos-loading">
                        Loading videos...
                    </div>

                    <div v-else-if="taskVideos.length > 0" class="videos-grid">
                        <div
                            v-for="video in taskVideos"
                            :key="video.id"
                            class="video-item"
                        >
                            <a :href="video.share_url" target="_blank" class="video-link">
                                <div class="video-thumb">
                                    <img v-if="video.thumbnail_url" :src="video.thumbnail_url" :alt="video.title" />
                                    <div v-else class="video-thumb-placeholder">
                                        <PlayIcon :size="24" />
                                    </div>
                                    <span v-if="video.duration" class="video-duration">{{ formatDuration(video.duration) }}</span>
                                </div>
                                <div class="video-info">
                                    <span class="video-title">{{ video.title }}</span>
                                    <span class="video-meta">{{ video.view_count }} views · {{ video.created_at }}</span>
                                </div>
                            </a>
                            <button
                                class="video-delete-btn"
                                @click="deleteVideo(video.id, $event)"
                                :disabled="deletingVideoId === video.id"
                            >
                                <TrashIcon v-if="deletingVideoId !== video.id" :size="16" />
                                <span v-else class="loading-spinner"></span>
                            </button>
                        </div>
                    </div>

                    <div v-else class="videos-empty">
                        <p>No videos recorded for this task</p>
                        <p class="videos-empty-hint">Use the Chrome extension to record a video</p>
                    </div>
                </div>

                <!-- Activity/Comments -->
                <div class="detail-section">
                    <h4 class="detail-label">Activity</h4>

                    <!-- Add Comment Form -->
                    <div class="comment-form">
                        <RichTextEditor
                            ref="richEditorRef"
                            v-model="newComment"
                            placeholder="Add a comment... Use @ to mention"
                            min-height="60px"
                            @submit="submitComment"
                        />
                        <button
                            class="btn btn-sm btn-primary"
                            @click="submitComment"
                            :disabled="isCommentEmpty || submittingComment"
                        >
                            {{ submittingComment ? 'Posting...' : 'Post' }}
                        </button>
                    </div>

                    <!-- Comments List -->
                    <div class="activity-list">
                        <div
                            v-for="comment in taskComments"
                            :key="comment.id"
                            :class="['activity-item', `activity-${comment.type}`]"
                        >
                            <div :class="['activity-icon', `icon-${comment.type}`]">
                                <EditIcon v-if="comment.type === 'comment'" :size="16" />
                                <RefreshIcon v-else-if="comment.type === 'status_change'" :size="16" />
                                <UserIcon v-else-if="comment.type === 'assignment'" :size="16" />
                                <ExternalLinkIcon v-else-if="comment.type === 'external'" :size="16" />
                            </div>
                            <div class="activity-content">
                                <div class="activity-header">
                                    <span class="activity-user">{{ comment.user.name }}</span>
                                    <span v-if="comment.type === 'external' && comment.metadata?.platform" class="external-badge" :title="`From ${getPlatformName(comment.metadata.platform)}`">
                                        {{ getPlatformName(comment.metadata.platform) }}
                                    </span>
                                    <span class="activity-time">{{ comment.created_at }}</span>
                                    <button
                                        v-if="comment.type === 'comment'"
                                        class="activity-delete"
                                        @click="deleteComment(comment.id)"
                                        :disabled="deletingCommentId === comment.id"
                                    >
                                        <CloseIcon :size="12" />
                                    </button>
                                </div>
                                <div v-if="comment.type === 'comment' || comment.type === 'external'" class="activity-text rich-content" v-html="comment.content"></div>
                                <p v-else-if="comment.type === 'status_change' && comment.metadata" class="activity-text">
                                    Changed status from
                                    <span :class="['status-badge', `status-${comment.metadata.old_status}`]">
                                        {{ statusLabels[comment.metadata.old_status] || comment.metadata.old_status }}
                                    </span>
                                    to
                                    <span :class="['status-badge', `status-${comment.metadata.new_status}`]">
                                        {{ statusLabels[comment.metadata.new_status] || comment.metadata.new_status }}
                                    </span>
                                </p>
                                <div v-else-if="comment.type === 'system'" class="activity-text rich-content" v-html="renderSystemComment(comment.content)"></div>
                                <p v-else class="activity-text">{{ comment.content }}</p>

                                <!-- Reactions -->
                                <div v-if="comment.type === 'comment'" class="reactions-row">
                                    <div class="reactions-list">
                                        <button
                                            v-for="reaction in comment.reactions"
                                            :key="reaction.emoji"
                                            :class="['reaction-btn', { 'user-reacted': hasUserReacted(reaction) }]"
                                            @click="toggleReaction(comment, reaction.emoji)"
                                            :title="reaction.users.join(', ')"
                                        >
                                            <span class="reaction-emoji">{{ reaction.emoji }}</span>
                                            <span class="reaction-count">{{ reaction.count }}</span>
                                        </button>
                                    </div>
                                    <div class="add-reaction">
                                        <button
                                            class="add-reaction-btn"
                                            @click="showEmojiPicker = showEmojiPicker === comment.id ? null : comment.id"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </button>
                                        <div v-if="showEmojiPicker === comment.id" class="emoji-picker">
                                            <button
                                                v-for="emoji in quickEmojis"
                                                :key="emoji"
                                                class="emoji-option"
                                                @click="toggleReaction(comment, emoji)"
                                            >
                                                {{ emoji }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Show task created if no comments -->
                        <div class="activity-item activity-system">
                            <div class="activity-icon icon-system">
                                <PlusIcon :size="16" />
                            </div>
                            <div class="activity-content">
                                <div class="activity-header">
                                    <span class="activity-text">Task created</span>
                                    <span class="activity-time">{{ selectedTask.created_at }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <button
                    class="btn btn-ghost"
                    style="color: var(--color-status-red)"
                    @click="deleteTask"
                    :disabled="isSubmitting"
                >
                    Delete
                </button>
                <div class="flex-1"></div>
                <button class="btn btn-secondary" @click="showTaskDetailModal = false">
                    Close
                </button>
                <button
                    class="btn btn-primary"
                    @click="showTaskDetailModal = false; openEditTaskModal(selectedTask!)"
                >
                    Edit
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Toolbar */
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.toolbar-left {
    display: flex;
    gap: 12px;
    align-items: center;
}

.view-toggle {
    display: flex;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    overflow: hidden;
}

.toggle-btn {
    padding: 8px 12px;
    background: none;
    border: none;
    color: var(--color-text-tertiary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.toggle-btn.active {
    background: var(--color-status-blue);
    color: white;
}

.filter-select {
    padding: 8px 12px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    color: var(--color-text-primary);
    font-size: 13px;
}

/* Task Table */
.task-table {
    display: flex;
    flex-direction: column;
}

.table-header {
    display: grid;
    grid-template-columns: 32px 40px 2fr 1.5fr 90px 150px 100px;
    gap: 12px;
    padding: 12px 16px;
    background: var(--color-bg-tertiary);
    font-size: 11px;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
}

.table-row {
    display: grid;
    grid-template-columns: 32px 40px 2fr 1.5fr 90px 150px 100px;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-subtle);
    align-items: center;
    cursor: pointer;
}

.table-row:hover {
    background: var(--color-bg-tertiary);
}

.col-check :deep(.checkbox-group) {
    margin-bottom: 0;
}

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.task-title-row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.task-title {
    font-weight: 500;
    color: var(--color-text-primary);
    position: relative;
}

.task-title.task-completed {
    color: var(--color-text-tertiary);
}

.external-link {
    color: var(--color-text-quaternary);
    opacity: 0;
    transition: opacity 0.15s, color 0.15s;
}

.table-row:hover .external-link {
    opacity: 1;
}

.external-link:hover {
    color: var(--color-accent);
}

.task-title.task-completed::after {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    width: 100%;
    height: 1px;
    background: var(--color-text-tertiary);
    animation: strikethrough-draw 0.3s ease-out forwards;
}

@keyframes strikethrough-draw {
    from { width: 0; }
    to { width: 100%; }
}

.col-task {
    min-width: 0; /* Allow text to shrink and truncate */
    overflow: hidden;
}

.task-desc {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}

.project-link {
    display: flex;
    flex-direction: column;
    text-decoration: none;
    font-size: 13px;
}

.project-client {
    color: var(--color-text-tertiary);
    font-size: 11px;
}

.project-name {
    color: var(--color-text-secondary);
}

.project-link:hover .project-name {
    color: var(--color-status-blue);
}

.no-project {
    color: var(--color-text-tertiary);
    font-size: 13px;
}

.priority-badge {
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    text-transform: capitalize;
}

.priority-low { background: var(--color-bg-tertiary); color: var(--color-text-secondary); }
.priority-medium { background: rgba(59, 130, 246, 0.15); color: var(--color-status-blue); }
.priority-high { background: rgba(249, 115, 22, 0.15); color: var(--color-status-orange); }
.priority-urgent { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }

.assignee {
    display: flex;
    align-items: center;
    gap: 8px;
}

.avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: white;
    font-weight: 600;
}

.avatar-sm {
    width: 24px;
    height: 24px;
    font-size: 10px;
}

.avatar-xs {
    width: 20px;
    height: 20px;
    font-size: 8px;
}

.assignee-name {
    font-size: 13px;
    color: var(--color-text-secondary);
}

.unassigned {
    color: var(--color-text-tertiary);
    font-size: 13px;
}

.due-date {
    font-size: 13px;
    color: var(--color-text-secondary);
}

.no-due {
    color: var(--color-text-tertiary);
}

/* Mobile Task Row */
.mobile-task-row {
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-subtle);
    cursor: pointer;
}

.mobile-task-row:hover {
    background: var(--color-bg-tertiary);
}

/* Board View */
.board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    min-height: 600px;
}

@media (max-width: 1024px) {
    .board {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .board {
        grid-template-columns: repeat(4, 280px);
        overflow-x: auto;
        padding-bottom: 16px;
    }
}

.board-column {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
}

.column-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid var(--color-border-subtle);
}

.column-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: var(--color-text-primary);
    font-size: 14px;
}

.column-count {
    background: var(--color-bg-tertiary);
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.column-cards {
    flex: 1;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    overflow-y: auto;
}

.task-card {
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 12px;
    cursor: grab;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.15s ease;
}

.task-card:hover {
    border-color: var(--color-border-strong);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.15), 0 4px 8px -2px rgba(0, 0, 0, 0.1);
}

.task-card:active {
    cursor: grabbing;
    transform: scale(0.98);
}

/* Drag and drop styles */
.task-ghost {
    opacity: 0.5;
    background: var(--color-bg-tertiary);
    border: 2px dashed var(--color-accent);
    transform: scale(0.98);
}

.task-dragging {
    transform: rotate(2deg) scale(1.02);
    box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.25), 0 5px 15px rgba(0, 0, 0, 0.1);
    cursor: grabbing !important;
    z-index: 100;
}

/* Snap animation when dropped */
@keyframes task-snap {
    0% { transform: scale(0.95); }
    50% { transform: scale(1.02); }
    100% { transform: scale(1); }
}

.task-card.just-dropped {
    animation: task-snap 0.2s ease-out;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.priority-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}

.priority-dot.priority-low { background: var(--color-text-tertiary); }
.priority-dot.priority-medium { background: var(--color-status-blue); }
.priority-dot.priority-high { background: var(--color-status-orange); }
.priority-dot.priority-urgent { background: var(--color-status-red); }

.card-due {
    font-size: 11px;
    color: var(--color-text-tertiary);
}

.card-external-link {
    margin-left: auto;
    color: var(--color-text-quaternary);
    opacity: 0;
    transition: opacity 0.15s, color 0.15s;
}

.task-card:hover .card-external-link {
    opacity: 1;
}

.card-external-link:hover {
    color: var(--color-accent);
}

.card-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-primary);
    line-height: 1.4;
}

.card-project {
    font-size: 11px;
    color: var(--color-text-tertiary);
    margin-top: 8px;
}

.card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
}

.card-source {
    font-size: 10px;
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
    padding: 2px 6px;
    border-radius: 4px;
}

.add-task-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 12px;
    background: none;
    border: 1px dashed var(--color-border-default);
    border-radius: 8px;
    color: var(--color-text-tertiary);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.add-task-btn:hover {
    border-color: var(--color-status-blue);
    color: var(--color-status-blue);
}

.empty-state {
    padding: 48px;
    text-align: center;
    color: var(--color-text-tertiary);
}

/* Task Detail Modal */
.task-detail {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.detail-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.dev-agent-result {
    padding: 0.75rem;
    border-radius: 8px;
    font-size: 0.875rem;
}

.dev-agent-result.success {
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.2);
    color: var(--color-status-green);
}

.dev-agent-result.error {
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.2);
    color: var(--color-status-red);
}

.status-select {
    padding: 6px 10px;
    background: var(--color-bg-tertiary);
    border: 2px solid;
    border-radius: 6px;
    color: var(--color-text-primary);
    font-size: 13px;
    font-weight: 500;
}

.assignee-select {
    padding: 6px 10px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    color: var(--color-text-primary);
    font-size: 13px;
    width: 100%;
}

.detail-section {
    padding-top: 1rem;
    border-top: 1px solid var(--color-border-subtle);
}

.detail-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin-bottom: 0.5rem;
}

.detail-text {
    color: var(--color-text-primary);
    font-size: 0.875rem;
    line-height: 1.5;
}

.detail-grid {
    display: grid;
    gap: 1rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-link {
    color: var(--color-status-blue);
    font-size: 0.875rem;
    text-decoration: none;
}

.detail-link:hover {
    text-decoration: underline;
}

.detail-assignee {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.detail-empty {
    color: var(--color-text-tertiary);
    font-size: 0.875rem;
}

.detail-source {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-block;
}

.preview-section {
    background: var(--color-bg-secondary);
    border-radius: 8px;
    padding: 1rem;
    margin-top: 0.5rem;
}

.preview-links {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.preview-link {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 500;
    border-radius: 6px;
    text-decoration: none;
    background: var(--color-status-blue);
    color: #fff;
    transition: opacity 0.15s;
}

.preview-link:hover {
    opacity: 0.85;
}

.preview-link.pr-link {
    background: var(--color-text-tertiary);
}

.preview-branch {
    margin-top: 0.5rem;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.preview-branch code {
    background: var(--color-bg-tertiary);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Fira Mono', monospace;
    font-size: 0.6875rem;
}

.timeline {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.timeline-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}

.timeline-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--color-status-blue);
    margin-top: 6px;
    flex-shrink: 0;
}

.timeline-content p {
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.timeline-date {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

/* Video Section Styles */
.videos-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border);
}

.videos-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}

.videos-header h4 {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
}

.videos-loading {
    text-align: center;
    padding: 1rem;
    color: var(--color-text-tertiary);
    font-size: 0.875rem;
}

.videos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 0.75rem;
}

.video-item {
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.video-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.video-thumb {
    position: relative;
    aspect-ratio: 16 / 9;
    background: var(--color-bg-secondary);
}

.video-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.video-thumb-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--color-bg-tertiary) 0%, var(--color-bg-secondary) 100%);
}

.video-thumb-placeholder svg {
    width: 32px;
    height: 32px;
    color: var(--color-text-tertiary);
}

.video-duration {
    position: absolute;
    bottom: 4px;
    right: 4px;
    background: rgba(0, 0, 0, 0.8);
    color: #fff;
    font-size: 0.625rem;
    padding: 2px 4px;
    border-radius: 3px;
}

.video-info {
    padding: 0.5rem;
}

.video-title {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-primary);
    margin: 0 0 0.25rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.video-meta {
    font-size: 0.625rem;
    color: var(--color-text-tertiary);
    display: flex;
    gap: 0.5rem;
}

.videos-empty {
    text-align: center;
    padding: 1.5rem;
    color: var(--color-text-tertiary);
}

.videos-empty svg {
    width: 40px;
    height: 40px;
    margin-bottom: 0.5rem;
    opacity: 0.5;
}

.videos-empty p {
    margin: 0;
    font-size: 0.875rem;
}

.videos-empty-hint {
    font-size: 0.75rem;
    margin-top: 0.25rem;
    opacity: 0.7;
}

.video-item {
    position: relative;
}

.video-link {
    text-decoration: none;
    color: inherit;
    display: block;
}

.video-link:hover {
    opacity: 0.9;
}

.video-delete-btn {
    position: absolute;
    top: 4px;
    right: 4px;
    background: rgba(0, 0, 0, 0.7);
    border: none;
    border-radius: 4px;
    padding: 4px;
    cursor: pointer;
    color: white;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.video-item:hover .video-delete-btn {
    opacity: 1;
}

.video-delete-btn:hover {
    background: var(--color-status-red);
}

.video-delete-btn:disabled {
    cursor: not-allowed;
}

.loading-spinner {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Comment Form */
.comment-form {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.comment-input {
    width: 100%;
    padding: 0.75rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    color: var(--color-text-primary);
    font-size: 0.875rem;
    resize: none;
}

.comment-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.comment-form .btn {
    align-self: flex-end;
}

/* Activity List */
.activity-list {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.activity-item {
    display: flex;
    gap: 0.75rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--color-border-subtle);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.icon-comment {
    background: rgba(59, 130, 246, 0.15);
    color: var(--color-status-blue);
}

.icon-status_change {
    background: rgba(249, 115, 22, 0.15);
    color: var(--color-status-orange);
}

.icon-assignment {
    background: rgba(168, 85, 247, 0.15);
    color: var(--color-accent);
}

.icon-system {
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
}

.activity-content {
    flex: 1;
    min-width: 0;
}

.activity-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
}

.activity-user {
    font-weight: 500;
    font-size: 0.8125rem;
    color: var(--color-text-primary);
}

.activity-time {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.external-badge {
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 2px 6px;
    border-radius: 4px;
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.activity-delete {
    margin-left: auto;
    padding: 2px;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--color-text-tertiary);
    opacity: 0;
    transition: opacity 0.15s ease;
}

.activity-item:hover .activity-delete {
    opacity: 1;
}

.activity-delete:hover {
    color: var(--color-status-red);
}

.activity-text {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    margin: 0;
    line-height: 1.4;
}

.activity-comment .activity-text {
    color: var(--color-text-primary);
}

/* Status badges in activity */
.status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    vertical-align: middle;
}

.status-pending {
    background: rgba(113, 113, 122, 0.15);
    color: var(--color-text-secondary);
}

.status-in_progress {
    background: rgba(59, 130, 246, 0.15);
    color: var(--color-status-blue);
}

.status-review {
    background: rgba(139, 92, 246, 0.15);
    color: var(--color-accent);
}

.status-completed {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

/* Rich Content Styles */
.rich-content {
    font-size: 0.8125rem;
    color: var(--color-text-primary);
    line-height: 1.5;
}

.rich-content :deep(p) {
    margin: 0 0 0.5em;
}

.rich-content :deep(p:last-child) {
    margin-bottom: 0;
}

.rich-content :deep(ul),
.rich-content :deep(ol) {
    padding-left: 1.5em;
    margin: 0.5em 0;
}

.rich-content :deep(code) {
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    padding: 2px 4px;
    font-family: 'SF Mono', Consolas, monospace;
    font-size: 0.9em;
}

.rich-content :deep(.mention) {
    background: rgba(139, 92, 246, 0.15);
    border-radius: 4px;
    padding: 1px 4px;
    color: var(--color-accent);
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.15s ease;
}

.rich-content :deep(.mention:hover) {
    background: rgba(139, 92, 246, 0.25);
}

/* Reactions */
.reactions-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.reactions-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
}

.reaction-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.reaction-btn:hover {
    background: var(--color-bg-secondary);
    border-color: var(--color-border-strong);
}

.reaction-btn.user-reacted {
    background: rgba(139, 92, 246, 0.1);
    border-color: var(--color-accent);
}

.reaction-emoji {
    font-size: 0.875rem;
}

.reaction-count {
    font-weight: 500;
}

.add-reaction {
    position: relative;
}

.add-reaction-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: none;
    border: none;
    border-radius: 4px;
    color: var(--color-text-tertiary);
    cursor: pointer;
    opacity: 0;
    transition: all 0.15s ease;
}

.activity-item:hover .add-reaction-btn {
    opacity: 1;
}

.add-reaction-btn:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.emoji-picker {
    position: absolute;
    bottom: 100%;
    left: 0;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 8px;
    display: flex;
    gap: 4px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 10;
}

.emoji-option {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: none;
    border: none;
    border-radius: 6px;
    font-size: 1.125rem;
    cursor: pointer;
    transition: all 0.1s ease;
}

.emoji-option:hover {
    background: var(--color-bg-tertiary);
    transform: scale(1.2);
}
</style>
