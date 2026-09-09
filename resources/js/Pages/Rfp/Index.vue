<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormTextarea from '@/Components/FormTextarea.vue'
import draggable from 'vuedraggable'
import { ref, reactive, computed, watch } from 'vue'
import { router, Link, useForm } from '@inertiajs/vue3'

interface RfpOpportunity {
    id: number
    title: string
    slug: string
    issuing_organization: string
    description: string | null
    source_type: string
    budget_min: number | null
    budget_max: number | null
    budget_range: string
    submission_deadline: string | null
    submission_deadline_display: string | null
    contact_name: string | null
    contact_email: string | null
    status: string
    priority: string
    fit_score: number
    decline_reason: string | null
    assignee: { id: number; name: string } | null
    is_expired: boolean
    tags: string[] | null
    created_at: string
}

interface Stats {
    total_active: number
    proposals_in_progress: number
    avg_fit_score: number
    submitted_this_month: number
    win_rate: number
}

const props = defineProps<{
    opportunities: RfpOpportunity[]
    stats: Stats
}>()

// Modal states
const showCreateModal = ref(false)
const showDetailModal = ref(false)
const showCloseModal = ref(false)
const showDeleteModal = ref(false)
const showScanModal = ref(false)
const scanForm = useForm({ from: '', days: 14 })
const editingRfp = ref<RfpOpportunity | null>(null)
const selectedRfp = ref<RfpOpportunity | null>(null)
const closingRfp = ref<RfpOpportunity | null>(null)
const rfpToDelete = ref<RfpOpportunity | null>(null)
const closeType = ref<'won' | 'lost'>('won')
const isSaving = ref(false)
const isDeleting = ref(false)

// Form
const rfpForm = reactive({
    title: '',
    issuing_organization: '',
    description: '',
    source_type: 'manual',
    budget_min: '',
    budget_max: '',
    submission_deadline: '',
    contact_name: '',
    contact_email: '',
    priority: 'medium',
})

// Close form
const closeForm = reactive({
    decline_reason: '',
    close_notes: '',
})

const statuses = [
    { key: 'discovered', label: 'Discovered', color: 'var(--color-text-tertiary)' },
    { key: 'evaluating', label: 'Evaluating', color: 'var(--color-status-blue)' },
    { key: 'qualified', label: 'Qualified', color: '#6366f1' },
    { key: 'pursuing', label: 'Pursuing', color: 'var(--color-status-orange)' },
    { key: 'proposal_drafting', label: 'Drafting', color: '#a855f7' },
    { key: 'proposal_review', label: 'Review', color: 'var(--color-status-orange)' },
    { key: 'submitted', label: 'Submitted', color: '#14b8a6' },
    { key: 'won', label: 'Won', color: 'var(--color-status-green)' },
    { key: 'lost', label: 'Lost', color: 'var(--color-status-red)' },
]

const activeStatuses = statuses.filter(s => !['won', 'lost'].includes(s.key))
const statusOptions = statuses.map(s => ({ value: s.key, label: s.label }))

const sourceTypeOptions = [
    { value: 'manual', label: 'Manual Entry' },
    { value: 'email_teaser', label: 'Email' },
    { value: 'sam_gov', label: 'SAM.gov' },
    { value: 'rfp_board', label: 'RFP Board' },
    { value: 'web_scrape', label: 'Web Scrape' },
]

const priorityOptions = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
]

const declineReasonOptions = [
    { value: '', label: 'Select reason...' },
    { value: 'budget_mismatch', label: 'Budget mismatch' },
    { value: 'scope_mismatch', label: 'Scope mismatch' },
    { value: 'timeline_too_tight', label: 'Timeline too tight' },
    { value: 'competitor_advantage', label: 'Competitor advantage' },
    { value: 'resource_constraints', label: 'Resource constraints' },
    { value: 'low_fit_score', label: 'Low fit score' },
    { value: 'other', label: 'Other' },
]

// Local state for optimistic drag-and-drop
const localOpportunities = ref<RfpOpportunity[]>([...props.opportunities])

// Mutable arrays for each status that vuedraggable can modify directly
const statusArrays = reactive<Record<string, RfpOpportunity[]>>({})

const initStatusArrays = () => {
    for (const status of statuses) {
        statusArrays[status.key] = localOpportunities.value
            .filter(o => o.status === status.key)
            .sort((a, b) => (b.fit_score ?? 0) - (a.fit_score ?? 0))
    }
}

initStatusArrays()

watch(() => props.opportunities, (newOpps) => {
    localOpportunities.value = [...newOpps]
    initStatusArrays()
}, { deep: true })

const opportunitiesByStatus = computed(() => statusArrays)

const onDragChange = (evt: any, targetStatus: string) => {
    if (targetStatus === 'won' || targetStatus === 'lost') {
        const item = evt.added?.element
        if (item) {
            localOpportunities.value = [...props.opportunities]
            initStatusArrays()
            openCloseRfp(item, targetStatus as 'won' | 'lost')
        }
        return
    }

    if (!evt.moved && !evt.added) {
        return
    }

    const movedRfp = evt.moved?.element || evt.added?.element
    if (!movedRfp) return

    if (evt.added) {
        movedRfp.status = targetStatus
    }

    // Update status on server
    router.put(`/rfp/${movedRfp.id}/status`, { status: targetStatus }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            localOpportunities.value = [...props.opportunities]
            initStatusArrays()
        }
    })
}

