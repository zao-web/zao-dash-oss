<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import FormCheckbox from '@/Components/FormCheckbox.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormTextarea from '@/Components/FormTextarea.vue'
import InlineSelect from '@/Components/InlineSelect.vue'
import RichTextEditor from '@/Components/RichTextEditor.vue'
import ChartCard from '@/Components/Charts/ChartCard.vue'
import { ExternalLinkIcon, DocumentIcon } from '@/Components/Icons'
import draggable from 'vuedraggable'
import { VisXYContainer, VisArea, VisLine, VisAxis, VisCrosshair, VisTooltip } from '@unovis/vue'
import { CurveType } from '@unovis/ts'
import ChartTooltip from '@/Components/Charts/ChartTooltip.vue'
import { marked } from 'marked'
import { Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, reactive, onMounted, onUnmounted, watch, createApp } from 'vue'
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
    type: 'comment' | 'status_change' | 'assignment' | 'system'
    content: string
    metadata: Record<string, any> | null
    user: { id: number; name: string }
    reactions: Reaction[]
    created_at: string
}

interface Task {
    id: number
    title: string
    description: string | null
    milestone_id: number | null
    status: 'pending' | 'in_progress' | 'review' | 'completed'
    priority: 'low' | 'medium' | 'high' | 'urgent'
    assignee: { id: number; name: string; type: 'user' | 'agent' | 'client_contact' } | null
    due_date: string | null
    due_date_raw: string | null
    estimated_hours: number | null
    position: number | null
    source: string
    external_url: string | null
    external_platform: string | null
    created_at: string
    metadata?: {
        preview_url?: string
        pr_url?: string
        cloud_environment_id?: string
        preview_branch?: string
        preview_status?: string
    } | null
}

interface Milestone {
    id: number
    name: string
    status: 'pending' | 'in_progress' | 'completed'
    due_date: string
    tasks_count: number
    completed_tasks_count: number
}

interface GitHubRepo {
    id: number
    full_name: string
    name?: string
    is_private: boolean
    default_branch: string
    open_issues_count: number
    open_prs_count: number
    url: string
    is_linked?: boolean
    match_score?: number
}

interface SlackChannel {
    id: number
    name: string
}

interface Project {
    id: number
    name: string
    slug: string
    description: string | null
    status: string
    type: string
    budget: number
    start_date: string | null
    end_date: string | null
    timeline_status: 'ahead' | 'on_track' | 'at_risk' | 'behind' | null
    days_elapsed: number | null
    days_remaining: number | null
    project_duration_days: number | null
    github_repo: string | null
    notion_page_id: string | null
    slack_channel_id: number | null
    slack_channel: SlackChannel | null
    client: { id: number; name: string; slug: string; slack_channel_id: number | null; slack_channel: SlackChannel | null }
    tasks: Task[]
    milestones: Milestone[]
    created_at: string
}

interface TimelineData {
    project: {
        start_date: string
        end_date: string
        time_progress_pct: number
        task_progress_pct: number
        timeline_status: string
        days_elapsed: number
        days_remaining: number
    }
    milestones: {
        id: number
        name: string
        status: string
        due_date: string | null
        start_pct: number
        end_pct: number
        tasks_count: number
        completed_tasks_count: number
        progress_pct: number
    }[]
    completion_series: { date: string; completed: number; total: number }[]
    ideal_series: { date: string; value: number }[]
}

interface DistributionPreview {
    milestones: {
        id: number | null
        name: string
        window_start: string
        window_end: string
        tasks: {
            id: number
            title: string
            milestone_name: string
            current_date: string | null
            new_date: string | null
        }[]
    }[]
    summary: { milestones_updated: number; tasks_updated: number }
}

interface Stats {
    total_tasks: number
    completed_tasks: number
    in_progress_tasks: number
    pending_tasks: number
    completion_pct: number
    hours_logged: number
    budget_used: number
    budget_remaining: number
}

interface Activity {
    id: number
    type: string
    description: string
    task: { id: number; title: string } | null
    user: { id: number; name: string } | null
    agent: { id: number; name: string } | null
    metadata: Record<string, any> | null
    pr_url: string | null
    pr_number: string | null
    hours_logged: number | null
    created_at: string
}

const props = defineProps<{
    project: Project
    stats: Stats
    team: { id: number; name: string; tasks_count: number }[]
    activities: Activity[]
    slackChannels: SlackChannel[]
    agents?: { id: number; name: string; slug: string }[]
    clientContacts?: { id: number; name: string; client_id: number; client_name: string }[]
}>()

const activeTab = ref<'overview' | 'tasks' | 'timeline' | 'activity' | 'settings'>('overview')
const taskFilter = ref<string>('all')
const taskMilestoneFilter = ref<string>('all')
const taskViewMode = ref<'list' | 'board' | 'milestone'>(getPreference('project.taskViewMode', 'list'))

watch(taskViewMode, (newMode) => {
    savePreference('project.taskViewMode', newMode)
})

// Modal state
const showDeleteModal = ref(false)
const showArchiveModal = ref(false)
const showTaskModal = ref(false)
const showTaskDetailModal = ref(false)
const isSubmitting = ref(false)
const isEditing = ref(false)
const selectedTask = ref<Task | null>(null)
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

// Task Import state
const showImportModal = ref(false)
const importTab = ref<'paste' | 'csv' | 'url' | 'excel'>('paste')
const importContent = ref('')
const importUrl = ref('')
const importFile = ref<File | null>(null)
const parsedTasks = ref<Array<{
    title: string
    description: string | null
    priority: string
    estimated_hours: number | null
    context: string | null
    selected: boolean
    status?: string
    original_status?: string
}>>([])
const parsingDocument = ref(false)
const importingTasks = ref(false)
const importAssigneeFilter = ref('')

// Excel import state
const excelFile = ref<File | null>(null)
const excelSheets = ref<string[]>([])
const excelSelectedSheet = ref('')
const excelHeaders = ref<string[]>([])
const excelTitleColumn = ref('')
const excelStatusColumn = ref('')
const excelDescriptionColumn = ref('')
const excelPriorityColumn = ref('')
const excelNotesColumn = ref('')
const loadingSheets = ref(false)

// GitHub repos state
const showRepoModal = ref(false)
const linkedRepos = ref<GitHubRepo[]>([])
const availableRepos = ref<GitHubRepo[]>([])
const suggestedRepos = ref<GitHubRepo[]>([])
const loadingRepos = ref(false)
const linkingRepoName = ref<string | null>(null)
const repoSearchQuery = ref('')

const filteredAvailableRepos = computed(() => {
    if (!repoSearchQuery.value) return availableRepos.value
    const query = repoSearchQuery.value.toLowerCase()
    return availableRepos.value.filter(repo => 
        repo.full_name.toLowerCase().includes(query)
    )
})

const loadLinkedRepos = async () => {
    try {
        const response = await fetch(`/api/integrations/github/projects/${props.project.id}/repos`)
        const data = await response.json()
        linkedRepos.value = data.repos || []
    } catch (e) {
        console.error('Failed to load linked repos:', e)
    }
}

const loadAvailableRepos = async () => {
    loadingRepos.value = true
    try {
        const response = await fetch(`/api/integrations/github/projects/${props.project.id}/available-repos`)
        const data = await response.json()
        availableRepos.value = data.repos || []
        suggestedRepos.value = data.suggestions || []
    } catch (e) {
        console.error('Failed to load available repos:', e)
    } finally {
        loadingRepos.value = false
    }
}

const openRepoModal = () => {
    showRepoModal.value = true
    loadAvailableRepos()
}

const linkRepo = async (repo: GitHubRepo) => {
    linkingRepoName.value = repo.full_name
    try {
        // For OAuth repos (no DB id), use full_name-based endpoint
        const endpoint = repo.id 
            ? `/api/integrations/github/projects/${props.project.id}/repos/${repo.id}/link`
            : `/api/integrations/github/projects/${props.project.id}/repos/link-by-name`
        
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: repo.id ? undefined : JSON.stringify({ full_name: repo.full_name }),
        })
        if (response.ok) {
            const data = await response.json()
            const linkedRepo = data.repo || { ...repo, is_linked: true, id: data.id }
            linkedRepos.value.push(linkedRepo)
            availableRepos.value = availableRepos.value.map(r =>
                r.full_name === repo.full_name ? { ...r, is_linked: true, id: linkedRepo.id } : r
            )
        }
    } catch (e) {
        console.error('Failed to link repo:', e)
    } finally {
        linkingRepoName.value = null
    }
}

const unlinkRepo = async (repo: GitHubRepo) => {
    linkingRepoName.value = repo.full_name
    try {
        const response = await fetch(`/api/integrations/github/projects/${props.project.id}/repos/${repo.id}/link`, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        })
        if (response.ok) {
            linkedRepos.value = linkedRepos.value.filter(r => r.full_name !== repo.full_name)
            availableRepos.value = availableRepos.value.map(r =>
                r.full_name === repo.full_name ? { ...r, is_linked: false } : r
            )
        }
    } catch (e) {
        console.error('Failed to unlink repo:', e)
    } finally {
        linkingRepoName.value = null
    }
}

interface EffortEstimation {
    success: boolean
    error?: string
    project_name: string
    since_date: string
    last_time_entry: { date: string; hours: number; notes: string } | null
    total_commits: number
    total_estimated_hours: number
    breakdown: Record<string, number>
    repos: Array<{
        repo: string
        commit_count: number
        estimated_hours: number
        error?: string
    }>
}

const effortEstimation = ref<EffortEstimation | null>(null)
const loadingEffortEstimation = ref(false)

const estimateEffort = async () => {
    loadingEffortEstimation.value = true
    effortEstimation.value = null
    try {
        const response = await fetch(`/api/integrations/github/projects/${props.project.id}/estimate-effort`)
        const data = await response.json()
        effortEstimation.value = data
    } catch (e) {
        console.error('Failed to estimate effort:', e)
        effortEstimation.value = {
            success: false,
            error: 'Failed to estimate effort',
            project_name: props.project.name,
            since_date: '',
            last_time_entry: null,
            total_commits: 0,
            total_estimated_hours: 0,
            breakdown: {},
            repos: [],
        }
    } finally {
        loadingEffortEstimation.value = false
    }
}

// Settings form
const settingsForm = reactive({
    name: props.project.name,
    description: props.project.description || '',
    status: props.project.status,
    type: props.project.type,
    budget: (props.project.budget ?? '').toString(),
    notion_page_id: props.project.notion_page_id || '',
    slack_channel_id: props.project.slack_channel_id,
    start_date: props.project.start_date || '',
    end_date: props.project.end_date || '',
})

// Timeline state
const timelineData = ref<TimelineData | null>(null)
const timelineLoading = ref(false)
const showDistributeModal = ref(false)
const distributeOverwrite = ref(false)
const distributionPreview = ref<DistributionPreview | null>(null)
const previewLoading = ref(false)
const distributing = ref(false)

const hasProjectDates = computed(() => !!props.project.start_date && !!props.project.end_date)

const segmentColors = ['#3b82f6', '#8b5cf6', '#06b6d4', '#f59e0b', '#ef4444', '#ec4899', '#10b981', '#6366f1', '#14b8a6', '#f97316']

const timelineStatusConfig = computed(() => {
    const status = timelineData.value?.project?.timeline_status || props.project.timeline_status
    const configs: Record<string, { label: string; color: string; bgColor: string }> = {
        ahead: { label: 'Ahead of Schedule', color: '#22c55e', bgColor: 'rgba(34, 197, 94, 0.1)' },
        on_track: { label: 'On Track', color: '#3b82f6', bgColor: 'rgba(59, 130, 246, 0.1)' },
        at_risk: { label: 'At Risk', color: '#f59e0b', bgColor: 'rgba(245, 158, 11, 0.1)' },
        behind: { label: 'Behind Schedule', color: '#ef4444', bgColor: 'rgba(239, 68, 68, 0.1)' },
    }
    return configs[status || ''] || configs.on_track
})

const todayPct = computed(() => {
    if (!timelineData.value) return 0
    const { time_progress_pct } = timelineData.value.project
    return Math.min(100, Math.max(0, time_progress_pct))
})

const tasksWithDates = computed(() => props.project.tasks.filter(t => t.due_date_raw).length)
const overdueTasks = computed(() => {
    const today = new Date().toISOString().split('T')[0]
    return props.project.tasks.filter(t => t.due_date_raw && t.due_date_raw < today && t.status !== 'completed').length
})

interface ChartPoint {
    index: number
    actual: number
    ideal: number
}

const completionChartData = computed<ChartPoint[]>(() => {
    if (!timelineData.value) return []
    const { completion_series, ideal_series } = timelineData.value
    const maxLen = Math.max(completion_series.length, ideal_series.length)
    const points: ChartPoint[] = []
    for (let i = 0; i < maxLen; i++) {
        points.push({
            index: i,
            actual: completion_series[i]?.completed ?? 0,
            ideal: ideal_series[i]?.value ?? 0,
        })
    }
    return points
})

const completionChartCategories = computed(() => {
    if (!timelineData.value) return []
    const series = timelineData.value.completion_series.length > 0
        ? timelineData.value.completion_series
        : timelineData.value.ideal_series
    return series.map(s => {
        const d = new Date(s.date)
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
    })
})

const chartX = (d: ChartPoint) => d.index
const chartActual = (d: ChartPoint) => d.actual
const chartIdeal = (d: ChartPoint) => d.ideal

const completionTooltipWm = new WeakMap()
function completionTooltipTemplate(d: ChartPoint) {
    if (completionTooltipWm.has(d)) return completionTooltipWm.get(d)
    const div = document.createElement('div')
    const data = [
        { name: 'Actual', color: '#3b82f6', value: `${Math.round(d.actual)} tasks` },
        { name: 'Estimated', color: '#9ca3af', value: `${Math.round(d.ideal)} tasks` },
    ]
    const title = completionChartCategories.value[d.index] ?? `Week ${d.index + 1}`
    createApp(ChartTooltip, { title, data }).mount(div)
    completionTooltipWm.set(d, div.innerHTML)
    return div.innerHTML
}