const modalTitle = computed(() => editingRfp.value ? 'Edit RFP Opportunity' : 'New RFP Opportunity')

const resetForm = () => {
    rfpForm.title = ''
    rfpForm.issuing_organization = ''
    rfpForm.description = ''
    rfpForm.source_type = 'manual'
    rfpForm.budget_min = ''
    rfpForm.budget_max = ''
    rfpForm.submission_deadline = ''
    rfpForm.contact_name = ''
    rfpForm.contact_email = ''
    rfpForm.priority = 'medium'
}

// Upload RFP
const isUploading = ref(false)
const isDragging = ref(false)
const uploadUrl = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

const handleFileDrop = (e: DragEvent) => {
    isDragging.value = false
    const file = e.dataTransfer?.files?.[0]
    if (file && file.type === 'application/pdf') {
        uploadFile(file)
    }
}

const handleFileSelect = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (file) uploadFile(file)
}

const uploadFile = (file: File) => {
    isUploading.value = true
    const formData = new FormData()
    formData.append('file', file)
    router.post('/rfp/upload', formData, {
        forceFormData: true,
        onFinish: () => isUploading.value = false,
    })
}

const uploadFromUrl = () => {
    if (!uploadUrl.value) return
    isUploading.value = true
    router.post('/rfp/upload', { url: uploadUrl.value }, {
        onFinish: () => {
            isUploading.value = false
            uploadUrl.value = ''
        },
    })
}

const openNewRfp = () => {
    editingRfp.value = null
    resetForm()
    showCreateModal.value = true
}

const openEditRfp = (rfp: RfpOpportunity, e?: Event) => {
    e?.preventDefault()
    e?.stopPropagation()
    editingRfp.value = rfp
    rfpForm.title = rfp.title
    rfpForm.issuing_organization = rfp.issuing_organization
    rfpForm.description = rfp.description || ''
    rfpForm.source_type = rfp.source_type
    rfpForm.budget_min = rfp.budget_min?.toString() || ''
    rfpForm.budget_max = rfp.budget_max?.toString() || ''
    rfpForm.submission_deadline = rfp.submission_deadline ? rfp.submission_deadline.slice(0, 10) : ''
    rfpForm.contact_name = rfp.contact_name || ''
    rfpForm.contact_email = rfp.contact_email || ''
    rfpForm.priority = rfp.priority
    showCreateModal.value = true
}

const closeCreateModal = () => {
    showCreateModal.value = false
    editingRfp.value = null
    resetForm()
}

const saveRfp = () => {
    isSaving.value = true
    const data = { ...rfpForm }

    if (editingRfp.value) {
        router.put(`/rfp/${editingRfp.value.id}`, data, {
            onSuccess: () => closeCreateModal(),
            onFinish: () => isSaving.value = false,
        })
    } else {
        router.post('/rfp', data, {
            onSuccess: () => closeCreateModal(),
            onFinish: () => isSaving.value = false,
        })
    }
}

const openRfpDetail = (rfp: RfpOpportunity) => {
    selectedRfp.value = rfp
    showDetailModal.value = true
}

const closeDetailModal = () => {
    showDetailModal.value = false
    selectedRfp.value = null
}

const changeStatus = (rfp: RfpOpportunity, newStatus: string) => {
    if (newStatus === 'won' || newStatus === 'lost') {
        openCloseRfp(rfp, newStatus as 'won' | 'lost')
        return
    }
    router.put(`/rfp/${rfp.id}/status`, { status: newStatus }, {
        preserveScroll: true,
    })
}

const openCloseRfp = (rfp: RfpOpportunity, type: 'won' | 'lost') => {
    closingRfp.value = rfp
    closeType.value = type
    closeForm.decline_reason = ''
    closeForm.close_notes = ''
    showCloseModal.value = true
}

const confirmCloseRfp = () => {
    if (!closingRfp.value) return
    isSaving.value = true

    const data: Record<string, string> = {
        status: closeType.value,
    }

    if (closeType.value === 'lost' && closeForm.decline_reason) {
        data.decline_reason = closeForm.decline_reason
    }

    router.put(`/rfp/${closingRfp.value.id}/status`, data, {
        onSuccess: () => {
            showCloseModal.value = false
            closingRfp.value = null
        },
        onFinish: () => isSaving.value = false,
    })
}

const declineForm = reactive({
    decline_category: '',
    decline_notes: '',
})

const declineCategories = [
    { value: 'wrong_industry', label: 'Wrong industry / not our niche' },
    { value: 'budget_too_low', label: 'Budget too low' },
    { value: 'budget_too_high', label: 'Budget too high / too complex' },
    { value: 'wrong_tech_stack', label: 'Wrong technology stack' },
    { value: 'scope_mismatch', label: 'Scope doesn\'t match our services' },
    { value: 'too_competitive', label: 'Too competitive / low win chance' },
    { value: 'timeline_unrealistic', label: 'Timeline unrealistic' },
    { value: 'not_qualified', label: 'We\'re not qualified' },
    { value: 'already_awarded', label: 'Already awarded to someone else' },
    { value: 'geographic', label: 'Geographic / location mismatch' },
    { value: 'other', label: 'Other' },
]

const openDeleteModal = (rfp: RfpOpportunity, e?: Event) => {
    e?.preventDefault()
    e?.stopPropagation()
    rfpToDelete.value = rfp
    declineForm.decline_category = ''
    declineForm.decline_notes = ''
    showDeleteModal.value = true
}

const confirmDelete = () => {
    if (!rfpToDelete.value || !declineForm.decline_category) return
    isDeleting.value = true
    router.delete(`/rfp/${rfpToDelete.value.id}`, {
        data: {
            decline_category: declineForm.decline_category,
            decline_notes: declineForm.decline_notes,
        },
        onSuccess: () => {
            showDeleteModal.value = false
            rfpToDelete.value = null
        },
        onFinish: () => isDeleting.value = false,
    })
}

const reopenRfp = (rfp: RfpOpportunity) => {
    router.put(`/rfp/${rfp.id}/status`, { status: 'evaluating' }, {
        preserveScroll: true,
    })
}

const formatBudget = (value: number | null) => {
    if (!value) return null
    const num = Number(value)
    if (num >= 1000000) return `$${(num / 1000000).toFixed(1)}M`
    if (num >= 1000) return `$${(num / 1000).toFixed(0)}K`
    return `$${num.toFixed(0)}`
}

const formatBudgetRange = (rfp: RfpOpportunity) => {
    if (rfp.budget_min && rfp.budget_max) {
        return `${formatBudget(rfp.budget_min)} - ${formatBudget(rfp.budget_max)}`
    }
    if (rfp.budget_min) return `From ${formatBudget(rfp.budget_min)}`
    if (rfp.budget_max) return `Up to ${formatBudget(rfp.budget_max)}`
    return null
}

const deadlineCountdown = (rfp: RfpOpportunity) => {
    if (!rfp.submission_deadline) return null
    const deadline = new Date(rfp.submission_deadline)
    const now = new Date()
    const diffMs = deadline.getTime() - now.getTime()
    const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24))

    if (diffDays < 0) return { text: 'Expired', color: 'var(--color-status-red)' }
    if (diffDays === 0) return { text: 'Due today', color: 'var(--color-status-red)' }
    if (diffDays === 1) return { text: '1 day left', color: 'var(--color-status-orange)' }
    if (diffDays <= 7) return { text: `${diffDays} days left`, color: 'var(--color-status-orange)' }
    return { text: `${diffDays} days left`, color: 'var(--color-text-tertiary)' }
}

const fitScoreColor = (score: number) => {
    if (score >= 70) return { bg: 'rgba(34, 197, 94, 0.15)', text: 'var(--color-status-green)' }
    if (score >= 40) return { bg: 'rgba(234, 179, 8, 0.15)', text: '#eab308' }
    return { bg: 'rgba(239, 68, 68, 0.15)', text: 'var(--color-status-red)' }
}

const truncate = (text: string, len: number) => {
    if (text.length <= len) return text
    return text.slice(0, len) + '...'
}

const getStatusColor = (status: string) => {
    return statuses.find(s => s.key === status)?.color || 'var(--color-text-tertiary)'
}
</script>