const loadTimelineData = async () => {
    if (!hasProjectDates.value) return
    timelineLoading.value = true
    try {
        const response = await fetch(`/api/projects/${props.project.id}/timeline-data`)
        if (response.ok) {
            timelineData.value = await response.json()
        }
    } finally {
        timelineLoading.value = false
    }
}

const loadDistributionPreview = async () => {
    previewLoading.value = true
    try {
        const response = await fetch(`/api/projects/${props.project.id}/preview-distribution?overwrite_existing=${distributeOverwrite.value ? '1' : '0'}`)
        if (response.ok) {
            distributionPreview.value = await response.json()
        }
    } finally {
        previewLoading.value = false
    }
}

const applyDistribution = () => {
    distributing.value = true
    router.post(`/projects/${props.project.id}/distribute-dates`, {
        overwrite_existing: distributeOverwrite.value,
    }, {
        onFinish: () => {
            distributing.value = false
            showDistributeModal.value = false
            distributionPreview.value = null
            loadTimelineData()
        },
    })
}

const showOverdueTasks = () => {
    taskFilter.value = 'overdue'
    activeTab.value = 'tasks'
    window.location.hash = '#tasks'
}

const saveDatesAndContinue = () => {
    isSubmitting.value = true
    router.put(`/projects/${props.project.id}`, {
        name: settingsForm.name,
        start_date: settingsForm.start_date || null,
        end_date: settingsForm.end_date || null,
    }, {
        onFinish: () => {
            isSubmitting.value = false
        },
        onSuccess: () => {
            loadTimelineData()
        },
    })
}

// Task form
const taskForm = reactive({
    title: '',
    description: '',
    status: 'pending',
    priority: 'medium',
    assigned_to: null as number | null,
    assignee_type: null as string | null,
    due_date: '',
})

const resetTaskForm = () => {
    taskForm.title = ''
    taskForm.description = ''
    taskForm.status = 'pending'
    taskForm.priority = 'medium'
    taskForm.assigned_to = null
    taskForm.assignee_type = null
    taskForm.due_date = ''
}

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

const statusFilteredTasks = computed(() => {
    if (taskFilter.value === 'all') return props.project.tasks
    if (taskFilter.value === 'overdue') {
        const today = new Date().toISOString().split('T')[0]
        return props.project.tasks.filter(t => t.due_date_raw && t.due_date_raw < today && t.status !== 'completed')
    }
    return props.project.tasks.filter(t => t.status === taskFilter.value)
})

const milestoneNameById = computed(() => {
    return new Map(props.project.milestones.map(m => [m.id, m.name]))
})

const taskMatchesMilestoneFilter = (task: Task): boolean => {
    if (taskMilestoneFilter.value === 'all') {
        return true
    }

    if (taskMilestoneFilter.value === 'unassigned') {
        return task.milestone_id == null
    }

    return task.milestone_id === Number(taskMilestoneFilter.value)
}

const filteredTasks = computed(() => {
    return statusFilteredTasks.value.filter(taskMatchesMilestoneFilter)
})

const milestoneTaskSections = computed(() => {
    const sections: Array<{
        key: string
        name: string
        tasks: Task[]
        total: number
        completed: number
        progress: number
    }> = []

    for (const milestone of props.project.milestones) {
        const tasks = filteredTasks.value.filter(t => t.milestone_id === milestone.id)
        if (tasks.length === 0) {
            continue
        }

        const completed = tasks.filter(t => t.status === 'completed').length
        sections.push({
            key: `milestone-${milestone.id}`,
            name: milestone.name,
            tasks,
            total: tasks.length,
            completed,
            progress: tasks.length ? Math.round((completed / tasks.length) * 100) : 0,
        })
    }

    const unassignedTasks = filteredTasks.value.filter(t => t.milestone_id == null)
    if (unassignedTasks.length > 0) {
        const completed = unassignedTasks.filter(t => t.status === 'completed').length
        sections.push({
            key: 'milestone-unassigned',
            name: 'No Milestone',
            tasks: unassignedTasks,
            total: unassignedTasks.length,
            completed,
            progress: unassignedTasks.length ? Math.round((completed / unassignedTasks.length) * 100) : 0,
        })
    }

    return sections
})

const milestoneFilterOptions = computed(() => {
    const options = [
        {
            key: 'all',
            label: 'All Milestones',
            count: statusFilteredTasks.value.length,
        },
    ]

    for (const milestone of props.project.milestones) {
        const count = statusFilteredTasks.value.filter(t => t.milestone_id === milestone.id).length
        if (milestone.tasks_count > 0 || count > 0) {
            options.push({
                key: String(milestone.id),
                label: milestone.name,
                count,
            })
        }
    }

    const unassignedCount = statusFilteredTasks.value.filter(t => t.milestone_id == null).length
    const hasUnassignedTasks = props.project.tasks.some(t => t.milestone_id == null)
    if (hasUnassignedTasks || unassignedCount > 0) {
        options.push({
            key: 'unassigned',
            label: 'No Milestone',
            count: unassignedCount,
        })
    }

    return options
})

const getTaskMilestoneName = (task: Task): string => {
    if (task.milestone_id == null) {
        return 'No Milestone'
    }

    return milestoneNameById.value.get(task.milestone_id) ?? 'Unknown Milestone'
}

const statusColors: Record<string, string> = {
    pending: 'var(--color-text-tertiary)',
    in_progress: 'var(--color-status-blue)',
    review: 'var(--color-status-purple)',
    completed: 'var(--color-status-green)'
}

const statusLabels: Record<string, string> = {
    pending: 'To Do',
    in_progress: 'In Progress',
    review: 'Review',
    completed: 'Done'
}

function sourceBadgeLabel(task: Task): string {
    if (task.source !== 'activity-feed') {
        return task.source
    }
    const platform = task.external_platform
    if (platform === 'slack') return 'from Slack'
    if (platform === 'email') return 'from email'
    if (platform === 'github_pr') {
        const num = task.external_url?.split('/').pop()
        return num ? `from PR #${num}` : 'from PR'
    }
    if (platform === 'github_issue') {
        const num = task.external_url?.split('/').pop()
        return num ? `from issue #${num}` : 'from issue'
    }
    if (platform === 'internal_task') return 'auto-synthesised'
    if (platform === 'google_sheet') return 'from sheet'
    return 'auto-synthesised'
}

const boardStatusKeys = ['pending', 'in_progress', 'review', 'completed'] as const

const boardArrays = reactive<Record<string, Task[]>>({})

const initBoardArrays = () => {
    for (const status of boardStatusKeys) {
        boardArrays[status] = filteredTasks.value
            .filter(t => t.status === status)
            .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
    }
}

initBoardArrays()

watch([filteredTasks], () => {
    initBoardArrays()
})

const getTasksByStatus = (status: string) => {
    return filteredTasks.value.filter(t => t.status === status)
}

const onBoardDragChange = (evt: any, targetStatus: string) => {
    if (!evt.moved && !evt.added) return

    const movedTask = evt.moved?.element || evt.added?.element
    if (!movedTask) return

    if (evt.added && targetStatus === 'completed') {
        const completedColumn = document.querySelector('[data-board-status="completed"]')
        if (completedColumn) {
            const rect = completedColumn.getBoundingClientRect()
            triggerConfetti({ x: rect.left + rect.width / 2, y: rect.top + 100 })
        } else {
            triggerConfetti()
        }
    }

    if (evt.added) {
        movedTask.status = targetStatus
    }

    const tasksToUpdate: { id: number; position: number; status: string }[] = []
    for (const status of boardStatusKeys) {
        const statusTasks = boardArrays[status] || []
        statusTasks.forEach((task, index) => {
            task.position = index
            task.status = status as Task['status']
            tasksToUpdate.push({ id: task.id, position: index, status })
        })
    }

    router.post('/tasks/reorder', { tasks: tasksToUpdate }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => initBoardArrays(),
        onSuccess: () => {
            if (hasProjectDates.value) loadTimelineData()
        },
    })
}

const platformNames: Record<string, string> = {
    clickup: 'ClickUp',
    notion: 'Notion',
    asana: 'Asana',
}

const getPlatformName = (platform: string | null) => {
    return platform ? (platformNames[platform] || platform) : 'External'
}

const priorityColors: Record<string, string> = {
    low: 'var(--color-text-tertiary)',
    medium: 'var(--color-status-blue)',
    high: 'var(--color-status-orange)',
    urgent: 'var(--color-status-red)'
}

const getActivityColor = (type: string): string => {
    const colors: Record<string, string> = {
        agent_completed: 'var(--color-status-green)',
        agent_started: 'var(--color-status-blue)',
        agent_assigned: 'var(--color-status-purple)',
        agent_failed: 'var(--color-status-red)',
        status_changed: 'var(--color-status-green)',
        pr_created: 'var(--color-status-purple)',
        pr_merged: 'var(--color-status-green)',
        time_logged: 'var(--color-status-orange)',
        comment_added: 'var(--color-status-blue)',
        deployed: 'var(--color-status-green)',
    }
    return colors[type] || 'var(--color-text-tertiary)'
}

const formatCurrency = (value: number | string | null) => {
    const num = Number(value) || 0
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
    }).format(num)
}