<template>
    <AppLayout title="RFP Pipeline">
        <!-- Sub-nav -->
        <div class="sub-nav-bar">
            <div class="sub-nav">
                <Link href="/rfp" class="sub-nav-link sub-nav-active">Pipeline</Link>
                <Link href="/rfp/sources" class="sub-nav-link">Sources</Link>
                <Link href="/rfp/learning" class="sub-nav-link">Learning</Link>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-8 mb-6">
            <div class="metric-card">
                <div class="metric-label">ACTIVE</div>
                <div class="metric-value">{{ stats.total_active }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PENDING EVAL</div>
                <div class="metric-value" style="color: var(--color-status-yellow);">{{ stats.pending_evaluation }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">RFPs FOUND</div>
                <div class="metric-value" style="color: #8b5cf6;">{{ stats.with_documents }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PARSED</div>
                <div class="metric-value" style="color: #06b6d4;">{{ stats.with_requirements }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PROPOSALS</div>
                <div class="metric-value" style="color: var(--color-accent);">{{ stats.proposals_in_progress }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">AVG FIT</div>
                <div class="metric-value">{{ stats.avg_fit_score }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">SUBMITTED</div>
                <div class="metric-value" style="color: #14b8a6;">{{ stats.submitted_this_month }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">WIN RATE</div>
                <div class="metric-value" style="color: var(--color-status-green);">{{ stats.win_rate }}%</div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar mb-4">
            <div class="toolbar-left">
                <span class="text-caption">Drag cards between columns to update status</span>
            </div>

            <div class="flex items-center gap-2">
                <button class="btn btn-secondary" @click="showScanModal = true">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M14 7A7 7 0 1 1 3.5 2.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M14 1v3h-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Scan Gmail
                </button>
                <button class="btn btn-secondary" @click="router.post('/rfp/evaluate-all')">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M9 3L5 8h4l-1 5 4-5H8l1-5z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Evaluate All
                </button>
                <button class="btn btn-secondary" @click="router.post('/rfp/retrieve-all-documents')">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M14 14l-4-4m2-3a5 5 0 1 1-10 0 5 5 0 0 1 10 0z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Find All RFPs
                </button>
                <button class="btn btn-primary" @click="openNewRfp()">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    New Opportunity
                </button>
            </div>
        </div>

        <!-- Upload Drop Zone -->
        <div
            class="upload-zone"
            :class="{ 'upload-zone-active': isDragging, 'upload-zone-uploading': isUploading }"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="handleFileDrop"
        >
            <input ref="fileInput" type="file" accept=".pdf" class="upload-input-hidden" @change="handleFileSelect" />
            <div v-if="isUploading" class="upload-status">
                <svg class="spin" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                </svg>
                Parsing RFP document...
            </div>
            <div v-else class="upload-content">
                <div class="upload-row">
                    <button class="upload-btn" @click="fileInput?.click()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        Upload PDF
                    </button>
                    <span class="upload-or">or drag PDF here</span>
                    <span class="upload-divider">|</span>
                    <input
                        v-model="uploadUrl"
                        class="upload-url-input"
                        placeholder="Paste RFP URL..."
                        @keydown.enter="uploadFromUrl"
                    />
                    <button
                        v-if="uploadUrl"
                        class="btn btn-primary btn-sm"
                        @click="uploadFromUrl"
                    >
                        Import
                    </button>
                </div>
            </div>
        </div>

        <!-- Pipeline View -->
        <div class="pipeline">
            <div
                v-for="status in activeStatuses"
                :key="status.key"
                class="pipeline-column"
            >
                <div class="column-header">
                    <div class="column-title">
                        <div class="stage-dot" :style="{ background: status.color }"></div>
                        {{ status.label }}
                    </div>
                    <div class="column-meta">
                        <span class="column-count">{{ opportunitiesByStatus[status.key]?.length || 0 }}</span>
                    </div>
                </div>
                <draggable
                    :list="opportunitiesByStatus[status.key]"
                    group="rfp"
                    item-key="id"
                    class="column-cards"
                    ghost-class="rfp-ghost"
                    drag-class="rfp-dragging"
                    :animation="150"
                    @change="(evt: any) => onDragChange(evt, status.key)"
                >
                    <template #item="{ element: rfp }">
                        <div :class="['rfp-card', { 'rfp-card-expired': rfp.is_expired }]" @click="openRfpDetail(rfp)">
                            <div class="rfp-header">
                                <span class="rfp-title">{{ truncate(rfp.title, 60) }}</span>
                                <div class="rfp-actions">
                                    <button class="action-btn action-btn-evaluate" @click.stop="router.post(`/rfp/${rfp.id}/evaluate`, {}, { preserveScroll: true })" title="Evaluate">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3L5 8h4l-1 5 4-5H8l1-5z" />
                                        </svg>
                                    </button>
                                    <button class="action-btn action-btn-search" @click.stop="router.post(`/rfp/${rfp.id}/retrieve-document`, {}, { preserveScroll: true })" title="Find Full RFP">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </button>
                                    <button class="action-btn" @click="openEditRfp(rfp, $event)" title="Edit">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button class="action-btn action-btn-danger" @click="openDeleteModal(rfp, $event)" title="Delete">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="rfp-org">{{ rfp.issuing_organization }}</div>

                            <div class="rfp-badges">
                                <!-- Expired -->
                                <span v-if="rfp.is_expired" class="expired-badge">
                                    Expired
                                </span>

                                <!-- Budget -->
                                <span v-if="formatBudgetRange(rfp)" class="budget-badge">
                                    {{ formatBudgetRange(rfp) }}
                                </span>

                                <!-- Fit Score -->
                                <span
                                    v-if="rfp.fit_score > 0"
                                    class="fit-badge"
                                    :style="{ background: fitScoreColor(rfp.fit_score).bg, color: fitScoreColor(rfp.fit_score).text }"
                                >
                                    {{ rfp.fit_score }}% fit
                                </span>

                                <!-- Priority -->
                                <span
                                    v-if="rfp.priority === 'high' || rfp.priority === 'critical'"
                                    class="priority-badge"
                                    :class="`priority-${rfp.priority}`"
                                >
                                    {{ rfp.priority }}
                                </span>

                                <!-- Document status -->
                                <span v-if="rfp.has_requirements" class="doc-badge doc-parsed" title="Full RFP parsed">
                                    RFP
                                </span>
                                <span v-else-if="rfp.has_document" class="doc-badge doc-found" title="Document found, parsing...">
                                    DOC
                                </span>
                            </div>

                            <div class="rfp-footer">
                                <span class="source-badge">
                                    {{ rfp.source_type.replace('_', ' ') }}
                                </span>
                                <button
                                    v-if="rfp.status === 'qualified'"
                                    class="pursue-btn"
                                    @click.stop="router.put(`/rfp/${rfp.id}/status`, { status: 'pursuing' }, { preserveScroll: true })"
                                >
                                    Pursue
                                </button>
                                <span
                                    v-else-if="deadlineCountdown(rfp)"
                                    class="deadline-badge"
                                    :style="{ color: deadlineCountdown(rfp)!.color }"
                                >
                                    {{ deadlineCountdown(rfp)!.text }}
                                </span>
                            </div>
                        </div>
                    </template>
                    <template #footer>
                        <button class="add-rfp-btn" @click="openNewRfp()">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Add opportunity
                        </button>
                    </template>
                </draggable>
            </div>
        </div>

        <!-- Won/Lost Summary -->
        <div class="closed-deals">
            <div class="closed-section">
                <h3>
                    <span class="stage-dot" style="background: var(--color-status-green);"></span>
                    Won ({{ opportunitiesByStatus['won']?.length || 0 }})
                </h3>
                <div class="closed-list">
                    <div v-for="rfp in opportunitiesByStatus['won']?.slice(0, 5)" :key="rfp.id" class="closed-item won">
                        <span>{{ rfp.title }}</span>
                        <div class="closed-actions">
                            <span v-if="formatBudgetRange(rfp)">{{ formatBudgetRange(rfp) }}</span>
                            <button class="reopen-btn" @click="reopenRfp(rfp)" title="Reopen">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div v-if="!opportunitiesByStatus['won']?.length" class="empty-closed">No won RFPs yet</div>
                </div>
            </div>
            <div class="closed-section">
                <h3>
                    <span class="stage-dot" style="background: var(--color-status-red);"></span>
                    Lost ({{ opportunitiesByStatus['lost']?.length || 0 }})
                </h3>
                <div class="closed-list">
                    <div v-for="rfp in opportunitiesByStatus['lost']?.slice(0, 5)" :key="rfp.id" class="closed-item lost">
                        <span>{{ rfp.title }}</span>
                        <div class="closed-actions">
                            <span v-if="rfp.decline_reason" class="decline-reason">{{ rfp.decline_reason.replace('_', ' ') }}</span>
                            <button class="reopen-btn" @click="reopenRfp(rfp)" title="Reopen">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div v-if="!opportunitiesByStatus['lost']?.length" class="empty-closed">No lost RFPs</div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showCreateModal" size="lg" @close="closeCreateModal">
            <template #header>
                <h2 class="modal-title">{{ modalTitle }}</h2>
            </template>

            <form @submit.prevent="saveRfp">
                <!-- RFP Info -->
                <div class="form-section">
                    <h3 class="form-section-title">Opportunity Details</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="rfpForm.title"
                            label="Title"
                            placeholder="Website Redesign RFP"
                            required
                        />
                        <FormInput
                            v-model="rfpForm.issuing_organization"
                            label="Issuing Organization"
                            placeholder="Acme Corp"
                            required
                        />
                    </div>
                    <FormTextarea
                        v-model="rfpForm.description"
                        label="Description"
                        placeholder="Brief description of the RFP opportunity..."
                        :rows="2"
                    />
                </div>

                <!-- Budget & Timeline -->
                <div class="form-section">
                    <h3 class="form-section-title">Budget & Timeline</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="rfpForm.budget_min"
                            label="Budget Min"
                            placeholder="50000"
                            type="number"
                            prefix="$"
                        />
                        <FormInput
                            v-model="rfpForm.budget_max"
                            label="Budget Max"
                            placeholder="100000"
                            type="number"
                            prefix="$"
                        />
                    </div>
                    <div class="form-grid">
                        <FormInput
                            v-model="rfpForm.submission_deadline"
                            label="Submission Deadline"
                            type="date"
                        />
                        <FormSelect
                            v-model="rfpForm.priority"
                            label="Priority"
                            :options="priorityOptions"
                        />
                    </div>
                </div>

                <!-- Contact & Source -->
                <div class="form-section">
                    <h3 class="form-section-title">Contact & Source</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="rfpForm.contact_name"
                            label="Contact Name"
                            placeholder="John Doe"
                        />
                        <FormInput
                            v-model="rfpForm.contact_email"
                            label="Contact Email"
                            placeholder="john@example.com"
                            type="email"
                        />
                    </div>
                    <div class="form-grid">
                        <FormSelect
                            v-model="rfpForm.source_type"
                            label="Source Type"
                            :options="sourceTypeOptions"
                        />
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeCreateModal">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSaving || !rfpForm.title || !rfpForm.issuing_organization"
                        @click="saveRfp"
                    >
                        {{ isSaving ? 'Saving...' : (editingRfp ? 'Update Opportunity' : 'Create Opportunity') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Detail Modal -->
        <Modal :show="showDetailModal" size="lg" @close="closeDetailModal">
            <template #header>
                <div class="detail-header">
                    <h2 class="modal-title">{{ selectedRfp?.title }}</h2>
                    <span
                        v-if="selectedRfp && selectedRfp.fit_score > 0"
                        class="fit-badge-lg"
                        :style="{ background: fitScoreColor(selectedRfp.fit_score).bg, color: fitScoreColor(selectedRfp.fit_score).text }"
                    >
                        {{ selectedRfp.fit_score }}% fit
                    </span>
                </div>
            </template>

            <div v-if="selectedRfp" class="rfp-detail">
                <!-- Status -->
                <div class="detail-row">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span
                            class="status-badge"
                            :style="{ background: getStatusColor(selectedRfp.status) + '20', color: getStatusColor(selectedRfp.status) }"
                        >
                            {{ statuses.find(s => s.key === selectedRfp!.status)?.label || selectedRfp.status }}
                        </span>
                    </div>
                </div>

                <!-- Organization -->
                <div class="detail-section">
                    <h4>Organization</h4>
                    <div class="detail-row">
                        <div class="detail-label">Name</div>
                        <div class="detail-value">{{ selectedRfp.issuing_organization }}</div>
                    </div>
                </div>

                <!-- Budget & Deadline -->
                <div class="detail-section">
                    <h4>Budget & Timeline</h4>
                    <div class="detail-row">
                        <div class="detail-label">Budget Range</div>
                        <div class="detail-value">{{ selectedRfp.budget_range }}</div>
                    </div>
                    <div v-if="selectedRfp.submission_deadline_display" class="detail-row">
                        <div class="detail-label">Deadline</div>
                        <div class="detail-value">
                            {{ selectedRfp.submission_deadline_display }}
                            <span
                                v-if="deadlineCountdown(selectedRfp)"
                                class="ml-2 text-xs"
                                :style="{ color: deadlineCountdown(selectedRfp)!.color }"
                            >
                                ({{ deadlineCountdown(selectedRfp)!.text }})
                            </span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Priority</div>
                        <div class="detail-value">
                            <span class="priority-badge" :class="`priority-${selectedRfp.priority}`">
                                {{ selectedRfp.priority }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <div v-if="selectedRfp.contact_name || selectedRfp.contact_email" class="detail-section">
                    <h4>Contact</h4>
                    <div v-if="selectedRfp.contact_name" class="detail-row">
                        <div class="detail-label">Name</div>
                        <div class="detail-value">{{ selectedRfp.contact_name }}</div>
                    </div>
                    <div v-if="selectedRfp.contact_email" class="detail-row">
                        <div class="detail-label">Email</div>
                        <div class="detail-value">
                            <a :href="`mailto:${selectedRfp.contact_email}`">{{ selectedRfp.contact_email }}</a>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div v-if="selectedRfp.description" class="detail-section">
                    <h4>Description</h4>
                    <p class="detail-description">{{ selectedRfp.description }}</p>
                </div>

                <!-- Quick Actions -->
                <div class="detail-actions">
                    <Link v-if="selectedRfp" :href="`/rfp/${selectedRfp.id}`" class="btn btn-primary">
                        View Full Details
                    </Link>
                    <button class="btn btn-secondary" @click="openEditRfp(selectedRfp); closeDetailModal()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit
                    </button>
                    <button class="btn btn-success" @click="openCloseRfp(selectedRfp, 'won'); closeDetailModal()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Mark Won
                    </button>
                    <button class="btn btn-danger-outline" @click="openCloseRfp(selectedRfp, 'lost'); closeDetailModal()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Mark Lost
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Close RFP Modal -->
        <Modal :show="showCloseModal" size="md" @close="showCloseModal = false">
            <template #header>
                <h2 class="modal-title">
                    {{ closeType === 'won' ? 'Mark as Won' : 'Mark as Lost' }}
                </h2>
            </template>

            <div class="close-deal-form">
                <div v-if="closeType === 'won'" class="won-header">
                    <div class="won-icon">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p>Congratulations on winning <strong>{{ closingRfp?.title }}</strong>!</p>
                </div>

                <div v-if="closeType === 'lost'" class="lost-header">
                    <div class="lost-icon">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p>Mark <strong>{{ closingRfp?.title }}</strong> as lost?</p>
                </div>

                <FormSelect
                    v-if="closeType === 'lost'"
                    v-model="closeForm.decline_reason"
                    label="Reason for Loss"
                    :options="declineReasonOptions"
                />
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showCloseModal = false">Cancel</button>
                    <button
                        type="button"
                        :class="closeType === 'won' ? 'btn btn-success' : 'btn btn-danger'"
                        :disabled="isSaving"
                        @click="confirmCloseRfp"
                    >
                        {{ isSaving ? 'Saving...' : (closeType === 'won' ? 'Mark as Won' : 'Mark as Lost') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" size="md" @close="showDeleteModal = false">
            <template #header>
                <h2 class="modal-title">Decline RFP Opportunity</h2>
            </template>

            <div class="decline-form">
                <p class="decline-prompt">
                    Why are you declining <strong>{{ rfpToDelete?.title }}</strong>?
                </p>
                <p class="decline-learn-note">
                    Your feedback helps the system learn which opportunities to prioritize in the future.
                </p>

                <FormSelect
                    v-model="declineForm.decline_category"
                    label="Reason"
                    :options="declineCategories"
                    placeholder="Select a reason..."
                    required
                />

                <FormTextarea
                    v-model="declineForm.decline_notes"
                    label="Additional notes (optional)"
                    :rows="3"
                    placeholder="Any specifics that would help filter similar opportunities..."
                />
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showDeleteModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isDeleting || !declineForm.decline_category"
                        @click="confirmDelete"
                    >
                        {{ isDeleting ? 'Declining...' : 'Decline & Remove' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Scan Gmail Modal -->
        <Modal :show="showScanModal" size="sm" @close="showScanModal = false">
            <template #header>
                <h2 class="modal-title">Scan Gmail for RFPs</h2>
            </template>

            <div class="space-y-4 p-4">
                <div>
                    <label class="form-label">Sender (optional)</label>
                    <input
                        v-model="scanForm.from"
                        type="text"
                        class="form-input"
                        placeholder="e.g., Rob Williams or rob@folyo.me"
                    />
                    <p class="text-caption mt-1">Leave blank to scan all configured RFP sources</p>
                </div>
                <div>
                    <label class="form-label">Look back (days)</label>
                    <input
                        v-model.number="scanForm.days"
                        type="number"
                        class="form-input"
                        min="1"
                        max="90"
                    />
                </div>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showScanModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="scanForm.processing"
                        @click="scanForm.post('/rfp/scan-gmail', { onSuccess: () => showScanModal = false })"
                    >
                        {{ scanForm.processing ? 'Scanning...' : 'Scan Gmail' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Upload Zone */
.upload-zone {
    background: var(--color-bg-secondary);
    border: 2px dashed var(--color-border-default);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 16px;
    transition: all 0.2s ease;
}

.upload-zone-active {
    border-color: var(--color-accent);
    background: rgba(139, 92, 246, 0.05);
}

.upload-zone-uploading {
    border-style: solid;
    border-color: var(--color-accent);
}

.upload-input-hidden {
    display: none;
}

.upload-content {
    display: flex;
    align-items: center;
}

.upload-row {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
}

.upload-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 500;
    color: var(--color-accent);
    background: rgba(139, 92, 246, 0.08);
    border: 1px solid rgba(139, 92, 246, 0.2);
    border-radius: 6px;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s;
}

.upload-btn:hover {
    background: rgba(139, 92, 246, 0.15);
}

.upload-or {
    font-size: 12px;
    color: var(--color-text-quaternary);
    white-space: nowrap;
}

.upload-divider {
    color: var(--color-border-default);
}

.upload-url-input {
    flex: 1;
    padding: 6px 10px;
    font-size: 12px;
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
    min-width: 200px;
}

.upload-url-input::placeholder {
    color: var(--color-text-quaternary);
}

.upload-url-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.upload-status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-accent);
    padding: 4px;
}

.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Sub-nav */
.sub-nav-bar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 16px;
}

.sub-nav {
    display: flex;
    gap: 2px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 3px;
}

.sub-nav-link {
    font-size: 13px;
    font-weight: 500;
    padding: 5px 14px;
    border-radius: 6px;
    color: var(--color-text-tertiary);
    text-decoration: none;
    transition: all 0.15s ease;
}

.sub-nav-link:hover {
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
}

.sub-nav-active {
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
}

/* Toolbar */
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.toolbar-left {
    display: flex;
    gap: 12px;
    align-items: center;
}

/* Pipeline View */
.pipeline {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 12px;
    min-height: 500px;
    overflow-x: auto;
}

.pipeline-column {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    min-width: 200px;
}

.column-header {
    padding: 16px;
    border-bottom: 1px solid var(--color-border-default);
}

.column-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: var(--color-text-primary);
    font-size: 13px;
}

.stage-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.column-meta {
    display: flex;
    gap: 12px;
    margin-top: 8px;
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
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    overflow-y: auto;
}

/* RFP Card */
.rfp-card {
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 12px;
    cursor: grab;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.15s ease;
}

.rfp-card:hover {
    border-color: var(--color-border-strong);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.15), 0 4px 8px -2px rgba(0, 0, 0, 0.1);
}

.rfp-card:active {
    cursor: grabbing;
    transform: scale(0.98);
}

.rfp-card-expired {
    opacity: 0.6;
    border-color: rgba(239, 68, 68, 0.3);
}

/* Drag and drop styles */
.rfp-ghost {
    opacity: 0.5;
    background: var(--color-bg-tertiary);
    border: 2px dashed var(--color-accent);
    transform: scale(0.98);
}

.rfp-dragging {
    transform: rotate(2deg) scale(1.02);
    box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.25), 0 5px 15px rgba(0, 0, 0, 0.1);
    cursor: grabbing !important;
    z-index: 100;
}

.rfp-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 4px;
}

.rfp-title {
    font-weight: 600;
    color: var(--color-text-primary);
    font-size: 13px;
    line-height: 1.3;
}

.rfp-org {
    font-size: 12px;
    color: var(--color-text-secondary);
    margin-bottom: 8px;
}

.rfp-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 8px;
}

.expired-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--color-status-red);
    background: rgba(239, 68, 68, 0.15);
    padding: 2px 6px;
    border-radius: 4px;
    letter-spacing: 0.3px;
}

.budget-badge {
    font-size: 11px;
    font-weight: 600;
    color: var(--color-status-green);
    background: rgba(34, 197, 94, 0.1);
    padding: 2px 6px;
    border-radius: 4px;
}

.fit-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 4px;
}

.fit-badge-lg {
    font-size: 13px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
}

.priority-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 4px;
    text-transform: capitalize;
}

.priority-high {
    background: rgba(249, 115, 22, 0.15);
    color: var(--color-status-orange);
}

.priority-critical {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-status-red);
}

.priority-medium {
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
}

.doc-badge {
    font-size: 9px;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 3px;
    letter-spacing: 0.5px;
}

.doc-parsed {
    background: rgba(6, 182, 212, 0.15);
    color: #06b6d4;
}

.doc-found {
    background: rgba(139, 92, 246, 0.15);
    color: #8b5cf6;
}

.priority-low {
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
}

.rfp-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.source-badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 4px;
    text-transform: capitalize;
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
}

.deadline-badge {
    font-size: 11px;
    font-weight: 500;
}

.status-badge {
    font-size: 12px;
    font-weight: 500;
    padding: 4px 10px;
    border-radius: 4px;
    text-transform: capitalize;
}

.decline-reason {
    font-size: 12px;
    color: var(--color-text-tertiary);
    text-transform: capitalize;
}

.add-rfp-btn {
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
}

.add-rfp-btn:hover {
    border-color: var(--color-status-blue);
    color: var(--color-status-blue);
}

/* Actions */
.rfp-actions {
    display: flex;
    align-items: center;
    gap: 4px;
}

.action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 6px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
    opacity: 0;
}