const getInitials = (name: string) => {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getMilestoneProgress = (m: Milestone) => {
    if (m.tasks_count === 0) return 0
    return Math.round((m.completed_tasks_count / m.tasks_count) * 100)
}

// Task status toggle
const toggleTaskStatus = (task: Task, event?: MouseEvent) => {
    const newStatus = task.status === 'completed' ? 'pending' : 'completed'

    // Confetti when completing!
    if (newStatus === 'completed') {
        triggerConfetti(event)
    }

    router.post(`/tasks/${task.id}/status`, {
        status: newStatus
    }, {
        preserveScroll: true,
        onSuccess: () => {
            if (hasProjectDates.value) loadTimelineData()
        },
    })
}

// Task detail modal
const openTaskDetail = async (task: Task, pushState = true) => {
    selectedTask.value = task
    showTaskDetailModal.value = true
    taskVideos.value = []
    taskComments.value = []
    newComment.value = ''
    loadingVideos.value = true

    if (pushState) {
        history.pushState(
            { tab: activeTab.value, modal: 'task', taskId: task.id },
            '',
            `#${activeTab.value}/task-${task.id}`
        )
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

    if (goBack && window.location.hash.includes('/task-')) {
        history.back()
    } else if (window.location.hash.includes('/task-')) {
        // Just remove the task part, keep the tab
        history.replaceState({ tab: activeTab.value }, '', `#${activeTab.value}`)
    }
}

const openEditTaskModal = (task: Task) => {
    isEditing.value = true
    selectedTask.value = task
    taskForm.title = task.title
    taskForm.description = task.description || ''
    taskForm.status = task.status
    taskForm.priority = task.priority
    taskForm.assigned_to = task.assignee?.id || null
    taskForm.assignee_type = task.assignee?.type || null
    taskForm.due_date = task.due_date || ''
    showTaskModal.value = true
}

const updateTaskStatus = (task: Task, newStatus: string, event?: MouseEvent) => {
    // Update local state optimistically
    if (selectedTask.value && selectedTask.value.id === task.id) {
        selectedTask.value = { ...selectedTask.value, status: newStatus as Task['status'] }
    }

    // Confetti when completing!
    if (newStatus === 'completed' && task.status !== 'completed') {
        triggerConfetti(event)
    }

    router.post(`/tasks/${task.id}/status`, {
        status: newStatus,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            if (hasProjectDates.value) loadTimelineData()
        },
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

const parseAssigneeValue = (value: string | null): { type: string | null; id: number | null } => {
    if (!value) return { type: null, id: null }
    const [type, idStr] = value.split(':')
    return { type, id: parseInt(idStr) }
}

const buildAssigneeValue = (type: string, id: number): string => `${type}:${id}`

const resolveAssigneeName = (type: string, id: number): string | null => {
    if (type === 'user') return props.team.find(m => m.id === id)?.name ?? null
    if (type === 'agent') return (props.agents ?? []).find(a => a.id === id)?.name ?? null
    if (type === 'client_contact') return (props.clientContacts ?? []).find(c => c.id === id)?.name ?? null
    return null
}

const updateTaskAssignee = (task: Task, compositeValue: string | null) => {
    const { type, id } = parseAssigneeValue(compositeValue)

    if (selectedTask.value && selectedTask.value.id === task.id) {
        if (id && type) {
            const name = resolveAssigneeName(type, id)
            selectedTask.value = {
                ...selectedTask.value,
                assignee: name ? { id, name, type: type as Task['assignee'] extends null ? never : NonNullable<Task['assignee']>['type'] } : null,
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

const getAssigneeAvatarStyle = (assignee: NonNullable<Task['assignee']>) => {
    const type = assignee.type ?? 'user'
    if (type === 'agent') return { background: 'var(--color-status-purple, #8b5cf6)' }
    if (type === 'client_contact') return { background: 'var(--color-status-orange, #f59e0b)' }
    return { background: `hsl(${assignee.id * 40}, 60%, 50%)` }
}

const getAssigneeLabel = (assignee: NonNullable<Task['assignee']>) => {
    if ((assignee.type ?? 'user') === 'agent') return 'AI'
    return getInitials(assignee.name)
}

// Options for inline selects
const inlineStatusColors: Record<string, string> = {
    pending: 'var(--color-text-tertiary)',
    in_progress: 'var(--color-status-blue)',
    review: 'var(--color-status-orange)',
    completed: 'var(--color-status-green)',
}

const statusSelectOptions = computed(() => [
    { value: 'pending', label: 'To Do', color: inlineStatusColors.pending },
    { value: 'in_progress', label: 'In Progress', color: inlineStatusColors.in_progress },
    { value: 'review', label: 'Review', color: inlineStatusColors.review },
    { value: 'completed', label: 'Completed', color: inlineStatusColors.completed },
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
    props.team.forEach(m => {
        options.push({ value: buildAssigneeValue('user', m.id), label: m.name, group: 'Team' })
    })
    ;(props.agents ?? []).forEach(a => {
        options.push({ value: buildAssigneeValue('agent', a.id), label: a.name, group: 'Agents' })
    })
    ;(props.clientContacts ?? []).forEach(c => {
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

const formatDuration = (seconds: number | null) => {
    if (!seconds) return '0:00'
    const mins = Math.floor(seconds / 60)
    const secs = seconds % 60
    return `${mins}:${secs.toString().padStart(2, '0')}`
}

const triggerRecordVideo = (taskId: number) => {
    window.postMessage({
        type: 'ZAO_RECORD_VIDEO',
        taskId: taskId,
    }, '*')
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

// Settings actions
const saveSettings = () => {
    isSubmitting.value = true

    router.put(`/projects/${props.project.id}`, {
        name: settingsForm.name,
        description: settingsForm.description,
        status: settingsForm.status,
        type: settingsForm.type,
        budget: parseFloat(settingsForm.budget),
        notion_page_id: settingsForm.notion_page_id || null,
        slack_channel_id: settingsForm.slack_channel_id,
        start_date: settingsForm.start_date || null,
        end_date: settingsForm.end_date || null,
    }, {
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

const archiveProject = () => {
    isSubmitting.value = true

    router.put(`/projects/${props.project.id}`, {
        status: 'archived'
    }, {
        onSuccess: () => {
            showArchiveModal.value = false
        },
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

const deleteProject = () => {
    isSubmitting.value = true

    router.delete(`/projects/${props.project.id}`, {
        onSuccess: () => {
            router.visit('/projects')
        },
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

// Task actions
const openNewTaskModal = () => {
    isEditing.value = false
    selectedTask.value = null
    resetTaskForm()
    showTaskModal.value = true
}

// Task Import functions
const openImportModal = () => {
    showImportModal.value = true
    parsedTasks.value = []
    importContent.value = ''
    importUrl.value = ''
    importFile.value = null
    importTab.value = 'paste'
    excelFile.value = null
    excelSheets.value = []
    excelHeaders.value = []
    excelSelectedSheet.value = ''
    excelTitleColumn.value = ''
    excelStatusColumn.value = ''
    excelDescriptionColumn.value = ''
    excelPriorityColumn.value = ''
    excelNotesColumn.value = ''
}

const parseDocument = async () => {
    parsingDocument.value = true
    try {
        const response = await fetch(`/api/projects/${props.project.id}/tasks/parse-document`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({
                content: importContent.value,
                url: importUrl.value || undefined,
                source_type: importTab.value === 'url' ? 'google_doc' : 'paste',
            }),
        })
        const data = await response.json()
        if (data.tasks) {
            parsedTasks.value = data.tasks.map((t: any) => ({ ...t, selected: true }))
        }
    } catch (e) {
        console.error('Failed to parse document:', e)
    } finally {
        parsingDocument.value = false
    }
}

const parseCsv = async () => {
    if (!importFile.value) return
    parsingDocument.value = true
    try {
        const formData = new FormData()
        formData.append('file', importFile.value)
        formData.append('title_column', 'Task name')
        formData.append('status_column', 'Status')
        formData.append('priority_column', 'Priority')
        if (importAssigneeFilter.value) {
            formData.append('assignee_filter', importAssigneeFilter.value)
        }

        const response = await fetch(`/api/projects/${props.project.id}/tasks/import-csv`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: formData,
        })
        const data = await response.json()
        if (data.tasks) {
            parsedTasks.value = data.tasks.map((t: any) => ({ ...t, selected: true }))
        }
    } catch (e) {
        console.error('Failed to parse CSV:', e)
    } finally {
        parsingDocument.value = false
    }
}

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement
    if (target.files && target.files[0]) {
        importFile.value = target.files[0]
    }
}

const handleExcelFileSelect = async (event: Event) => {
    const target = event.target as HTMLInputElement
    if (!target.files || !target.files[0]) return

    excelFile.value = target.files[0]
    excelSheets.value = []
    excelHeaders.value = []
    excelSelectedSheet.value = ''
    excelTitleColumn.value = ''
    excelStatusColumn.value = ''
    excelDescriptionColumn.value = ''
    excelPriorityColumn.value = ''
    excelNotesColumn.value = ''

    // Fetch sheet names
    loadingSheets.value = true
    try {
        const formData = new FormData()
        formData.append('file', excelFile.value)

        const response = await fetch(`/api/projects/${props.project.id}/tasks/excel-sheets`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: formData,
        })
        const data = await response.json()
        if (data.sheets) {
            excelSheets.value = data.sheets
            excelHeaders.value = data.headers || []
            if (data.sheets.length > 0) {
                excelSelectedSheet.value = data.sheets[0]
            }
        }
    } catch (e) {
        console.error('Failed to read Excel sheets:', e)
    } finally {
        loadingSheets.value = false
    }
}

const onExcelSheetChange = async () => {
    if (!excelFile.value || !excelSelectedSheet.value) return

    loadingSheets.value = true
    try {
        const formData = new FormData()
        formData.append('file', excelFile.value)
        formData.append('sheet_name', excelSelectedSheet.value)

        const response = await fetch(`/api/projects/${props.project.id}/tasks/excel-sheet-headers`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: formData,
        })
        const data = await response.json()
        if (data.headers) {
            excelHeaders.value = data.headers
            excelTitleColumn.value = ''
            excelStatusColumn.value = ''
            excelDescriptionColumn.value = ''
            excelPriorityColumn.value = ''
            excelNotesColumn.value = ''
        }
    } catch (e) {
        console.error('Failed to read sheet headers:', e)
    } finally {
        loadingSheets.value = false
    }
}

const parseExcel = async () => {
    if (!excelFile.value || !excelTitleColumn.value) return
    parsingDocument.value = true
    try {
        const formData = new FormData()
        formData.append('file', excelFile.value)
        formData.append('title_column', excelTitleColumn.value)
        if (excelSelectedSheet.value) formData.append('sheet_name', excelSelectedSheet.value)
        if (excelStatusColumn.value) formData.append('status_column', excelStatusColumn.value)
        if (excelDescriptionColumn.value) formData.append('description_column', excelDescriptionColumn.value)
        if (excelPriorityColumn.value) formData.append('priority_column', excelPriorityColumn.value)
        if (excelNotesColumn.value) formData.append('notes_column', excelNotesColumn.value)
        if (importAssigneeFilter.value) formData.append('assignee_filter', importAssigneeFilter.value)

        const response = await fetch(`/api/projects/${props.project.id}/tasks/import-excel`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: formData,
        })
        const data = await response.json()
        if (data.tasks) {
            parsedTasks.value = data.tasks.map((t: any) => ({ ...t, selected: true }))
        }
    } catch (e) {
        console.error('Failed to parse Excel:', e)
    } finally {
        parsingDocument.value = false
    }
}

const toggleAllTasks = (selected: boolean) => {
    parsedTasks.value.forEach(t => t.selected = selected)
}

const importSelectedTasks = async () => {
    const selected = parsedTasks.value.filter(t => t.selected)
    if (selected.length === 0) return

    importingTasks.value = true
    try {
        const response = await fetch(`/api/projects/${props.project.id}/tasks/import`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify({
                tasks: selected.map(t => ({
                    title: t.title,
                    description: t.description,
                    priority: t.priority,
                    estimated_hours: t.estimated_hours,
                    status: t.status || 'pending',
                    extra_data: t.extra_data || null,
                })),
                source: importTab.value === 'csv' ? 'csv_import' : importTab.value === 'excel' ? 'excel_import' : 'sow_import',
            }),
        })
        const data = await response.json()
        if (data.tasks) {
            showImportModal.value = false
            router.reload({ only: ['project'] })
        }
    } catch (e) {
        console.error('Failed to import tasks:', e)
    } finally {
        importingTasks.value = false
    }
}

const submitTask = () => {
    isSubmitting.value = true
    const url = isEditing.value ? `/tasks/${selectedTask.value?.id}` : '/tasks'
    const method = isEditing.value ? 'put' : 'post'

    router[method](url, {
        ...taskForm,
        project_id: props.project.id,
        source: 'manual',
    }, {
        onSuccess: () => {
            showTaskModal.value = false
            resetTaskForm()
        },
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

const teamOptions = computed(() =>
    props.team.map(m => ({ value: m.id, label: m.name }))
)

const priorityOptions = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'urgent', label: 'Urgent' },
]

const taskStatusOptions = [
    { value: 'pending', label: 'To Do' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'review', label: 'Review' },
    { value: 'completed', label: 'Completed' },
]

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'archived', label: 'Archived' },
]

const typeOptions = [
    { value: 'project', label: 'Project' },
    { value: 'retainer', label: 'Retainer' },
    { value: 'support', label: 'Support' },
]

const slackChannelOptions = computed(() => [
    { value: null, label: 'None' },
    ...props.slackChannels.map(c => ({ value: c.id, label: `#${c.name}` })),
])

// History state management
const parseHash = () => {
    const hash = window.location.hash.replace('#', '')
    if (!hash) return { tab: 'overview', taskId: null }

    const parts = hash.split('/')
    const tab = ['overview', 'tasks', 'timeline', 'activity', 'settings'].includes(parts[0]) ? parts[0] : 'overview'
    let taskId = null

    if (parts[1]?.startsWith('task-')) {
        taskId = parseInt(parts[1].replace('task-', ''), 10)
    }

    return { tab, taskId }
}

const handleHashNavigation = () => {
    const { tab, taskId } = parseHash()

    // Set the active tab
    activeTab.value = tab as typeof activeTab.value

    // Handle task modal
    if (taskId) {
        const task = props.project.tasks.find(t => t.id === taskId)
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

// Watch activeTab to update URL (only when not from popstate)
watch(activeTab, (newTab) => {
    // Only update if we're not currently handling a task modal
    if (!showTaskDetailModal.value) {
        const currentHash = window.location.hash.replace('#', '').split('/')[0]
        if (currentHash !== newTab) {
            history.replaceState({ tab: newTab }, '', `#${newTab}`)
        }
    }
})

onMounted(() => {
    handleHashNavigation()
    window.addEventListener('popstate', handlePopState)
    loadLinkedRepos()
    if (hasProjectDates.value) {
        loadTimelineData()
    }
})

onUnmounted(() => {
    window.removeEventListener('popstate', handlePopState)
})
</script>

<template>
    <AppLayout>
        <div class="page-container">
            <!-- Header -->
            <div class="page-header" style="margin-bottom: 0;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <Link :href="`/clients/${project.client.slug}`" class="breadcrumb-link">
                        {{ project.client.name }}
                    </Link>
                    <span style="color: var(--color-text-tertiary);">/</span>
                    <h1>{{ project.name }}</h1>
                    <span :class="['badge', `badge-${project.status}`]">{{ project.status }}</span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button
                    v-for="tab in ['overview', 'tasks', 'timeline', 'activity', 'settings']"
                    :key="tab"
                    :class="['tab', { active: activeTab === tab }]"
                    @click="activeTab = tab as any"
                >
                    {{ tab.charAt(0).toUpperCase() + tab.slice(1) }}
                </button>
            </div>

            <!-- Overview Tab -->
            <div v-if="activeTab === 'overview'" class="tab-content">
                <!-- Stats Row -->
                <div class="stats-grid">
                    <div class="metric-card">
                        <div class="metric-label">COMPLETION</div>
                        <div class="metric-value">{{ stats.completion_pct }}%</div>
                        <div class="progress-bar" style="margin-top: 8px;">
                            <div class="progress-fill" :style="{ width: `${stats.completion_pct}%` }"></div>
                        </div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">TASKS</div>
                        <div class="metric-value">{{ stats.completed_tasks }}<span class="metric-sub">/{{ stats.total_tasks }}</span></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">HOURS LOGGED</div>
                        <div class="metric-value">{{ stats.hours_logged.toFixed(1) }}<span class="metric-sub">h</span></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">BUDGET</div>
                        <div class="metric-value">{{ formatCurrency(project.budget) }}</div>
                        <div v-if="stats.budget_used > 0" class="metric-sub" style="margin-top: 4px;">
                            {{ formatCurrency(stats.budget_used) }} used
                        </div>
                    </div>
                </div>

                <div class="two-col">
                    <!-- Milestones -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Milestones</h3>
                        </div>
                        <div class="milestone-list">
                            <div v-for="milestone in project.milestones" :key="milestone.id" class="milestone-item">
                                <div class="milestone-header">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div
                                            class="status-dot"
                                            :style="{ background: statusColors[milestone.status] }"
                                        ></div>
                                        <span class="milestone-name">{{ milestone.name }}</span>
                                    </div>
                                    <span class="milestone-date">{{ milestone.due_date }}</span>
                                </div>
                                <div class="milestone-progress">
                                    <div class="progress-bar">
                                        <div
                                            class="progress-fill"
                                            :style="{ width: `${getMilestoneProgress(milestone)}%` }"
                                        ></div>
                                    </div>
                                    <span class="progress-text">{{ milestone.completed_tasks_count }}/{{ milestone.tasks_count }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Team -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Team</h3>
                        </div>
                        <div class="team-list">
                            <div v-for="member in team" :key="member.id" class="team-member">
                                <div class="avatar" :style="{ background: `hsl(${member.id * 40}, 60%, 50%)` }">
                                    {{ getInitials(member.name) }}
                                </div>
                                <div class="member-info">
                                    <div class="member-name">{{ member.name }}</div>
                                    <div class="member-tasks">{{ member.tasks_count }} tasks assigned</div>
                                </div>
                            </div>
                            <div v-if="team.length === 0" class="empty-state">
                                No team members assigned yet
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Tasks -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Tasks</h3>
                        <button class="btn btn-secondary" @click="activeTab = 'tasks'">View All</button>
                    </div>
                    <div class="task-list">
                        <div
                            v-for="task in project.tasks.slice(0, 5)"
                            :key="task.id"
                            class="task-item task-item-clickable"
                            @click="openTaskDetail(task)"
                        >
                            <div class="task-status">
                                <div class="status-dot" :style="{ background: statusColors[task.status] }"></div>
                            </div>
                            <div class="task-content">
                                <div class="task-title-row">
                                    <div class="task-title">{{ task.title }}</div>
                                    <a v-if="task.external_url" :href="task.external_url" target="_blank" @click.stop class="external-link" :title="`Open in ${getPlatformName(task.external_platform)}`">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>
                                        </svg>
                                    </a>
                                </div>
                                <div class="task-meta">
                                    <span :class="['priority-badge', `priority-${task.priority}`]">{{ task.priority }}</span>
                                    <span v-if="task.assignee" class="task-assignee">{{ task.assignee.name }}</span>
                                    <span v-if="task.due_date" class="task-due">Due {{ task.due_date }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="project.tasks.length === 0" class="empty-state">
                            No tasks yet
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tasks Tab -->
            <div v-if="activeTab === 'tasks'" class="tab-content">
                <div class="tasks-header">
                    <div class="task-filters">
                        <div class="view-toggle">
                            <button :class="['toggle-btn', { active: taskViewMode === 'list' }]" @click="taskViewMode = 'list'">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                            </button>
                            <button :class="['toggle-btn', { active: taskViewMode === 'milestone' }]" @click="taskViewMode = 'milestone'" title="Group by milestone">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <path d="M2.5 3.5h11M2.5 8h11M2.5 12.5h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    <circle cx="12.5" cy="12.5" r="1.5" stroke="currentColor" stroke-width="1.2"/>
                                </svg>
                            </button>
                            <button :class="['toggle-btn', { active: taskViewMode === 'board' }]" @click="taskViewMode = 'board'">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <rect x="1" y="2" width="4" height="12" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                    <rect x="6" y="2" width="4" height="8" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                    <rect x="11" y="2" width="4" height="10" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                            </button>
                        </div>
                        <button
                            v-for="filter in [
                                { key: 'all', label: 'All' },
                                { key: 'pending', label: 'Pending' },
                                { key: 'in_progress', label: 'In Progress' },
                                { key: 'review', label: 'Review' },
                                { key: 'completed', label: 'Completed' },
                                { key: 'overdue', label: `Overdue (${overdueTasks})` }
                            ]"
                            :key="filter.key"
                            :class="['filter-btn', { active: taskFilter === filter.key }]"
                            @click="taskFilter = filter.key"
                        >
                            {{ filter.label }}
                        </button>
                    </div>
                    <div class="task-actions">
                        <button class="btn btn-secondary" @click="openImportModal">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M14 10v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-3M8 2v8M5 5l3-3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Import
                        </button>
                        <button class="btn btn-primary" @click="openNewTaskModal">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            New Task
                        </button>
                    </div>
                </div>

                <div v-if="milestoneFilterOptions.length > 1" class="milestone-filters">
                    <span class="milestone-filter-label">Milestone</span>
                    <button
                        v-for="option in milestoneFilterOptions"
                        :key="option.key"
                        :class="['milestone-filter-btn', { active: taskMilestoneFilter === option.key }]"
                        @click="taskMilestoneFilter = option.key"
                    >
                        <span>{{ option.label }}</span>
                        <span class="milestone-filter-count">{{ option.count }}</span>
                    </button>
                </div>

                <!-- List View -->
                <div v-if="taskViewMode === 'list'" class="card">
                    <div class="task-list-full">
                        <div
                            v-for="task in filteredTasks"
                            :key="task.id"
                            class="task-row"
                            @click="openTaskDetail(task)"
                        >
                            <div class="task-checkbox" @click.stop="(e: MouseEvent) => toggleTaskStatus(task, e)">
                                <FormCheckbox
                                    :model-value="task.status === 'completed'"
                                />
                            </div>
                            <div class="task-status-col">
                                <div class="status-dot" :style="{ background: statusColors[task.status] }"></div>
                            </div>
                            <div class="task-main">
                                <div class="task-title-row">
                                    <div class="task-title">{{ task.title }}</div>
                                    <a v-if="task.external_url" :href="task.external_url" target="_blank" @click.stop class="external-link" :title="`Open in ${getPlatformName(task.external_platform)}`">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>
                                        </svg>
                                    </a>
                                </div>
                                <div v-if="task.description" class="task-description">{{ task.description }}</div>
                                <div class="task-meta-row">
                                    <span class="task-milestone-badge">{{ getTaskMilestoneName(task) }}</span>
                                </div>
                            </div>
                            <div class="task-priority">
                                <span :class="['priority-badge', `priority-${task.priority}`]">{{ task.priority }}</span>
                            </div>
                            <div class="task-assignee-col">
                                <div v-if="task.assignee" class="avatar avatar-sm" :style="getAssigneeAvatarStyle(task.assignee)">
                                    {{ getAssigneeLabel(task.assignee) }}
                                </div>
                            </div>
                            <div class="task-due-col">
                                <span v-if="task.due_date" class="due-date">{{ task.due_date }}</span>
                            </div>
                            <div class="task-source">
                                <component
                                    :is="task.external_url ? 'a' : 'span'"
                                    :href="task.external_url ?? undefined"
                                    :target="task.external_url ? '_blank' : undefined"
                                    rel="noopener"
                                    class="source-badge"
                                >{{ sourceBadgeLabel(task) }}</component>
                            </div>
                        </div>
                        <div v-if="filteredTasks.length === 0" class="empty-state">
                            No tasks match the current filter
                        </div>
                    </div>
                </div>

                <!-- Milestone Grouped View -->
                <div v-if="taskViewMode === 'milestone'" class="milestone-task-groups">
                    <div v-for="section in milestoneTaskSections" :key="section.key" class="card milestone-task-section">
                        <div class="milestone-task-section-header">
                            <div class="milestone-task-section-title-wrap">
                                <div class="milestone-task-section-title">{{ section.name }}</div>
                                <div class="milestone-task-section-meta">
                                    {{ section.completed }}/{{ section.total }} completed
                                </div>
                            </div>
                            <div class="milestone-task-section-progress">
                                <div class="milestone-task-section-progress-bar">
                                    <div class="milestone-task-section-progress-fill" :style="{ width: `${section.progress}%` }"></div>
                                </div>
                                <span class="milestone-task-section-progress-text">{{ section.progress }}%</span>
                            </div>
                        </div>

                        <div class="task-list-full">
                            <div
                                v-for="task in section.tasks"
                                :key="task.id"
                                class="task-row"
                                @click="openTaskDetail(task)"
                            >
                                <div class="task-checkbox" @click.stop="(e: MouseEvent) => toggleTaskStatus(task, e)">
                                    <FormCheckbox
                                        :model-value="task.status === 'completed'"
                                    />
                                </div>
                                <div class="task-status-col">
                                    <div class="status-dot" :style="{ background: statusColors[task.status] }"></div>
                                </div>
                                <div class="task-main">
                                    <div class="task-title-row">
                                        <div class="task-title">{{ task.title }}</div>
                                        <a v-if="task.external_url" :href="task.external_url" target="_blank" @click.stop class="external-link" :title="`Open in ${getPlatformName(task.external_platform)}`">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>
                                            </svg>
                                        </a>
                                    </div>
                                    <div v-if="task.description" class="task-description">{{ task.description }}</div>
                                    <div class="task-meta-row">
                                        <span class="task-milestone-badge">{{ getTaskMilestoneName(task) }}</span>
                                    </div>
                                </div>
                                <div class="task-priority">
                                    <span :class="['priority-badge', `priority-${task.priority}`]">{{ task.priority }}</span>
                                </div>
                                <div class="task-assignee-col">
                                    <div v-if="task.assignee" class="avatar avatar-sm" :style="getAssigneeAvatarStyle(task.assignee)">
                                        {{ getAssigneeLabel(task.assignee) }}
                                    </div>
                                </div>
                                <div class="task-due-col">
                                    <span v-if="task.due_date" class="due-date">{{ task.due_date }}</span>
                                </div>
                                <div class="task-source">
                                    <span class="source-badge">{{ task.source }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="milestoneTaskSections.length === 0" class="card">
                        <div class="empty-state">No tasks match the current filter</div>
                    </div>
                </div>

                <!-- Board View -->
                <div v-if="taskViewMode === 'board'" class="task-board">
                    <div
                        v-for="status in boardStatusKeys"
                        :key="status"
                        class="board-column"
                        :data-board-status="status"
                    >
                        <div class="column-header">
                            <div class="status-indicator" :style="{ background: statusColors[status] }"></div>
                            <span class="column-title">{{ statusLabels[status] }}</span>
                            <span class="column-count">{{ (boardArrays[status] || []).length }}</span>
                        </div>
                        <draggable
                            :list="boardArrays[status]"
                            group="tasks"
                            item-key="id"
                            class="column-content"
                            ghost-class="task-ghost"
                            drag-class="task-dragging"
                            :animation="150"
                            @change="(evt: any) => onBoardDragChange(evt, status as string)"
                        >
                            <template #item="{ element: task }">
                                <div class="board-card" @click="openTaskDetail(task)">
                                    <div class="card-header-row">
                                        <span :class="['priority-dot', `priority-${task.priority}`]"></span>
                                        <span class="card-title">{{ task.title }}</span>
                                    </div>
                                    <div class="card-milestone">{{ getTaskMilestoneName(task) }}</div>
                                    <div v-if="task.description" class="card-description">{{ task.description?.slice(0, 80) }}{{ task.description?.length > 80 ? '...' : '' }}</div>
                                    <div class="card-footer">
                                        <div v-if="task.assignee" class="avatar avatar-xs" :style="getAssigneeAvatarStyle(task.assignee)">
                                            {{ getAssigneeLabel(task.assignee) }}
                                        </div>
                                        <span v-if="task.due_date" class="card-due">{{ task.due_date }}</span>
                                    </div>
                                </div>
                            </template>
                        </draggable>
                    </div>
                </div>
            </div>

            <!-- Timeline Tab -->
            <div v-if="activeTab === 'timeline'" class="tab-content">
                <!-- No dates configured -->
                <div v-if="!hasProjectDates" class="card">
                    <div class="timeline-empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color: var(--color-text-quaternary); margin-bottom: 16px;">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        <h3>Set Project Dates</h3>
                        <p class="text-caption" style="margin-bottom: 20px;">Add a start and end date to enable the project timeline view.</p>
                        <div class="timeline-date-setup">
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" v-model="settingsForm.start_date" class="form-input" />
                            </div>
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" v-model="settingsForm.end_date" class="form-input" />
                            </div>
                            <button class="btn btn-primary" :disabled="!settingsForm.start_date || !settingsForm.end_date || isSubmitting" @click="saveDatesAndContinue">
                                {{ isSubmitting ? 'Saving...' : 'Save Dates' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Timeline content -->
                <template v-else>
                    <!-- Loading state -->
                    <div v-if="timelineLoading && !timelineData" class="card" style="padding: 40px; text-align: center;">
                        <span class="loading-spinner"></span>
                        <p class="text-caption" style="margin-top: 12px;">Loading timeline...</p>
                    </div>

                    <template v-else-if="timelineData">
                        <!-- Status Banner -->
                        <div class="timeline-status-banner" :style="{ borderColor: timelineStatusConfig.color, background: timelineStatusConfig.bgColor }">
                            <div class="timeline-status-left">
                                <span class="timeline-status-dot" :style="{ background: timelineStatusConfig.color }"></span>
                                <span class="timeline-status-label" :style="{ color: timelineStatusConfig.color }">{{ timelineStatusConfig.label }}</span>
                            </div>
                            <div class="timeline-status-right">
                                <span class="text-caption">{{ new Date(timelineData.project.start_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) }} &mdash; {{ new Date(timelineData.project.end_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) }}</span>
                                <span class="timeline-status-divider">|</span>
                                <span class="text-caption">Day {{ timelineData.project.days_elapsed }} of {{ (timelineData.project.days_elapsed || 0) + (timelineData.project.days_remaining || 0) }}</span>
                            </div>
                        </div>

                        <!-- Timeline Bar -->
                        <div class="card" style="padding: 24px;">
                            <h4 style="margin-bottom: 16px; font-size: 14px; font-weight: 600;">Milestone Timeline</h4>
                            <div class="timeline-bar-container">
                                <div class="timeline-bar">
                                    <div
                                        v-for="(milestone, idx) in timelineData.milestones"
                                        :key="milestone.id"
                                        class="timeline-segment"
                                        :title="`${milestone.name} — ${milestone.completed_tasks_count}/${milestone.tasks_count} tasks`"
                                        :style="{
                                            left: milestone.start_pct + '%',
                                            width: Math.max(0.5, milestone.end_pct - milestone.start_pct) + '%',
                                            background: segmentColors[idx % segmentColors.length] + '22',
                                            borderColor: segmentColors[idx % segmentColors.length] + '44',
                                        }"
                                    >
                                        <div
                                            class="timeline-segment-fill"
                                            :style="{
                                                width: milestone.progress_pct + '%',
                                                background: milestone.status === 'completed' ? '#22c55e' : segmentColors[idx % segmentColors.length],
                                            }"
                                        ></div>
                                    </div>
                                    <!-- Today marker -->
                                    <div
                                        v-if="todayPct > 0 && todayPct < 100"
                                        class="timeline-today-marker"
                                        :style="{ left: todayPct + '%' }"
                                    >
                                        <span class="timeline-today-label">Today</span>
                                        <div class="timeline-today-line"></div>
                                    </div>
                                </div>
                                <!-- Milestone legend -->
                                <div class="timeline-legend">
                                    <div
                                        v-for="(milestone, idx) in timelineData.milestones"
                                        :key="'legend-' + milestone.id"
                                        class="timeline-legend-item"
                                    >
                                        <span class="timeline-legend-dot" :style="{ background: segmentColors[idx % segmentColors.length] }"></span>
                                        <span class="timeline-legend-name">{{ milestone.name }}</span>
                                        <span class="timeline-legend-count">{{ milestone.completed_tasks_count }}/{{ milestone.tasks_count }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Stats Row -->
                        <div class="timeline-stats-row">
                            <div class="timeline-stat-card">
                                <span class="timeline-stat-value">{{ timelineData.project.task_progress_pct }}%</span>
                                <span class="timeline-stat-label">Completion</span>
                            </div>
                            <div class="timeline-stat-card">
                                <span class="timeline-stat-value">{{ timelineData.project.time_progress_pct }}%</span>
                                <span class="timeline-stat-label">Time Elapsed</span>
                            </div>
                            <div class="timeline-stat-card">
                                <span class="timeline-stat-value">{{ tasksWithDates }}</span>
                                <span class="timeline-stat-label">Tasks with Dates</span>
                            </div>
                            <div
                                class="timeline-stat-card"
                                :class="{ 'timeline-stat-clickable': overdueTasks > 0 }"
                                @click="overdueTasks > 0 && showOverdueTasks()"
                            >
                                <span class="timeline-stat-value" :style="{ color: overdueTasks > 0 ? '#ef4444' : undefined }">{{ overdueTasks }}</span>
                                <span class="timeline-stat-label">Overdue Tasks</span>
                            </div>
                        </div>

                        <!-- Completion Chart -->
                        <div v-if="completionChartData.length > 1" class="card completion-chart-card" style="padding: 24px; margin-bottom: 16px;">
                            <h4 style="margin-bottom: 16px; font-size: 14px; font-weight: 600;">Estimated vs Actual Progress</h4>
                            <div class="completion-chart" style="height: 200px;">
                                <VisXYContainer :data="completionChartData" :height="200" :margin="{ top: 8, right: 16, bottom: 28, left: 40 }">
                                    <VisArea
                                        :x="chartX"
                                        :y="chartActual"
                                        color="#3b82f6"
                                        :curve-type="CurveType.MonotoneX"
                                        :opacity="0.12"
                                    />
                                    <VisLine
                                        :x="chartX"
                                        :y="chartActual"
                                        color="#3b82f6"
                                        :curve-type="CurveType.MonotoneX"
                                        :line-width="2"
                                    />
                                    <VisLine
                                        :x="chartX"
                                        :y="chartIdeal"
                                        color="#9ca3af"
                                        :curve-type="CurveType.Linear"
                                        :line-width="1.5"
                                        :line-dash-array="[4, 4]"
                                    />
                                    <VisAxis
                                        type="x"
                                        :tick-format="(i: number) => completionChartCategories[i] ?? ''"
                                        :grid-line="false"
                                        :tick-line="false"
                                        :domain-line="false"
                                    />
                                    <VisAxis
                                        type="y"
                                        :tick-format="(v: number) => Math.round(v).toString()"
                                        :grid-line="true"
                                        :tick-line="false"
                                        :domain-line="false"
                                    />
                                    <VisCrosshair :template="completionTooltipTemplate" />
                                    <VisTooltip :horizontal-shift="16" :vertical-shift="16" />
                                </VisXYContainer>
                            </div>
                            <div class="chart-legend">
                                <span class="chart-legend-item"><span class="chart-legend-swatch" style="background: #3b82f6;"></span> Actual</span>
                                <span class="chart-legend-item"><span class="chart-legend-swatch chart-legend-dashed" style="background: #9ca3af;"></span> Estimated</span>
                            </div>
                        </div>

                        <!-- Milestone Detail Cards -->
                        <div class="card">
                            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between;">
                                <h3>Milestones</h3>
                                <button class="btn btn-secondary btn-sm" @click="showDistributeModal = true">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                        <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                                        <path d="M8 5v3l2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    Auto-assign Dates
                                </button>
                            </div>
                            <div class="milestone-cards">
                                <div v-for="milestone in timelineData.milestones" :key="'detail-' + milestone.id" class="milestone-detail-card">
                                    <div class="milestone-detail-header">
                                        <span class="milestone-detail-name">{{ milestone.name }}</span>
                                        <span
                                            class="milestone-detail-status"
                                            :class="'status-' + milestone.status"
                                        >{{ milestone.status.replace('_', ' ') }}</span>
                                    </div>
                                    <div class="milestone-detail-progress">
                                        <div class="milestone-detail-bar">
                                            <div class="milestone-detail-bar-fill" :style="{ width: milestone.progress_pct + '%' }"></div>
                                        </div>
                                        <span class="milestone-detail-pct">{{ milestone.progress_pct }}%</span>
                                    </div>
                                    <div class="milestone-detail-meta">
                                        <span>{{ milestone.completed_tasks_count }}/{{ milestone.tasks_count }} tasks</span>
                                        <span v-if="milestone.due_date">Due {{ milestone.due_date }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
            </div>

            <!-- Distribution Modal -->
            <Modal :show="showDistributeModal" @close="showDistributeModal = false; distributionPreview = null">
                <div class="modal-content" style="max-width: 700px;">
                    <div class="modal-header">
                        <h3>Auto-assign Due Dates</h3>
                        <button class="btn-icon" @click="showDistributeModal = false">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                    <div style="padding: 16px;">
                        <p class="text-caption" style="margin-bottom: 16px;">
                            Distribute due dates across milestones proportionally based on estimated hours (or task count). Tasks are ordered by position within each milestone.
                        </p>

                        <label class="distribute-checkbox">
                            <input type="checkbox" v-model="distributeOverwrite" />
                            <span>Overwrite existing due dates</span>
                        </label>

                        <div style="display: flex; gap: 8px; margin-top: 16px;">
                            <button class="btn btn-secondary" @click="loadDistributionPreview" :disabled="previewLoading">
                                {{ previewLoading ? 'Loading...' : 'Preview Changes' }}
                            </button>
                        </div>

                        <!-- Preview table -->
                        <div v-if="distributionPreview" style="margin-top: 20px;">
                            <div style="font-size: 13px; font-weight: 500; margin-bottom: 8px; color: var(--color-text-secondary);">
                                {{ distributionPreview.summary.tasks_updated }} tasks will be updated across {{ distributionPreview.summary.milestones_updated }} milestones
                            </div>
                            <div class="distribute-preview-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Task</th>
                                            <th>Milestone</th>
                                            <th>Current Date</th>
                                            <th>New Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template v-for="milestone in distributionPreview.milestones" :key="milestone.id">
                                            <tr v-for="task in milestone.tasks" :key="task.id" :class="{ 'changed': task.new_date !== task.current_date }">
                                                <td>{{ task.title }}</td>
                                                <td>{{ task.milestone_name }}</td>
                                                <td>{{ task.current_date || '—' }}</td>
                                                <td :class="{ 'text-new-date': task.new_date !== task.current_date }">{{ task.new_date || '—' }}</td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
                                <button class="btn btn-secondary" @click="showDistributeModal = false; distributionPreview = null">Cancel</button>
                                <button class="btn btn-primary" @click="applyDistribution" :disabled="distributing">
                                    {{ distributing ? 'Applying...' : `Apply to ${distributionPreview.summary.tasks_updated} Tasks` }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </Modal>

            <!-- Activity Tab -->
            <div v-if="activeTab === 'activity'" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Project Activity</h3>
                    </div>
                    <div class="activity-list">
                        <div v-for="activity in activities" :key="activity.id" class="activity-item">
                            <div class="activity-icon" :style="{ background: getActivityColor(activity.type) }">
                                <svg v-if="activity.type === 'status_changed' || activity.type === 'agent_completed'" width="14" height="14" viewBox="0 0 16 16" fill="none">
                                    <path d="M13.5 4.5l-7 7L3 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <svg v-else-if="activity.type.startsWith('agent_')" width="14" height="14" viewBox="0 0 16 16" fill="none">
                                    <circle cx="8" cy="8" r="3" stroke="currentColor" stroke-width="2"/>
                                </svg>
                                <svg v-else-if="activity.type === 'pr_created' || activity.type === 'pr_merged'" width="14" height="14" viewBox="0 0 16 16" fill="none">
                                    <path d="M5 3v10M11 3v10M5 8h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                <svg v-else-if="activity.type === 'time_logged'" width="14" height="14" viewBox="0 0 16 16" fill="none">
                                    <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                                    <path d="M8 5v3l2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                <svg v-else width="14" height="14" viewBox="0 0 16 16" fill="none">
                                    <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">
                                    <strong v-if="activity.agent">{{ activity.agent.name }}</strong>
                                    <strong v-else-if="activity.user">{{ activity.user.name }}</strong>
                                    {{ activity.description }}
                                    <template v-if="activity.task">
                                        on "<Link :href="`#tasks/task-${activity.task.id}`" class="activity-task-link">{{ activity.task.title }}</Link>"
                                    </template>
                                    <a v-if="activity.pr_url" :href="activity.pr_url" target="_blank" class="activity-pr-link">
                                        PR #{{ activity.pr_number }}
                                    </a>
                                </div>
                                <div class="activity-time">{{ activity.created_at }}</div>
                            </div>
                        </div>
                        <div v-if="activities.length === 0" class="empty-state">
                            No activity yet. Activity will appear here when tasks are updated, agents run, or PRs are created.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings Tab -->
            <div v-if="activeTab === 'settings'" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Project Settings</h3>
                    </div>
                    <div class="settings-form">
                        <div class="form-group">
                            <label>Project Name</label>
                            <input type="text" v-model="settingsForm.name" class="form-input" />
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea class="form-input" rows="3" v-model="settingsForm.description"></textarea>
                        </div>
                        <div class="form-row">
                            <FormSelect
                                v-model="settingsForm.status"
                                label="Status"
                                :options="statusOptions"
                            />
                            <FormSelect
                                v-model="settingsForm.type"
                                label="Type"
                                :options="typeOptions"
                            />
                            <div class="form-group">
                                <label>Budget</label>
                                <input type="number" v-model="settingsForm.budget" class="form-input" />
                            </div>
                        </div>
                        <div class="form-row" style="grid-template-columns: 1fr 1fr;">
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" v-model="settingsForm.start_date" class="form-input" />
                            </div>
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" v-model="settingsForm.end_date" class="form-input" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-4">
                            <button class="btn btn-primary" :disabled="isSubmitting" @click="saveSettings">
                                {{ isSubmitting ? 'Saving...' : 'Save Changes' }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Integrations</h3>
                    </div>
                    <div class="settings-form">
                        <!-- GitHub Repositories -->
                        <div class="form-group">
                            <div class="integration-header">
                                <label>GitHub Repositories</label>
                                <button class="btn btn-sm btn-secondary" @click="openRepoModal">
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                        <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    Add Repository
                                </button>
                            </div>
                            <div v-if="linkedRepos.length > 0" class="linked-repos-list">
                                <div v-for="repo in linkedRepos" :key="repo.id" class="linked-repo-item">
                                    <div class="repo-info">
                                        <a :href="repo.url" target="_blank" class="repo-name">
                                            <svg v-if="repo.is_private" class="repo-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                                <path d="M4 4a4 4 0 118 0v2h.25c.966 0 1.75.784 1.75 1.75v5.5A1.75 1.75 0 0112.25 15h-8.5A1.75 1.75 0 012 13.25v-5.5C2 6.784 2.784 6 3.75 6H4V4zm8.25 3.5h-8.5a.25.25 0 00-.25.25v5.5c0 .138.112.25.25.25h8.5a.25.25 0 00.25-.25v-5.5a.25.25 0 00-.25-.25zM10.5 6V4a2.5 2.5 0 10-5 0v2h5z"/>
                                            </svg>
                                            <svg v-else class="repo-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                                <path d="M2 2.5A2.5 2.5 0 014.5 0h8.75a.75.75 0 01.75.75v12.5a.75.75 0 01-.75.75h-2.5a.75.75 0 110-1.5h1.75v-2h-8a1 1 0 00-.714 1.7.75.75 0 01-1.072 1.05A2.495 2.495 0 012 11.5v-9zm10.5-1H4.5a1 1 0 00-1 1v6.708A2.486 2.486 0 014.5 9h8V1.5z"/>
                                            </svg>
                                            {{ repo.full_name }}
                                        </a>
                                        <span class="repo-branch">{{ repo.default_branch }}</span>
                                    </div>
                                    <div class="repo-stats">
                                        <span v-if="repo.open_issues_count" class="repo-stat">{{ repo.open_issues_count }} issues</span>
                                        <span v-if="repo.open_prs_count" class="repo-stat">{{ repo.open_prs_count }} PRs</span>
                                    </div>
                                    <button
                                        class="btn-icon btn-remove"
                                        @click="unlinkRepo(repo)"
                                        :disabled="linkingRepoName === repo.full_name"
                                        title="Remove repository"
                                    >
                                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div v-else class="empty-repos">
                                <p>No repositories linked to this project</p>
                                <p class="empty-hint">Click "Add Repository" to link GitHub repos</p>
                            </div>

                            <div v-if="linkedRepos.length > 0" class="effort-estimation-section">
                                <button
                                    class="btn btn-sm btn-secondary"
                                    @click="estimateEffort"
                                    :disabled="loadingEffortEstimation"
                                >
                                    <svg v-if="!loadingEffortEstimation" width="14" height="14" viewBox="0 0 16 16" fill="none">
                                        <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                                        <path d="M8 5v3l2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    <span v-else class="loading-spinner"></span>
                                    {{ loadingEffortEstimation ? 'Estimating...' : 'Estimate Effort' }}
                                </button>

                                <div v-if="effortEstimation" class="effort-results">
                                    <div v-if="effortEstimation.success" class="effort-success">
                                        <div class="effort-header">
                                            <div class="effort-total">
                                                <span class="effort-value">{{ effortEstimation.total_estimated_hours }}</span>
                                                <span class="effort-label">hours estimated</span>
                                            </div>
                                            <div class="effort-meta">
                                                <span>{{ effortEstimation.total_commits }} commits</span>
                                                <span>since {{ effortEstimation.since_date }}</span>
                                            </div>
                                        </div>

                                        <div class="effort-breakdown">
                                            <div v-for="(hours, type) in effortEstimation.breakdown" :key="type" class="breakdown-item">
                                                <span class="breakdown-type">{{ type }}</span>
                                                <span class="breakdown-hours">{{ hours }}h</span>
                                            </div>
                                        </div>

                                        <div class="effort-repos">
                                            <div v-for="repo in effortEstimation.repos" :key="repo.repo" class="repo-effort">
                                                <span class="repo-effort-name">{{ repo.repo }}</span>
                                                <span class="repo-effort-stats">
                                                    {{ repo.commit_count }} commits / {{ repo.estimated_hours }}h
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="effort-error">
                                        {{ effortEstimation.error }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Notion Page ID</label>
                            <input type="text" v-model="settingsForm.notion_page_id" placeholder="Page ID or URL" class="form-input" />
                        </div>

                        <FormSelect
                            v-model="settingsForm.slack_channel_id"
                            label="Slack Channel"
                            :options="slackChannelOptions"
                            :placeholder="project.client.slack_channel ? `Inherit from client (#${project.client.slack_channel.name})` : 'Select a channel'"
                            :hint="project.client.slack_channel && !settingsForm.slack_channel_id ? `Inheriting from ${project.client.name}'s default channel` : undefined"
                            searchable
                        />
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Billing</h3>
                    </div>
                    <div class="settings-form">
                        <div class="form-group">
                            <label>Invoicing</label>
                            <p class="text-caption" style="margin-bottom: 12px;">Create an invoice for work completed on this project.</p>
                            <Link :href="`/invoices/create?client_id=${project.client.id}&project_id=${project.id}`" class="btn btn-secondary">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Create Invoice
                            </Link>
                        </div>
                    </div>
                </div>

                <div class="card danger-zone">
                    <div class="card-header">
                        <h3 style="color: var(--color-status-red);">Danger Zone</h3>
                    </div>
                    <div class="danger-actions">
                        <div class="danger-item">
                            <div>
                                <div class="danger-title">Archive this project</div>
                                <div class="danger-desc">Mark project as archived. Can be restored later.</div>
                            </div>
                            <button class="btn btn-danger-outline" @click="showArchiveModal = true">Archive</button>
                        </div>
                        <div class="danger-item">
                            <div>
                                <div class="danger-title">Delete this project</div>
                                <div class="danger-desc">Permanently delete this project and all associated data.</div>
                            </div>
                            <button class="btn btn-danger" @click="showDeleteModal = true">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Tasks Modal -->
        <Modal
            :show="showImportModal"
            title="Import Tasks"
            size="lg"
            @close="showImportModal = false"
        >
            <div class="import-modal">
                <!-- Tab navigation -->
                <div class="import-tabs">
                    <button
                        :class="['import-tab', { active: importTab === 'paste' }]"
                        @click="importTab = 'paste'; parsedTasks = []"
                    >
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M10 2H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V5l-3-3z" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        Paste SOW
                    </button>
                    <button
                        :class="['import-tab', { active: importTab === 'csv' }]"
                        @click="importTab = 'csv'; parsedTasks = []"
                    >
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M3 3h10v10H3V3zM6 3v10M10 3v10M3 8h10" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        CSV File
                    </button>
                    <button
                        :class="['import-tab', { active: importTab === 'url' }]"
                        @click="importTab = 'url'; parsedTasks = []"
                    >
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M6.5 9.5a3.5 3.5 0 0 0 5-5M9.5 6.5a3.5 3.5 0 0 0-5 5M7 9l2-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        Google Doc URL
                    </button>
                    <button
                        :class="['import-tab', { active: importTab === 'excel' }]"
                        @click="importTab = 'excel'; parsedTasks = []"
                    >
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M3 2h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M5 5l6 6M11 5l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        Spreadsheet
                    </button>
                </div>

                <!-- Tab content -->
                <div class="import-content">
                    <!-- Paste SOW Tab -->
                    <div v-if="importTab === 'paste' && parsedTasks.length === 0">
                        <p class="import-help">Paste your Statement of Work, brief, or requirements document. AI will extract actionable tasks.</p>
                        <textarea
                            v-model="importContent"
                            class="import-textarea"
                            placeholder="Paste your SOW content here..."
                            rows="12"
                        ></textarea>
                        <button
                            class="btn btn-primary"
                            style="margin-top: 12px;"
                            :disabled="!importContent.trim() || parsingDocument"
                            @click="parseDocument"
                        >
                            {{ parsingDocument ? 'Analyzing...' : 'Extract Tasks' }}
                        </button>
                    </div>

                    <!-- CSV Tab -->
                    <div v-if="importTab === 'csv' && parsedTasks.length === 0">
                        <p class="import-help">Upload a CSV file (e.g., Notion export). Tasks will be parsed with status mapping.</p>
                        <div class="file-upload-zone" @click="($refs.fileInput as HTMLInputElement)?.click()">
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".csv"
                                style="display: none;"
                                @change="handleFileSelect"
                            />
                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                                <path d="M28 20v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-6M16 4v16M10 10l6-6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span v-if="importFile">{{ importFile.name }}</span>
                            <span v-else>Click to upload CSV</span>
                        </div>
                        <div class="form-group" style="margin-top: 12px;">
                            <label>Filter by assignee (optional)</label>
                            <FormInput
                                v-model="importAssigneeFilter"
                                placeholder="e.g., Justin, Krishna, Shehroz"
                            />
                        </div>
                        <button
                            class="btn btn-primary"
                            style="margin-top: 12px;"
                            :disabled="!importFile || parsingDocument"
                            @click="parseCsv"
                        >
                            {{ parsingDocument ? 'Parsing...' : 'Parse CSV' }}
                        </button>
                    </div>

                    <!-- URL Tab -->
                    <div v-if="importTab === 'url' && parsedTasks.length === 0">
                        <p class="import-help">Paste a Google Docs URL. The document will be fetched and parsed for tasks.</p>
                        <FormInput
                            v-model="importUrl"
                            placeholder="https://docs.google.com/document/d/..."
                        />
                        <button
                            class="btn btn-primary"
                            style="margin-top: 12px;"
                            :disabled="!importUrl.trim() || parsingDocument"
                            @click="parseDocument"
                        >
                            {{ parsingDocument ? 'Fetching...' : 'Fetch & Extract' }}
                        </button>
                    </div>

                    <!-- Excel/Spreadsheet Tab -->
                    <div v-if="importTab === 'excel' && parsedTasks.length === 0">
                        <p class="import-help">Upload an Excel file (.xlsx, .xls). Hyperlinks in cells will be extracted and included in task descriptions.</p>
                        <div class="file-upload-zone" @click="($refs.excelFileInput as HTMLInputElement)?.click()">
                            <input
                                ref="excelFileInput"
                                type="file"
                                accept=".xlsx,.xls"
                                style="display: none;"
                                @change="handleExcelFileSelect"
                            />
                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                                <path d="M28 20v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-6M16 4v16M10 10l6-6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span v-if="excelFile">{{ excelFile.name }}</span>
                            <span v-else>Click to upload .xlsx or .xls file</span>
                        </div>

                        <div v-if="loadingSheets" style="margin-top: 12px; color: var(--text-secondary);">
                            Loading spreadsheet...
                        </div>

                        <div v-if="excelSheets.length > 0 && !loadingSheets" style="margin-top: 12px;">
                            <div v-if="excelSheets.length > 1" class="form-group">
                                <FormSelect
                                    v-model="excelSelectedSheet"
                                    label="Sheet"
                                    :options="excelSheets.map(s => ({ value: s, label: s }))"
                                    @update:modelValue="onExcelSheetChange"
                                />
                            </div>
                            <div v-else class="form-group" style="margin-bottom: 8px;">
                                <label style="font-size: 13px; color: var(--text-secondary);">Sheet: {{ excelSheets[0] }}</label>
                            </div>

                            <div v-if="excelHeaders.length > 0" class="excel-column-mapping">
                                <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">Map columns to task fields:</p>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <FormSelect
                                        v-model="excelTitleColumn"
                                        label="Title *"
                                        placeholder="Select column..."
                                        :options="excelHeaders.map(h => ({ value: h, label: h }))"
                                    />
                                    <FormSelect
                                        v-model="excelStatusColumn"
                                        label="Status"
                                        placeholder="None"
                                        :options="[{ value: '', label: 'None' }, ...excelHeaders.map(h => ({ value: h, label: h }))]"
                                    />
                                    <FormSelect
                                        v-model="excelPriorityColumn"
                                        label="Priority"
                                        placeholder="None"
                                        :options="[{ value: '', label: 'None' }, ...excelHeaders.map(h => ({ value: h, label: h }))]"
                                    />
                                    <FormSelect
                                        v-model="excelDescriptionColumn"
                                        label="Description"
                                        placeholder="None"
                                        :options="[{ value: '', label: 'None' }, ...excelHeaders.map(h => ({ value: h, label: h }))]"
                                    />
                                    <FormSelect
                                        v-model="excelNotesColumn"
                                        label="Notes"
                                        placeholder="None"
                                        :options="[{ value: '', label: 'None' }, ...excelHeaders.map(h => ({ value: h, label: h }))]"
                                    />
                                </div>
                                <div class="form-group" style="margin-top: 8px;">
                                    <label>Filter by assignee (optional)</label>
                                    <FormInput
                                        v-model="importAssigneeFilter"
                                        placeholder="e.g., Justin, Krishna, Shehroz"
                                    />
                                </div>
                            </div>

                            <button
                                class="btn btn-primary"
                                style="margin-top: 12px;"
                                :disabled="!excelTitleColumn || parsingDocument"
                                @click="parseExcel"
                            >
                                {{ parsingDocument ? 'Parsing...' : 'Parse Spreadsheet' }}
                            </button>
                        </div>
                    </div>

                    <!-- Parsed Tasks Preview -->
                    <div v-if="parsedTasks.length > 0" class="parsed-tasks">
                        <div class="parsed-header">
                            <div class="parsed-info">
                                <strong>{{ parsedTasks.filter(t => t.selected).length }}</strong> of {{ parsedTasks.length }} tasks selected
                            </div>
                            <div class="parsed-actions">
                                <button class="btn btn-sm" @click="toggleAllTasks(true)">Select All</button>
                                <button class="btn btn-sm" @click="toggleAllTasks(false)">Deselect All</button>
                                <button class="btn btn-sm" @click="parsedTasks = []">← Back</button>
                            </div>
                        </div>
                        <div class="parsed-list">
                            <div
                                v-for="(task, i) in parsedTasks"
                                :key="i"
                                :class="['parsed-task', { selected: task.selected }]"
                                @click="task.selected = !task.selected"
                            >
                                <FormCheckbox :model-value="task.selected" @click.stop="task.selected = !task.selected" />
                                <div class="parsed-task-content">
                                    <div class="parsed-task-title">{{ task.title }}</div>
                                    <div class="parsed-task-meta">
                                        <span v-if="task.status" :class="['status-badge', task.status]">{{ task.original_status || task.status }}</span>
                                        <span v-if="task.priority" class="priority-badge">{{ task.priority }}</span>
                                        <span v-if="task.estimated_hours" class="hours-badge">{{ task.estimated_hours }}h</span>
                                    </div>
                                    <div v-if="task.description" class="parsed-task-desc">{{ task.description }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="parsed-footer">
                            <button
                                class="btn btn-primary"
                                :disabled="parsedTasks.filter(t => t.selected).length === 0 || importingTasks"
                                @click="importSelectedTasks"
                            >
                                {{ importingTasks ? 'Importing...' : `Import ${parsedTasks.filter(t => t.selected).length} Tasks` }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>

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
                    placeholder="Enter task title"
                    required
                />
                <FormTextarea
                    v-model="taskForm.description"
                    label="Description"
                    placeholder="Task description..."
                    :rows="3"
                />
                <div class="grid grid-cols-2 gap-4">
                    <FormSelect
                        v-model="taskForm.status"
                        label="Status"
                        :options="taskStatusOptions"
                    />
                    <FormSelect
                        v-model="taskForm.priority"
                        label="Priority"
                        :options="priorityOptions"
                    />
                </div>
                <FormSelect
                    v-model="formAssigneeComposite"
                    label="Assignee"
                    :options="assigneeSelectOptions"
                    placeholder="Select an assignee (optional)"
                    searchable
                />
                <FormInput
                    v-model="taskForm.due_date"
                    label="Due Date"
                    type="date"
                />
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

        <!-- Archive Confirmation Modal -->
        <Modal
            :show="showArchiveModal"
            title="Archive Project"
            size="sm"
            @close="showArchiveModal = false"
        >
            <div class="space-y-4">
                <p class="text-body">
                    Are you sure you want to archive <strong>{{ project.name }}</strong>?
                </p>
                <p class="text-caption">
                    The project will be marked as archived and hidden from active views. You can restore it later if needed.
                </p>
            </div>

            <template #footer>
                <button class="btn btn-secondary" @click="showArchiveModal = false">
                    Cancel
                </button>
                <button
                    class="btn btn-danger-outline"
                    :disabled="isSubmitting"
                    @click="archiveProject"
                >
                    {{ isSubmitting ? 'Archiving...' : 'Archive Project' }}
                </button>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal
            :show="showDeleteModal"
            title="Delete Project"
            size="sm"
            @close="showDeleteModal = false"
        >
            <div class="space-y-4">
                <p class="text-body">
                    Are you sure you want to delete <strong>{{ project.name }}</strong>?
                </p>
                <div class="warning-box">
                    <svg class="h-5 w-5 flex-shrink-0" style="color: var(--color-status-red)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div>
                        <p class="text-body" style="color: var(--color-status-red)">This action cannot be undone</p>
                        <p class="text-caption">All tasks, time entries, and files associated with this project will be permanently deleted.</p>
                    </div>
                </div>
            </div>

            <template #footer>
                <button class="btn btn-secondary" @click="showDeleteModal = false">
                    Cancel
                </button>
                <button
                    class="btn btn-danger"
                    :disabled="isSubmitting"
                    @click="deleteProject"
                >
                    {{ isSubmitting ? 'Deleting...' : 'Delete Project' }}
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
                </div>

                <!-- Description -->
                <div class="detail-section" v-if="selectedTask.description">
                    <h4 class="detail-label">Description</h4>
                    <p class="detail-text">{{ selectedTask.description }}</p>
                </div>

                <!-- Meta Grid -->
                <div class="detail-grid">
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
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
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
                                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z" />
                                        </svg>
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
                                <svg v-if="deletingVideoId !== video.id" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
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
                                <svg v-if="comment.type === 'comment'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                                </svg>
                                <svg v-else-if="comment.type === 'status_change'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                                </svg>
                                <svg v-else-if="comment.type === 'assignment'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                </svg>
                            </div>
                            <div class="activity-content">
                                <div class="activity-header">
                                    <span class="activity-user">{{ comment.user.name }}</span>
                                    <span class="activity-time">{{ comment.created_at }}</span>
                                    <button
                                        v-if="comment.type === 'comment'"
                                        class="activity-delete"
                                        @click="deleteComment(comment.id)"
                                        :disabled="deletingCommentId === comment.id"
                                    >
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                                <div v-if="comment.type === 'comment'" class="activity-text rich-content" v-html="comment.content"></div>
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

                        <!-- Show task created -->
                        <div class="activity-item activity-system">
                            <div class="activity-icon icon-system">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
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

        <!-- Add Repository Modal -->
        <Modal
            :show="showRepoModal"
            title="Add GitHub Repository"
            size="md"
            @close="showRepoModal = false"
        >
            <div class="repo-modal-content">
                <div v-if="loadingRepos" class="loading-state">
                    Loading repositories...
                </div>

                <template v-else>
                    <!-- Suggestions Section -->
                    <div v-if="suggestedRepos.length > 0" class="repo-section">
                        <h4 class="section-title">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M8 1.5a6.5 6.5 0 100 13 6.5 6.5 0 000-13zM0 8a8 8 0 1116 0A8 8 0 010 8zm6.5-.25A.75.75 0 017.25 7h1a.75.75 0 01.75.75v2.75h.25a.75.75 0 010 1.5h-2a.75.75 0 010-1.5h.25v-2h-.25a.75.75 0 01-.75-.75zM8 6a1 1 0 100-2 1 1 0 000 2z"/>
                            </svg>
                            Suggested (name matches)
                        </h4>
                        <div class="repo-list">
                            <div
                                v-for="repo in suggestedRepos"
                                :key="repo.id"
                                :class="['repo-option', { linked: repo.is_linked }]"
                            >
                                <div class="repo-option-info">
                                    <span class="repo-option-name">{{ repo.full_name }}</span>
                                    <span class="match-badge">{{ repo.match_score }}% match</span>
                                </div>
                                <button
                                    v-if="!repo.is_linked"
                                    class="btn btn-sm btn-primary"
                                    @click="linkRepo(repo)"
                                    :disabled="linkingRepoName === repo.full_name"
                                >
                                    {{ linkingRepoName === repo.full_name ? 'Linking...' : 'Link' }}
                                </button>
                                <span v-else class="linked-badge">Linked</span>
                            </div>
                        </div>
                    </div>

                    <!-- All Available Repos -->
                    <div class="repo-section">
                        <h4 class="section-title">All Available Repositories</h4>
                        <input
                            v-model="repoSearchQuery"
                            type="text"
                            class="form-input repo-search"
                            placeholder="Search repositories..."
                        />
                        <div v-if="filteredAvailableRepos.length > 0" class="repo-list">
                            <div
                                v-for="repo in filteredAvailableRepos"
                                :key="repo.id"
                                :class="['repo-option', { linked: repo.is_linked }]"
                            >
                                <div class="repo-option-info">
                                    <span class="repo-option-name">
                                        <svg v-if="repo.is_private" class="repo-icon-sm" width="12" height="12" viewBox="0 0 16 16" fill="currentColor">
                                            <path d="M4 4a4 4 0 118 0v2h.25c.966 0 1.75.784 1.75 1.75v5.5A1.75 1.75 0 0112.25 15h-8.5A1.75 1.75 0 012 13.25v-5.5C2 6.784 2.784 6 3.75 6H4V4zm8.25 3.5h-8.5a.25.25 0 00-.25.25v5.5c0 .138.112.25.25.25h8.5a.25.25 0 00.25-.25v-5.5a.25.25 0 00-.25-.25zM10.5 6V4a2.5 2.5 0 10-5 0v2h5z"/>
                                        </svg>
                                        {{ repo.full_name }}
                                    </span>
                                    <span v-if="repo.match_score && repo.match_score >= 40" class="match-badge-subtle">{{ repo.match_score }}%</span>
                                </div>
                                <button
                                    v-if="!repo.is_linked"
                                    class="btn btn-sm btn-secondary"
                                    @click="linkRepo(repo)"
                                    :disabled="linkingRepoName === repo.full_name"
                                >
                                    {{ linkingRepoName === repo.full_name ? 'Linking...' : 'Link' }}
                                </button>
                                <span v-else class="linked-badge">Linked</span>
                            </div>
                        </div>
                        <div v-else-if="repoSearchQuery && availableRepos.length > 0" class="empty-state">
                            No repositories match "{{ repoSearchQuery }}"
                        </div>
                        <div v-else class="empty-state">
                            No repositories available. Install the GitHub App first.
                        </div>
                    </div>
                </template>
            </div>

            <template #footer>
                <button class="btn btn-secondary" @click="showRepoModal = false">
                    Done
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.page-container {
    padding: 24px 32px;
}

.breadcrumb-link {
    color: var(--color-text-secondary);
    text-decoration: none;
    font-size: 14px;
}
.breadcrumb-link:hover {
    color: var(--color-text-primary);
}

/* Tabs */
.tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--color-border-primary);
    margin: 16px 0 24px;
}

.tab {
    padding: 12px 16px;
    background: none;
    border: none;
    color: var(--color-text-secondary);
    font-size: 14px;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all 0.15s ease;
}

.tab:hover {
    color: var(--color-text-primary);
}

.tab.active {
    color: var(--color-text-primary);
    border-bottom-color: var(--color-accent);
}

.tab-content {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}

.metric-sub {
    font-size: 20px;
    color: var(--color-text-tertiary);
}

/* Two Column Layout */
.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

/* Milestone List */
.milestone-list {
    display: flex;
    flex-direction: column;
}

.milestone-item {
    padding: 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.milestone-item:last-child {
    border-bottom: none;
}

.milestone-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.milestone-name {
    font-weight: 500;
    color: var(--color-text-primary);
}

.milestone-date {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.milestone-progress {
    display: flex;
    align-items: center;
    gap: 12px;
}

.progress-bar {
    flex: 1;
    height: 4px;
    background: var(--color-bg-tertiary);
    border-radius: 2px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--color-status-green);
    border-radius: 2px;
    transition: width 0.3s ease;
}

.progress-text {
    font-size: 12px;
    color: var(--color-text-tertiary);
    min-width: 40px;
}

/* Team List */
.team-list {
    display: flex;
    flex-direction: column;
}

.team-member {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.team-member:last-child {
    border-bottom: none;
}

.avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    color: white;
}

.avatar-sm {
    width: 28px;
    height: 28px;
    font-size: 10px;
}

.member-name {
    font-weight: 500;
    color: var(--color-text-primary);
}

.member-tasks {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

/* Task List */
.task-list {
    display: flex;
    flex-direction: column;
}

.task-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.task-item:last-child {
    border-bottom: none;
}

.task-status {
    padding-top: 4px;
}

.task-content {
    flex: 1;
}

.task-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}

.task-title {
    font-weight: 500;
    color: var(--color-text-primary);
}

.external-link {
    color: var(--color-text-quaternary);
    opacity: 0;
    transition: opacity 0.15s, color 0.15s;
}

.task-item:hover .external-link,
.task-row:hover .external-link {
    opacity: 1;
}

.external-link:hover {
    color: var(--color-accent);
}

.task-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 12px;
}

.priority-badge {
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
}

.priority-low { background: var(--color-bg-tertiary); color: var(--color-text-secondary); }
.priority-medium { background: rgba(59, 130, 246, 0.15); color: var(--color-status-blue); }
.priority-high { background: rgba(249, 115, 22, 0.15); color: var(--color-status-orange); }
.priority-urgent { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }

.task-assignee {
    color: var(--color-text-secondary);
}

.task-due {
    color: var(--color-text-tertiary);
}

/* Tasks Tab Full List */
.tasks-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.task-filters {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.task-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.filter-btn {
    padding: 8px 16px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-primary);
    border-radius: 6px;
    color: var(--color-text-secondary);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.filter-btn:hover {
    background: var(--color-bg-tertiary);
}

.filter-btn.active {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.task-list-full {
    display: flex;
    flex-direction: column;
}

.task-row {
    display: grid;
    grid-template-columns: 24px 24px 1fr 80px 40px 100px 80px;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.task-row:hover {
    background: var(--color-bg-tertiary);
}

.task-checkbox :deep(.checkbox-group) {
    margin-bottom: 0;
}

.task-description {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 4px;
}

.task-meta-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 6px;
}

.task-milestone-badge {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    line-height: 1;
    padding: 4px 8px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-status-blue) 12%, transparent);
    color: var(--color-text-secondary);
    border: 1px solid color-mix(in srgb, var(--color-status-blue) 20%, var(--color-border-primary));
}

.due-date {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.source-badge {
    font-size: 11px;
    padding: 2px 6px;
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    color: var(--color-text-tertiary);
}

/* View Toggle */
.view-toggle {
    display: flex;
    background: var(--color-bg-tertiary);
    border-radius: 6px;
    padding: 2px;
    margin-right: 8px;
}

.toggle-btn {
    padding: 6px 8px;
    border: none;
    background: transparent;
    border-radius: 4px;
    color: var(--color-text-tertiary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}

.toggle-btn:hover {
    color: var(--color-text-primary);
}

.toggle-btn.active {
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.milestone-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin: 12px 0 16px;
}

.milestone-filter-label {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-right: 2px;
}

.milestone-filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-primary);
    border-radius: 999px;
    color: var(--color-text-secondary);
    font-size: 12px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.milestone-filter-btn:hover {
    background: var(--color-bg-tertiary);
}

.milestone-filter-btn.active {
    border-color: color-mix(in srgb, var(--color-status-blue) 35%, var(--color-border-primary));
    background: color-mix(in srgb, var(--color-status-blue) 12%, var(--color-bg-secondary));
    color: var(--color-text-primary);
}

.milestone-filter-count {
    display: inline-flex;
    min-width: 18px;
    height: 18px;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
    font-size: 11px;
}

.milestone-task-groups {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.milestone-task-section {
    overflow: hidden;
}

.milestone-task-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-primary);
    background: var(--color-bg-secondary);
}

.milestone-task-section-title-wrap {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.milestone-task-section-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-primary);
}

.milestone-task-section-meta {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.milestone-task-section-progress {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 120px;
}

.milestone-task-section-progress-bar {
    width: 88px;
    height: 6px;
    border-radius: 999px;
    background: var(--color-bg-tertiary);
    overflow: hidden;
}

.milestone-task-section-progress-fill {
    height: 100%;
    background: var(--color-status-blue);
    border-radius: 999px;
    transition: width 0.2s ease;
}

.milestone-task-section-progress-text {
    font-size: 12px;
    color: var(--color-text-secondary);
    min-width: 34px;
    text-align: right;
}

/* Board View */
.task-board {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    min-height: 400px;
}

.board-column {
    background: var(--color-bg-secondary);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    min-height: 300px;
}

.column-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.status-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.column-title {
    font-weight: 500;
    font-size: 13px;
    color: var(--color-text-primary);
}

.column-count {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-left: auto;
}

.column-content {
    flex: 1;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-height: 100px;
}

.board-card {
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-primary);
    border-radius: 6px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.board-card:hover {
    border-color: var(--color-border-secondary);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.task-ghost {
    opacity: 0.4;
    background: var(--color-bg-tertiary);
}

.task-dragging {
    opacity: 0.9;
    transform: rotate(2deg);
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}

.card-header-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.priority-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    margin-top: 6px;
    flex-shrink: 0;
}

.priority-dot.priority-urgent { background: var(--color-status-red); }
.priority-dot.priority-high { background: var(--color-status-orange); }
.priority-dot.priority-medium { background: var(--color-status-blue); }
.priority-dot.priority-low { background: var(--color-text-tertiary); }

.card-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-primary);
    line-height: 1.4;
}

.card-description {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 8px;
    line-height: 1.4;
}

.card-milestone {
    margin-top: 6px;
    font-size: 11px;
    color: var(--color-text-tertiary);
}

.card-footer {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
}

.avatar-xs {
    width: 20px;
    height: 20px;
    font-size: 9px;
}

.card-due {
    font-size: 11px;
    color: var(--color-text-tertiary);
    margin-left: auto;
}

@media (max-width: 900px) {
    .task-board {
        grid-template-columns: repeat(2, 1fr);
    }

    .milestone-task-section-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .milestone-task-section-progress {
        width: 100%;
        min-width: 0;
    }

    .milestone-task-section-progress-bar {
        flex: 1;
        width: auto;
    }
}

@media (max-width: 600px) {
    .task-board {
        grid-template-columns: 1fr;
    }
}

/* Activity */
.activity-list {
    display: flex;
    flex-direction: column;
}

.activity-item {
    display: flex;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.activity-text {
    color: var(--color-text-primary);
    font-size: 14px;
}

.activity-time {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 4px;
}

.activity-task-link,
.activity-pr-link {
    color: var(--color-primary);
    text-decoration: none;
}

.activity-task-link:hover,
.activity-pr-link:hover {
    text-decoration: underline;
}

.activity-pr-link {
    margin-left: 4px;
    font-size: 12px;
}

/* Settings */
.settings-form {
    padding: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 6px;
}

.form-input {
    width: 100%;
    padding: 10px 12px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-primary);
    border-radius: 6px;
    color: var(--color-text-primary);
    font-size: 14px;
}

.form-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.form-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

/* Danger Zone */
.danger-zone {
    border-color: rgba(239, 68, 68, 0.3);
}

.danger-actions {
    display: flex;
    flex-direction: column;
}

.danger-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.danger-item:last-child {
    border-bottom: none;
}

.danger-title {
    font-weight: 500;
    color: var(--color-text-primary);
}

.danger-desc {
    font-size: 13px;
    color: var(--color-text-tertiary);
    margin-top: 2px;
}

.btn-danger-outline {
    background: transparent;
    border: 1px solid var(--color-status-red);
    color: var(--color-status-red);
}

.btn-danger-outline:hover {
    background: rgba(239, 68, 68, 0.1);
}

.btn-danger {
    background: var(--color-status-red);
    border-color: var(--color-status-red);
}

.btn-danger:hover {
    background: #dc2626;
}

/* Empty State */
.empty-state {
    padding: 24px;
    text-align: center;
    color: var(--color-text-tertiary);
}

/* Warning Box */
.warning-box {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 8px;
}

/* Clickable task items */
.task-item-clickable {
    cursor: pointer;
    transition: background 0.15s ease;
}

.task-item-clickable:hover {
    background: var(--color-bg-tertiary);
}

.task-row {
    cursor: pointer;
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
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
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

/* Videos */
.videos-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}

.videos-header h4 {
    margin: 0;
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
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.video-meta {
    font-size: 0.625rem;
    color: var(--color-text-tertiary);
    display: block;
    margin-top: 2px;
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

.videos-empty {
    text-align: center;
    padding: 1.5rem;
    color: var(--color-text-tertiary);
}

.videos-empty p {
    margin: 0;
    font-size: 0.875rem;
}

.videos-empty-hint {
    font-size: 0.75rem;
    margin-top: 0.25rem !important;
    opacity: 0.7;
}

/* Timeline */
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
    margin: 0;
}

.timeline-date {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
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

/* Status Badges for Activity */
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

/* GitHub Repository Styles */
.integration-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.integration-header label {
    margin: 0;
}

.linked-repos-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.linked-repo-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    background: var(--color-bg-tertiary);
    border-radius: 6px;
    border: 1px solid var(--color-border-primary);
}

.repo-info {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.repo-name {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--color-text-primary);
    text-decoration: none;
    font-weight: 500;
    font-size: 13px;
}

.repo-name:hover {
    color: var(--color-accent);
}

.repo-icon {
    flex-shrink: 0;
    color: var(--color-text-tertiary);
}

.repo-branch {
    font-size: 11px;
    color: var(--color-text-tertiary);
    background: var(--color-bg-secondary);
    padding: 2px 6px;
    border-radius: 4px;
}

.repo-stats {
    display: flex;
    gap: 8px;
}

.repo-stat {
    font-size: 11px;
    color: var(--color-text-tertiary);
}

.btn-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    background: none;
    border: none;
    border-radius: 4px;
    color: var(--color-text-tertiary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.btn-icon:hover {
    background: var(--color-bg-secondary);
    color: var(--color-text-primary);
}

.btn-remove:hover {
    color: var(--color-status-red);
}

.empty-repos {
    text-align: center;
    padding: 20px;
    color: var(--color-text-tertiary);
}

.empty-repos p {
    margin: 0;
    font-size: 13px;
}

.empty-hint {
    font-size: 12px;
    margin-top: 4px !important;
    opacity: 0.7;
}

/* Repo Modal */
.repo-modal-content {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.loading-state {
    text-align: center;
    padding: 24px;
    color: var(--color-text-tertiary);
}

.repo-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.section-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin: 0;
}

.repo-search {
    margin-bottom: 12px;
}

.repo-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    max-height: 300px;
    overflow-y: auto;
}

.repo-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    background: var(--color-bg-tertiary);
    border-radius: 6px;
    border: 1px solid var(--color-border-primary);
}

.repo-option.linked {
    opacity: 0.6;
}

.repo-option-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
}

.repo-option-name {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: var(--color-text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.repo-icon-sm {
    flex-shrink: 0;
    color: var(--color-text-tertiary);
}

.match-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
    border-radius: 4px;
}

.match-badge-subtle {
    font-size: 10px;
    padding: 2px 6px;
    background: var(--color-bg-secondary);
    color: var(--color-text-tertiary);
    border-radius: 4px;
}

.linked-badge {
    font-size: 11px;
    color: var(--color-status-green);
    font-weight: 500;
}

/* Effort Estimation */
.effort-estimation-section {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border-primary);
}

.effort-results {
    margin-top: 16px;
}

.effort-success {
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    padding: 16px;
}

.effort-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 16px;
}

.effort-total {
    display: flex;
    flex-direction: column;
}

.effort-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--color-accent);
    line-height: 1;
}

.effort-label {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 4px;
}

.effort-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.effort-breakdown {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.breakdown-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: var(--color-bg-secondary);
    border-radius: 4px;
    font-size: 12px;
}

.breakdown-type {
    color: var(--color-text-secondary);
    text-transform: capitalize;
}

.breakdown-hours {
    font-weight: 600;
    color: var(--color-text-primary);
}

.effort-repos {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.repo-effort {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
}

.repo-effort-name {
    color: var(--color-text-primary);
    font-weight: 500;
}

.repo-effort-stats {
    color: var(--color-text-tertiary);
}

.effort-error {
    padding: 16px;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 8px;
    color: var(--color-status-red);
    font-size: 13px;
}

/* Import Modal Styles */
.import-modal {
    padding: 0;
}

.import-tabs {
    display: flex;
    gap: 4px;
    padding: 0 0 16px 0;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: 16px;
}

.import-tab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 6px;
    color: var(--color-text-secondary);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.15s;
}

.import-tab:hover {
    background: var(--color-surface-elevated);
}

.import-tab.active {
    background: var(--color-surface-elevated);
    border-color: var(--color-border);
    color: var(--color-text-primary);
}

.import-content {
    min-height: 300px;
}

.import-help {
    color: var(--color-text-secondary);
    font-size: 13px;
    margin-bottom: 12px;
}

.import-textarea {
    width: 100%;
    padding: 12px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    color: var(--color-text-primary);
    font-size: 13px;
    font-family: inherit;
    resize: vertical;
    min-height: 200px;
}

.import-textarea:focus {
    outline: none;
    border-color: var(--color-accent);
}

.file-upload-zone {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 32px;
    background: var(--color-surface);
    border: 2px dashed var(--color-border);
    border-radius: 8px;
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s;
}

.file-upload-zone:hover {
    border-color: var(--color-accent);
    background: var(--color-surface-elevated);
}

.parsed-tasks {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.parsed-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--color-border);
}

.parsed-info {
    font-size: 13px;
    color: var(--color-text-secondary);
}

.parsed-actions {
    display: flex;
    gap: 8px;
}

.parsed-list {
    max-height: 400px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.parsed-task {
    display: flex;
    gap: 12px;
    padding: 12px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s;
}

.parsed-task:hover {
    background: var(--color-surface-elevated);
}

.parsed-task.selected {
    border-color: var(--color-accent);
    background: rgba(59, 130, 246, 0.05);
}

.parsed-task-content {
    flex: 1;
    min-width: 0;
}

.parsed-task-title {
    font-weight: 500;
    color: var(--color-text-primary);
    margin-bottom: 4px;
}

.parsed-task-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}

.parsed-task-meta .status-badge {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 4px;
    background: var(--color-surface-elevated);
    color: var(--color-text-secondary);
}

.parsed-task-meta .status-badge.completed {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.parsed-task-meta .status-badge.in_progress {
    background: rgba(59, 130, 246, 0.15);
    color: var(--color-status-blue);
}

.parsed-task-meta .priority-badge,
.parsed-task-meta .hours-badge {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 4px;
    background: var(--color-surface-elevated);
    color: var(--color-text-tertiary);
}

.parsed-task-desc {
    font-size: 12px;
    color: var(--color-text-tertiary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.parsed-footer {
    padding-top: 12px;
    border-top: 1px solid var(--color-border);
    display: flex;
    justify-content: flex-end;
}

.btn-sm {
    padding: 4px 8px;
    font-size: 12px;
}

/* Timeline Tab */
.timeline-empty-state {
    padding: 48px 24px;
    text-align: center;
}

.timeline-empty-state h3 {
    margin-bottom: 4px;
}

.timeline-date-setup {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    justify-content: center;
    max-width: 500px;
    margin: 0 auto;
}

.timeline-date-setup .form-group {
    flex: 1;
    text-align: left;
}

.timeline-status-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid;
    margin-bottom: 16px;
}

.timeline-status-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.timeline-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.timeline-status-label {
    font-size: 14px;
    font-weight: 600;
}

.timeline-status-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.timeline-status-divider {
    color: var(--color-text-quaternary);
}

.timeline-bar-container {
    position: relative;
}

.timeline-bar {
    position: relative;
    height: 32px;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    overflow: visible;
}

.timeline-segment {
    position: absolute;
    top: 0;
    height: 100%;
    border-right: 1px solid;
    overflow: hidden;
    cursor: default;
}

.timeline-segment:first-child {
    border-radius: 6px 0 0 6px;
}

.timeline-segment:last-child {
    border-radius: 0 6px 6px 0;
    border-right: none;
}

.timeline-segment-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s ease;
}

.timeline-today-marker {
    position: absolute;
    top: -18px;
    bottom: 0;
    transform: translateX(-50%);
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    pointer-events: none;
}

.timeline-today-line {
    width: 2px;
    flex: 1;
    background: #ef4444;
}

.timeline-today-label {
    font-size: 10px;
    font-weight: 600;
    color: #ef4444;
    line-height: 1;
    margin-bottom: 2px;
}

.timeline-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 16px;
    margin-top: 12px;
}

.timeline-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    white-space: nowrap;
}

.timeline-legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    flex-shrink: 0;
}

.timeline-legend-name {
    color: var(--color-text-secondary);
    font-weight: 500;
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.timeline-legend-count {
    color: var(--color-text-quaternary);
    font-size: 11px;
}

.timeline-stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.timeline-stat-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-primary);
    border-radius: 8px;
    padding: 16px;
    text-align: center;
}

.timeline-stat-clickable {
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
}

.timeline-stat-clickable:hover {
    border-color: #ef4444;
    background: color-mix(in srgb, #ef4444 6%, var(--color-bg-secondary));
}

.timeline-stat-value {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: var(--color-text-primary);
    line-height: 1.2;
}

.timeline-stat-label {
    display: block;
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 4px;
}

.milestone-cards {
    padding: 16px;
    display: grid;
    gap: 12px;
}

.milestone-detail-card {
    border: 1px solid var(--color-border-primary);
    border-radius: 8px;
    padding: 14px 16px;
}

.milestone-detail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}

.milestone-detail-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-primary);
}

.milestone-detail-status {
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 10px;
    text-transform: capitalize;
}

.milestone-detail-status.status-completed {
    background: rgba(34, 197, 94, 0.1);
    color: #22c55e;
}

.milestone-detail-status.status-in_progress {
    background: rgba(59, 130, 246, 0.1);
    color: #3b82f6;
}

.milestone-detail-status.status-pending {
    background: rgba(156, 163, 175, 0.1);
    color: #9ca3af;
}

.milestone-detail-progress {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.milestone-detail-bar {
    flex: 1;
    height: 6px;
    background: var(--color-bg-tertiary);
    border-radius: 3px;
    overflow: hidden;
}

.milestone-detail-bar-fill {
    height: 100%;
    background: var(--color-accent);
    border-radius: 3px;
    transition: width 0.3s ease;
}

.milestone-detail-pct {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-secondary);
    min-width: 36px;
    text-align: right;
}

.milestone-detail-meta {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--color-text-tertiary);
}

/* Distribution Modal */
.distribute-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--color-text-secondary);
    cursor: pointer;
}

.distribute-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--color-accent);
}

.distribute-preview-table {
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid var(--color-border-primary);
    border-radius: 8px;
}

.distribute-preview-table table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.distribute-preview-table th {
    background: var(--color-bg-tertiary);
    padding: 8px 12px;
    text-align: left;
    font-weight: 500;
    color: var(--color-text-secondary);
    position: sticky;
    top: 0;
}

.distribute-preview-table td {
    padding: 8px 12px;
    border-top: 1px solid var(--color-border-subtle);
    color: var(--color-text-secondary);
}

.distribute-preview-table tr.changed td {
    background: rgba(59, 130, 246, 0.04);
}

.text-new-date {
    color: var(--color-accent) !important;
    font-weight: 500;
}

/* Completion Chart */
.completion-chart-card {
    --vis-crosshair-line-stroke-color: var(--color-border-default);
    --vis-crosshair-circle-stroke-color: #3b82f6;
    --vis-tooltip-background-color: transparent;
    --vis-tooltip-border-color: transparent;
    --vis-tooltip-text-color: inherit;
    --vis-tooltip-shadow-color: transparent;
}

.completion-chart {
    --vis-axis-tick-label-color: var(--color-text-quaternary);
    --vis-axis-grid-color: var(--color-border-subtle);
    --vis-axis-tick-label-font-size: 10px;
}

.completion-chart :deep(.unovis-xy-container) {
    overflow: visible;
}

.completion-chart :deep(.unovis-tooltip) {
    pointer-events: none;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}

.chart-legend {
    display: flex;
    gap: 16px;
    justify-content: center;
    margin-top: 8px;
}

.chart-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.chart-legend-swatch {
    width: 12px;
    height: 3px;
    border-radius: 2px;
}

.chart-legend-dashed {
    background: repeating-linear-gradient(
        to right,
        currentColor 0,
        currentColor 4px,
        transparent 4px,
        transparent 8px
    ) !important;
    background-color: transparent !important;
    color: #9ca3af;
}

@media (max-width: 768px) {
    .timeline-stats-row {
        grid-template-columns: repeat(2, 1fr);
    }

    .timeline-status-banner {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }

    .timeline-date-setup {
        flex-direction: column;
    }
}
</style>