.rfp-card:hover .action-btn {
    opacity: 1;
}

.action-btn:hover {
    background: var(--color-bg-elevated);
    color: var(--color-text-primary);
    border-color: var(--color-border-strong);
}

.action-btn-evaluate:hover {
    background: rgba(59, 130, 246, 0.1);
    color: var(--color-status-blue);
    border-color: var(--color-status-blue);
}

.action-btn-search:hover {
    background: rgba(139, 92, 246, 0.1);
    color: #8b5cf6;
    border-color: var(--color-status-blue);
}

.action-btn-danger:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
    border-color: var(--color-status-red);
}

.pursue-btn {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    background: rgba(249, 115, 22, 0.15);
    color: var(--color-status-orange);
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s ease;
}

.pursue-btn:hover {
    background: rgba(249, 115, 22, 0.25);
    border-color: var(--color-status-orange);
}

/* Closed Deals */
.closed-deals {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-top: 24px;
}

.closed-section h3 {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--color-text-primary);
    margin-bottom: 12px;
}

.closed-list {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
}

.closed-item {
    display: flex;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-default);
    font-size: 13px;
}

.closed-item:last-child { border-bottom: none; }
.closed-item.won { color: var(--color-status-green); }
.closed-item.lost { color: var(--color-text-tertiary); }

.closed-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.reopen-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 4px;
    background: transparent;
    border: none;
    color: var(--color-text-tertiary);
    cursor: pointer;
    opacity: 0;
    transition: all 0.15s ease;
}

.closed-item:hover .reopen-btn {
    opacity: 1;
}

.reopen-btn:hover {
    color: var(--color-status-blue);
    background: rgba(59, 130, 246, 0.1);
}

.empty-closed {
    padding: 24px;
    text-align: center;
    color: var(--color-text-tertiary);
    font-size: 13px;
}

/* Modal styles */
.modal-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.form-section {
    padding: 1.25rem 0;
    border-bottom: 1px solid var(--color-border-subtle);
}

.form-section:first-child {
    padding-top: 0;
}

.form-section:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.form-section-title {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* Detail modal */
.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
}

.rfp-detail {
    padding: 0.5rem 0;
}

.detail-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border-subtle);
}

.detail-section h4 {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
}

.detail-label {
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
}

.detail-value {
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.detail-value a {
    color: var(--color-status-blue);
    text-decoration: none;
}

.detail-value a:hover {
    text-decoration: underline;
}

.detail-description {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    line-height: 1.6;
}

.detail-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border-subtle);
}

/* Button variants */
.btn-success {
    background: var(--color-status-green);
    color: white;
    border: none;
}

.btn-success:hover:not(:disabled) {
    background: #16a34a;
}

.btn-danger {
    background: var(--color-status-red);
    color: white;
    border: none;
}

.btn-danger:hover:not(:disabled) {
    background: #dc2626;
}

.btn-danger-outline {
    background: transparent;
    color: var(--color-status-red);
    border: 1px solid var(--color-status-red);
}

.btn-danger-outline:hover {
    background: rgba(239, 68, 68, 0.1);
}

/* Close deal modal */
.close-deal-form {
    padding: 0.5rem 0;
}

.won-header,
.lost-header {
    text-align: center;
    margin-bottom: 1.5rem;
}

.won-icon {
    color: var(--color-status-green);
    margin-bottom: 1rem;
}

.won-icon svg {
    width: 48px;
    height: 48px;
}

.lost-icon {
    color: var(--color-status-red);
    margin-bottom: 1rem;
}

.lost-icon svg {
    width: 48px;
    height: 48px;
}

.won-header p,
.lost-header p {
    font-size: 1rem;
    color: var(--color-text-primary);
}

/* Delete warning */
.decline-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 0.5rem 0;
}

.decline-prompt {
    color: var(--color-text-primary);
    font-size: 0.875rem;
}

.decline-learn-note {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
    padding: 8px 12px;
    border-radius: 6px;
    border-left: 3px solid var(--color-accent);
}
</style>
