<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormTextarea from '@/Components/FormTextarea.vue'
import MarkdownRenderer from '@/Components/MarkdownRenderer.vue'
import Badge from '@/Components/Badge.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, reactive, computed } from 'vue'

interface EvaluationCriterion {
    criterion: string
    weight: number | string
    description: string
}

interface RequirementResponse {
    requirement: string
    response: string
    met: boolean
}

interface PricingItem {
    item: string
    amount: number
    description?: string
}

interface Proposal {
    id: number
    version: number
    title: string
    status: string
    executive_summary: string | null
    full_content: string | null
    total_price: number | null
    pricing_breakdown: PricingItem[] | null
    case_studies_used: string[] | null
    testimonials_used: string[] | null
    requirement_responses: RequirementResponse[] | null
    created_at: string
    reviewed_at: string | null
    submitted_at: string | null
    review_notes: string | null
}

interface Outcome {
    id: number
    outcome: string
    feedback_raw: string | null
    feedback_structured: Record<string, string> | null
    win_factors: string[] | null
    loss_factors: string[] | null
    competitor_info: Record<string, any>[] | null
    lessons_learned: string[] | null
    awarded_to: string | null
    awarded_amount: number | null
    score_received: number | null
    organization_would_bid_again: boolean | null
    created_at: string
}

interface Opportunity {
    id: number
    title: string
    slug: string
    issuing_organization: string
    description: string | null
    source_type: string
    source_url: string | null
    full_document_url: string | null
    budget_min: number | null
    budget_max: number | null
    budget_range: string
    submission_deadline: string | null
    submission_deadline_display: string | null
    submission_method: string | null
    submission_email: string | null
    submission_portal_url: string | null
    contact_name: string | null
    contact_email: string | null
    contact_phone: string | null
    status: string
    priority: string
    fit_score: number
    tech_requirements: string[] | null
    requirements_summary: string[] | null
    evaluation_criteria: EvaluationCriterion[] | null
    timeline_requirements: Record<string, string>[] | null
    tags: string[] | null
    created_at: string
    updated_at: string
}

const props = defineProps<{
    opportunity: Opportunity
    proposals: Proposal[]
    outcome: Outcome | null
}>()

// Tabs
const activeTab = ref('overview')
const tabs = [
    { key: 'overview', label: 'Overview' },
    { key: 'requirements', label: 'Requirements' },
    { key: 'proposals', label: 'Proposals' },
    { key: 'outcome', label: 'Outcome' },
    { key: 'activity', label: 'Activity' },
]

// Expanded proposals
const expandedProposal = ref<number | null>(null)

const toggleProposal = (id: number) => {
    expandedProposal.value = expandedProposal.value === id ? null : id
}

// Outcome form
const showOutcomeForm = ref(false)
const isSavingOutcome = ref(false)
const outcomeForm = reactive({
    outcome: 'won',
    feedback_raw: '',
    awarded_to: '',
    awarded_amount: '',
})

const outcomeOptions = [
    { value: 'won', label: 'Won' },
    { value: 'lost', label: 'Lost' },
    { value: 'no_response', label: 'No Response' },
    { value: 'withdrawn', label: 'Withdrawn' },
]

const submitOutcome = () => {
    isSavingOutcome.value = true
    router.post(`/rfp/${props.opportunity.id}/outcome`, outcomeForm, {
        onSuccess: () => {
            showOutcomeForm.value = false
        },
        onFinish: () => isSavingOutcome.value = false,
    })
}

// Generate proposal
const isGenerating = ref(false)
const generateProposal = () => {
    isGenerating.value = true
    router.post(`/rfp/${props.opportunity.id}/generate-proposal`, {}, {
        onFinish: () => isGenerating.value = false,
    })
}

// Status helpers
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

const currentStatus = computed(() => {
    return statuses.find(s => s.key === props.opportunity.status)
})

const proposalStatusColors: Record<string, { bg: string; text: string }> = {
    draft: { bg: 'rgba(100, 116, 139, 0.15)', text: 'var(--color-text-tertiary)' },
    review: { bg: 'rgba(234, 179, 8, 0.15)', text: '#eab308' },
    approved: { bg: 'rgba(34, 197, 94, 0.15)', text: 'var(--color-status-green)' },
    submitted: { bg: 'rgba(20, 184, 166, 0.15)', text: '#14b8a6' },
    superseded: { bg: 'rgba(100, 116, 139, 0.1)', text: 'var(--color-text-quaternary)' },
}

const fitScoreColor = (score: number) => {
    if (score >= 70) return { bg: 'rgba(34, 197, 94, 0.15)', text: 'var(--color-status-green)' }
    if (score >= 40) return { bg: 'rgba(234, 179, 8, 0.15)', text: '#eab308' }
    return { bg: 'rgba(239, 68, 68, 0.15)', text: 'var(--color-status-red)' }
}

const priorityColors: Record<string, string> = {
    low: 'neutral',
    medium: 'neutral',
    high: 'warning',
    critical: 'danger',
}

const deadlineCountdown = computed(() => {
    if (!props.opportunity.submission_deadline) return null
    const deadline = new Date(props.opportunity.submission_deadline)
    const now = new Date()
    const diffMs = deadline.getTime() - now.getTime()
    const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24))

    if (diffDays < 0) return { text: 'Expired', color: 'var(--color-status-red)', urgent: true }
    if (diffDays === 0) return { text: 'Due today', color: 'var(--color-status-red)', urgent: true }
    if (diffDays === 1) return { text: '1 day left', color: 'var(--color-status-orange)', urgent: true }
    if (diffDays <= 7) return { text: `${diffDays} days left`, color: 'var(--color-status-orange)', urgent: false }
    return { text: `${diffDays} days left`, color: 'var(--color-text-tertiary)', urgent: false }
})

const formatBudget = (value: number | null) => {
    if (!value) return null
    const num = Number(value)
    if (num >= 1000000) return `$${(num / 1000000).toFixed(1)}M`
    if (num >= 1000) return `$${(num / 1000).toFixed(0)}K`
    return `$${num.toFixed(0)}`
}

const formatBudgetRange = computed(() => {
    const o = props.opportunity
    if (o.budget_min && o.budget_max) {
        return `${formatBudget(o.budget_min)} - ${formatBudget(o.budget_max)}`
    }
    if (o.budget_min) return `From ${formatBudget(o.budget_min)}`
    if (o.budget_max) return `Up to ${formatBudget(o.budget_max)}`
    return null
})

const formatCurrency = (value: number | null) => {
    if (!value) return '--'
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value)
}

const formatDate = (dateStr: string | null) => {
    if (!dateStr) return '--'
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

const outcomeColorMap: Record<string, string> = {
    won: 'success',
    lost: 'danger',
    no_response: 'neutral',
    withdrawn: 'warning',
}

const sortedProposals = computed(() => {
    return [...props.proposals].sort((a, b) => b.version - a.version)
})

// Show/hide outcome modal
const showOutcomeModal = ref(false)
const openOutcomeModal = () => {
    outcomeForm.outcome = 'won'
    outcomeForm.feedback_raw = ''
    outcomeForm.awarded_to = ''
    outcomeForm.awarded_amount = ''
    showOutcomeModal.value = true
}

const submitProposal = (proposalId: number) => {
    router.put(`/rfp/${props.opportunity.id}/proposals/${proposalId}/submit`, {}, {
        preserveScroll: true,
    })
}

// Email send modal
const showEmailModal = ref(false)
const isSendingEmail = ref(false)
const emailProposal = ref<Proposal | null>(null)
const emailForm = reactive({
    to_email: '',
    to_name: '',
    subject: '',
    body: '',
})

const openEmailModal = (proposal: Proposal) => {
    emailProposal.value = proposal
    const o = props.opportunity

    emailForm.to_email = o.submission_email || o.contact_email || ''
    emailForm.to_name = o.contact_name || ''
    emailForm.subject = `Proposal: ${o.title} — ${proposal.title}`
    emailForm.body = buildEmailDraft(proposal)
    showEmailModal.value = true
}

const buildEmailDraft = (proposal: Proposal): string => {
    const o = props.opportunity
    const contactName = o.contact_name ? o.contact_name.split(' ')[0] : 'there'
    const price = proposal.total_price ? formatCurrency(proposal.total_price) : ''
    const deadline = o.submission_deadline_display || ''

    let body = `Hi ${contactName},\n\n`
    body += `Thank you for the opportunity to respond to the ${o.title} RFP. `
    body += `We're excited about the possibility of partnering with ${o.issuing_organization} on this project.\n\n`
    body += `Please find our proposal attached. `
    if (price) {
        body += `Our proposed investment is ${price}. `
    }
    body += `We've included a detailed breakdown of our approach, timeline, and relevant experience.\n\n`
    if (deadline) {
        body += `We understand the submission deadline is ${deadline} and have ensured all materials are complete.\n\n`
    }
    body += `We'd welcome the chance to discuss this further at your convenience. Please don't hesitate to reach out with any questions.\n\n`
    body += `Best regards`
    return body
}

const sendEmail = () => {
    if (!emailProposal.value) return
    isSendingEmail.value = true
    router.post(`/rfp/${props.opportunity.id}/proposals/${emailProposal.value.id}/send-email`, emailForm, {
        preserveScroll: true,
        onSuccess: () => {
            showEmailModal.value = false
        },
        onFinish: () => isSendingEmail.value = false,
    })
}

const sendTestEmail = () => {
    if (!emailProposal.value) return
    isSendingEmail.value = true
    router.post(`/rfp/${props.opportunity.id}/proposals/${emailProposal.value.id}/send-email`, {
        ...emailForm,
        is_test: true,
    }, {
        preserveScroll: true,
        onFinish: () => isSendingEmail.value = false,
    })
}
</script>

<template>
    <AppLayout :title="opportunity.title">
        <!-- Back link -->
        <Link href="/rfp" class="back-link">
            <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            Back to RFP Pipeline
        </Link>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-top">
                <div class="header-info">
                    <h1 class="page-title">{{ opportunity.title }}</h1>
                    <p class="org-name">{{ opportunity.issuing_organization }}</p>
                </div>
                <div class="header-actions">
                    <Link :href="`/rfp/${opportunity.id}/edit`" class="btn btn-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit
                    </Link>
                    <button class="btn btn-secondary" @click="router.post(`/rfp/${opportunity.id}/retrieve-document`)">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Find Full RFP
                    </button>
                    <button class="btn btn-primary" :disabled="isGenerating" @click="generateProposal">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        {{ isGenerating ? 'Generating...' : 'Generate Proposal' }}
                    </button>
                    <button v-if="!outcome" class="btn btn-secondary" @click="openOutcomeModal">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        Record Outcome
                    </button>
                </div>
            </div>

            <!-- Badges row -->
            <div class="header-badges">
                <!-- Status -->
                <span
                    v-if="currentStatus"
                    class="status-badge"
                    :style="{ background: currentStatus.color + '20', color: currentStatus.color }"
                >
                    <span class="status-dot" :style="{ background: currentStatus.color }"></span>
                    {{ currentStatus.label }}
                </span>

                <!-- Fit Score -->
                <span
                    v-if="opportunity.fit_score > 0"
                    class="fit-badge"
                    :style="{ background: fitScoreColor(opportunity.fit_score).bg, color: fitScoreColor(opportunity.fit_score).text }"
                >
                    {{ opportunity.fit_score }}% fit
                </span>

                <!-- Priority -->
                <Badge :variant="(priorityColors[opportunity.priority] as any) || 'neutral'" size="sm">
                    {{ opportunity.priority }}
                </Badge>

                <!-- Deadline -->
                <span
                    v-if="deadlineCountdown"
                    class="deadline-pill"
                    :style="{ color: deadlineCountdown.color }"
                    :class="{ 'deadline-urgent': deadlineCountdown.urgent }"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ deadlineCountdown.text }}
                </span>

                <!-- Budget -->
                <span v-if="formatBudgetRange" class="budget-pill">
                    {{ formatBudgetRange }}
                </span>
            </div>
        </div>

        <!-- Generation Progress Banner -->
        <div v-if="opportunity.generation_stage && opportunity.generation_stage !== 'complete' && opportunity.status === 'proposal_drafting'" class="gen-progress-banner">
            <div v-if="opportunity.generation_stage === 'failed'" class="gen-progress-failed">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span>Proposal generation failed: {{ opportunity.generation_error }}</span>
                <button class="btn btn-sm btn-primary" @click="generateProposal">Retry</button>
            </div>
            <div v-else class="gen-progress-active">
                <svg class="gen-spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                <span class="gen-stage-label">
                    {{ opportunity.generation_stage === 'starting' ? 'Starting proposal generation...' :
                       opportunity.generation_stage === 'gathering_context' ? 'Gathering project history and references...' :
                       opportunity.generation_stage === 'generating_content' ? 'Writing proposal with AI (this takes a few minutes)...' :
                       opportunity.generation_stage === 'saving_proposal' ? 'Saving proposal and creating PDF...' :
                       'Processing...' }}
                </span>
                <span v-if="opportunity.generation_started_at" class="gen-started">Started {{ opportunity.generation_started_at }}</span>
            </div>
        </div>

        <!-- Generation Error (when status reverted to pursuing) -->
        <div v-if="opportunity.generation_stage === 'failed' && opportunity.status === 'pursuing'" class="gen-progress-banner">
            <div class="gen-progress-failed">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span>Last proposal attempt failed: {{ opportunity.generation_error }}</span>
                <button class="btn btn-sm btn-primary" @click="generateProposal">Retry</button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-bar">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                :class="['tab-btn', { 'tab-active': activeTab === tab.key }]"
                @click="activeTab = tab.key"
            >
                {{ tab.label }}
                <span v-if="tab.key === 'proposals' && proposals.length > 0" class="tab-count">
                    {{ proposals.length }}
                </span>
            </button>
        </div>

        <!-- Tab content -->
        <div class="tab-content">

            <!-- Overview Tab -->
            <div v-if="activeTab === 'overview'" class="tab-panel">
                <div class="detail-grid">
                    <!-- Description -->
                    <div class="detail-card detail-card-wide">
                        <h3 class="card-title">Description</h3>
                        <p v-if="opportunity.description" class="description-text">{{ opportunity.description }}</p>
                        <p v-else class="empty-text">No description provided</p>
                    </div>

                    <!-- Contact Info -->
                    <div class="detail-card">
                        <h3 class="card-title">Contact Information</h3>
                        <div class="info-list">
                            <div v-if="opportunity.contact_name" class="info-row">
                                <span class="info-label">Name</span>
                                <span class="info-value">{{ opportunity.contact_name }}</span>
                            </div>
                            <div v-if="opportunity.contact_email" class="info-row">
                                <span class="info-label">Email</span>
                                <a :href="`mailto:${opportunity.contact_email}`" class="info-link">
                                    {{ opportunity.contact_email }}
                                </a>
                            </div>
                            <div v-if="opportunity.contact_phone" class="info-row">
                                <span class="info-label">Phone</span>
                                <span class="info-value">{{ opportunity.contact_phone }}</span>
                            </div>
                            <div v-if="!opportunity.contact_name && !opportunity.contact_email && !opportunity.contact_phone" class="empty-text">
                                No contact information available
                            </div>
                        </div>
                    </div>

                    <!-- Source & Submission -->
                    <div class="detail-card">
                        <h3 class="card-title">Source & Submission</h3>
                        <div class="info-list">
                            <div class="info-row">
                                <span class="info-label">Source Type</span>
                                <span class="source-pill">{{ opportunity.source_type.replace(/_/g, ' ') }}</span>
                            </div>
                            <div v-if="opportunity.source_url" class="info-row">
                                <span class="info-label">Source URL</span>
                                <a :href="opportunity.source_url" target="_blank" rel="noopener noreferrer" class="info-link">
                                    View Source
                                    <svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                </a>
                            </div>
                            <div v-if="opportunity.full_document_url" class="info-row">
                                <span class="info-label">Full RFP Document</span>
                                <a :href="opportunity.full_document_url" target="_blank" rel="noopener noreferrer" class="info-link rfp-doc-link">
                                    <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                    View RFP Document
                                    <svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                </a>
                            </div>
                            <div v-if="opportunity.submission_method" class="info-row">
                                <span class="info-label">Submission</span>
                                <span class="info-value capitalize">{{ opportunity.submission_method }}</span>
                            </div>
                            <div v-if="opportunity.submission_email" class="info-row">
                                <span class="info-label">Submit To</span>
                                <a :href="`mailto:${opportunity.submission_email}`" class="info-link">
                                    {{ opportunity.submission_email }}
                                </a>
                            </div>
                            <div v-if="opportunity.submission_portal_url" class="info-row">
                                <span class="info-label">Portal</span>
                                <a :href="opportunity.submission_portal_url" target="_blank" rel="noopener noreferrer" class="info-link">
                                    Submission Portal
                                    <svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Tags -->
                    <div v-if="opportunity.tags && opportunity.tags.length > 0" class="detail-card detail-card-wide">
                        <h3 class="card-title">Tags</h3>
                        <div class="tags-list">
                            <span v-for="tag in opportunity.tags" :key="tag" class="tag-pill">
                                {{ tag }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Requirements Tab -->
            <div v-if="activeTab === 'requirements'" class="tab-panel">
                <div class="detail-grid">
                    <!-- Requirements Summary -->
                    <div class="detail-card detail-card-wide">
                        <h3 class="card-title">Requirements Summary</h3>
                        <ul v-if="opportunity.requirements_summary && opportunity.requirements_summary.length > 0" class="requirements-list">
                            <li v-for="(req, i) in opportunity.requirements_summary" :key="i" class="requirement-item">
                                <div class="requirement-main">
                                    <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4" />
                                    </svg>
                                    <div>
                                        <span>{{ typeof req === 'object' ? (req.requirement || JSON.stringify(req)) : req }}</span>
                                        <div v-if="typeof req === 'object' && (req.section || req.priority)" class="requirement-meta">
                                            <span v-if="req.section" class="requirement-section">{{ req.section }}</span>
                                            <span v-if="req.priority" class="requirement-priority" :class="`priority-${req.priority}`">{{ req.priority }}</span>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="empty-text">No requirements parsed yet</p>
                    </div>

                    <!-- Tech Requirements -->
                    <div class="detail-card detail-card-wide">
                        <h3 class="card-title">Technical Requirements</h3>
                        <div v-if="opportunity.tech_requirements && opportunity.tech_requirements.length > 0" class="tech-pills">
                            <span v-for="tech in opportunity.tech_requirements" :key="tech" class="tech-pill">
                                {{ tech }}
                            </span>
                        </div>
                        <p v-else class="empty-text">No technical requirements specified</p>
                    </div>

                    <!-- Evaluation Criteria -->
                    <div class="detail-card detail-card-wide">
                        <h3 class="card-title">Evaluation Criteria</h3>
                        <div v-if="opportunity.evaluation_criteria && opportunity.evaluation_criteria.length > 0" class="criteria-table-wrap">
                            <table class="criteria-table">
                                <thead>
                                    <tr>
                                        <th>Criterion</th>
                                        <th>Weight</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(crit, i) in opportunity.evaluation_criteria" :key="i">
                                        <td class="criterion-name">{{ crit.criterion }}</td>
                                        <td class="criterion-weight">{{ crit.weight }}%</td>
                                        <td class="criterion-desc">{{ crit.description }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="empty-text">No evaluation criteria available</p>
                    </div>

                    <!-- Timeline Requirements -->
                    <div v-if="opportunity.timeline_requirements && opportunity.timeline_requirements.length > 0" class="detail-card detail-card-wide">
                        <h3 class="card-title">Timeline Requirements</h3>
                        <div class="timeline-items">
                            <div v-for="(item, i) in opportunity.timeline_requirements" :key="i" class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-content">
                                    <div v-for="(val, key) in item" :key="key" class="timeline-row">
                                        <span class="timeline-key">{{ String(key).replace(/_/g, ' ') }}</span>
                                        <span class="timeline-val">{{ val }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Proposals Tab -->
            <div v-if="activeTab === 'proposals'" class="tab-panel">
                <div class="proposals-header">
                    <h3 class="section-title">Proposals ({{ proposals.length }})</h3>
                    <button class="btn btn-primary" :disabled="isGenerating" @click="generateProposal">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        {{ isGenerating ? 'Generating...' : 'Generate New Proposal' }}
                    </button>
                </div>

                <div v-if="sortedProposals.length > 0" class="proposals-list">
                    <div
                        v-for="proposal in sortedProposals"
                        :key="proposal.id"
                        class="proposal-card"
                    >
                        <div class="proposal-header" @click="toggleProposal(proposal.id)">
                            <div class="proposal-info">
                                <span class="proposal-version">v{{ proposal.version }}</span>
                                <span class="proposal-title">{{ proposal.title }}</span>
                                <span
                                    class="proposal-status"
                                    :style="{
                                        background: (proposalStatusColors[proposal.status] || proposalStatusColors.draft).bg,
                                        color: (proposalStatusColors[proposal.status] || proposalStatusColors.draft).text,
                                    }"
                                >
                                    {{ proposal.status }}
                                </span>
                            </div>
                            <div class="proposal-meta">
                                <span v-if="proposal.total_price" class="proposal-price">
                                    {{ formatCurrency(proposal.total_price) }}
                                </span>
                                <span class="proposal-date">{{ formatDate(proposal.created_at) }}</span>
                                <svg
                                    :class="['expand-icon', { 'expand-icon-open': expandedProposal === proposal.id }]"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                        </div>

                        <div v-if="expandedProposal === proposal.id" class="proposal-expanded">
                            <!-- Executive Summary -->
                            <div v-if="proposal.executive_summary" class="proposal-section">
                                <h4>Executive Summary</h4>
                                <p class="proposal-text">{{ proposal.executive_summary }}</p>
                            </div>

                            <!-- Full Content -->
                            <div v-if="proposal.full_content" class="proposal-section">
                                <h4>Full Proposal</h4>
                                <div class="proposal-content-wrap">
                                    <MarkdownRenderer :content="proposal.full_content" />
                                </div>
                            </div>

                            <!-- Pricing Breakdown -->
                            <div v-if="proposal.pricing_breakdown && proposal.pricing_breakdown.length > 0" class="proposal-section">
                                <h4>Pricing Breakdown</h4>
                                <table class="pricing-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Amount</th>
                                            <th>Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(item, i) in proposal.pricing_breakdown" :key="i">
                                            <td>{{ item.item }}</td>
                                            <td class="price-cell">{{ formatCurrency(item.amount) }}</td>
                                            <td class="desc-cell">{{ item.description || '--' }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td class="total-label">Total</td>
                                            <td class="total-value">{{ formatCurrency(proposal.total_price) }}</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Case Studies & Testimonials -->
                            <div v-if="proposal.case_studies_used && proposal.case_studies_used.length > 0" class="proposal-section">
                                <h4>Case Studies Used</h4>
                                <ul class="evidence-list">
                                    <li v-for="(cs, i) in proposal.case_studies_used" :key="i">{{ cs }}</li>
                                </ul>
                            </div>

                            <div v-if="proposal.testimonials_used && proposal.testimonials_used.length > 0" class="proposal-section">
                                <h4>Testimonials Used</h4>
                                <ul class="evidence-list">
                                    <li v-for="(t, i) in proposal.testimonials_used" :key="i">{{ t }}</li>
                                </ul>
                            </div>

                            <!-- Requirement Responses -->
                            <div v-if="proposal.requirement_responses && proposal.requirement_responses.length > 0" class="proposal-section">
                                <h4>Requirement Responses</h4>
                                <div class="requirement-checklist">
                                    <div
                                        v-for="(rr, i) in proposal.requirement_responses"
                                        :key="i"
                                        :class="['req-item', { 'req-met': rr.met, 'req-unmet': !rr.met }]"
                                    >
                                        <svg v-if="rr.met" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <svg v-else class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <div>
                                            <div class="req-label">{{ rr.requirement }}</div>
                                            <div class="req-response">{{ rr.response }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="proposal-actions">
                                <a
                                    :href="`/rfp/${opportunity.id}/proposals/${proposal.id}/pdf`"
                                    target="_blank"
                                    class="btn btn-secondary"
                                    @click.stop
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    Export PDF
                                </a>
                                <button
                                    class="btn btn-primary"
                                    @click.stop="openEmailModal(proposal)"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                    Send via Email
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="empty-state">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <p>No proposals generated yet</p>
                    <button class="btn btn-primary mt-4" :disabled="isGenerating" @click="generateProposal">
                        Generate First Proposal
                    </button>
                </div>
            </div>

            <!-- Outcome Tab -->
            <div v-if="activeTab === 'outcome'" class="tab-panel">
                <!-- Existing outcome -->
                <div v-if="outcome" class="outcome-display">
                    <div class="outcome-header-row">
                        <Badge :variant="(outcomeColorMap[outcome.outcome] as any) || 'neutral'" size="md">
                            {{ outcome.outcome.replace(/_/g, ' ') }}
                        </Badge>
                        <span class="outcome-date">Recorded {{ formatDate(outcome.created_at) }}</span>
                    </div>

                    <div class="outcome-details">
                        <div v-if="outcome.feedback_raw" class="outcome-section">
                            <h4>Feedback</h4>
                            <p class="outcome-text">{{ outcome.feedback_raw }}</p>
                        </div>

                        <div v-if="outcome.win_factors && outcome.win_factors.length > 0" class="outcome-section">
                            <h4>Win Factors</h4>
                            <div class="factor-pills">
                                <span v-for="(f, i) in outcome.win_factors" :key="i" class="factor-pill factor-win">{{ f }}</span>
                            </div>
                        </div>

                        <div v-if="outcome.loss_factors && outcome.loss_factors.length > 0" class="outcome-section">
                            <h4>Loss Factors</h4>
                            <div class="factor-pills">
                                <span v-for="(f, i) in outcome.loss_factors" :key="i" class="factor-pill factor-loss">{{ f }}</span>
                            </div>
                        </div>

                        <div v-if="outcome.awarded_to" class="outcome-section">
                            <div class="info-row">
                                <span class="info-label">Awarded To</span>
                                <span class="info-value">{{ outcome.awarded_to }}</span>
                            </div>
                        </div>

                        <div v-if="outcome.awarded_amount" class="outcome-section">
                            <div class="info-row">
                                <span class="info-label">Awarded Amount</span>
                                <span class="info-value">{{ formatCurrency(outcome.awarded_amount) }}</span>
                            </div>
                        </div>

                        <div v-if="outcome.score_received" class="outcome-section">
                            <div class="info-row">
                                <span class="info-label">Score Received</span>
                                <span class="info-value">{{ outcome.score_received }}</span>
                            </div>
                        </div>

                        <div v-if="outcome.lessons_learned && outcome.lessons_learned.length > 0" class="outcome-section">
                            <h4>Lessons Learned</h4>
                            <ul class="lessons-list">
                                <li v-for="(lesson, i) in outcome.lessons_learned" :key="i">{{ lesson }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- No outcome yet -->
                <div v-else class="empty-state">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <p>No outcome recorded yet</p>
                    <button class="btn btn-primary mt-4" @click="openOutcomeModal">
                        Record Outcome
                    </button>
                </div>
            </div>

            <!-- Activity Tab -->
            <div v-if="activeTab === 'activity'" class="tab-panel">
                <div class="activity-timeline">
                    <!-- Created -->
                    <div class="activity-item">
                        <div class="activity-dot activity-dot-created"></div>
                        <div class="activity-content">
                            <span class="activity-label">Opportunity Created</span>
                            <span class="activity-date">{{ opportunity.created_at }}</span>
                        </div>
                    </div>

                    <!-- Status set -->
                    <div class="activity-item">
                        <div class="activity-dot" :style="{ background: currentStatus?.color || 'var(--color-text-tertiary)' }"></div>
                        <div class="activity-content">
                            <span class="activity-label">
                                Status: <strong>{{ currentStatus?.label || opportunity.status }}</strong>
                            </span>
                            <span class="activity-date">{{ opportunity.updated_at }}</span>
                        </div>
                    </div>

                    <!-- Proposals -->
                    <div v-for="proposal in sortedProposals" :key="'prop-' + proposal.id" class="activity-item">
                        <div class="activity-dot activity-dot-proposal"></div>
                        <div class="activity-content">
                            <span class="activity-label">
                                Proposal v{{ proposal.version }} generated
                                <span
                                    class="inline-badge"
                                    :style="{
                                        background: (proposalStatusColors[proposal.status] || proposalStatusColors.draft).bg,
                                        color: (proposalStatusColors[proposal.status] || proposalStatusColors.draft).text,
                                    }"
                                >
                                    {{ proposal.status }}
                                </span>
                            </span>
                            <span class="activity-date">{{ formatDate(proposal.created_at) }}</span>
                        </div>
                    </div>

                    <!-- Outcome -->
                    <div v-if="outcome" class="activity-item">
                        <div
                            class="activity-dot"
                            :style="{ background: outcome.outcome === 'won' ? 'var(--color-status-green)' : outcome.outcome === 'lost' ? 'var(--color-status-red)' : 'var(--color-text-tertiary)' }"
                        ></div>
                        <div class="activity-content">
                            <span class="activity-label">
                                Outcome recorded: <strong class="capitalize">{{ outcome.outcome.replace(/_/g, ' ') }}</strong>
                            </span>
                            <span class="activity-date">{{ formatDate(outcome.created_at) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Record Outcome Modal -->
        <Modal :show="showOutcomeModal" size="md" @close="showOutcomeModal = false">
            <template #header>
                <h2 class="modal-title">Record Outcome</h2>
            </template>

            <form @submit.prevent="submitOutcome">
                <FormSelect
                    v-model="outcomeForm.outcome"
                    label="Outcome"
                    :options="outcomeOptions"
                    required
                />
                <FormTextarea
                    v-model="outcomeForm.feedback_raw"
                    label="Feedback"
                    placeholder="Any feedback from the issuing organization..."
                    :rows="3"
                />
                <FormInput
                    v-model="outcomeForm.awarded_to"
                    label="Awarded To"
                    placeholder="Name of winning organization (if not us)"
                />
                <FormInput
                    v-model="outcomeForm.awarded_amount"
                    label="Awarded Amount"
                    type="number"
                    placeholder="0"
                    prefix="$"
                />
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showOutcomeModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSavingOutcome"
                        @click="submitOutcome"
                    >
                        {{ isSavingOutcome ? 'Saving...' : 'Record Outcome' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Email Send Modal -->
        <Modal :show="showEmailModal" size="lg" @close="showEmailModal = false">
            <template #title>Send Proposal via Email</template>
            <template #default>
                <div class="email-modal-body">
                    <div v-if="emailProposal" class="email-proposal-info">
                        <span class="email-proposal-label">Attaching:</span>
                        {{ emailProposal.title }} (v{{ emailProposal.version }})
                        <span v-if="emailProposal.total_price" class="email-proposal-price">
                            {{ formatCurrency(emailProposal.total_price) }}
                        </span>
                    </div>

                    <div class="form-grid">
                        <div class="form-row form-row-half">
                            <FormInput
                                v-model="emailForm.to_email"
                                label="To Email"
                                type="email"
                                placeholder="contact@organization.com"
                                required
                            />
                            <FormInput
                                v-model="emailForm.to_name"
                                label="To Name"
                                placeholder="Contact Name"
                            />
                        </div>
                        <FormInput
                            v-model="emailForm.subject"
                            label="Subject"
                            placeholder="Proposal: ..."
                            required
                        />
                        <FormTextarea
                            v-model="emailForm.body"
                            label="Email Body"
                            :rows="12"
                            placeholder="Write your email..."
                            required
                        />
                    </div>
                </div>
            </template>
            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showEmailModal = false">Cancel</button>
                    <a
                        v-if="emailProposal"
                        :href="`/rfp/${opportunity.id}/proposals/${emailProposal.id}/pdf`"
                        target="_blank"
                        class="btn btn-secondary"
                    >
                        Preview PDF
                    </a>
                    <button
                        class="btn btn-secondary"
                        :disabled="isSendingEmail || !emailForm.subject || !emailForm.body"
                        @click="sendTestEmail"
                    >
                        Send Test to Me
                    </button>
                    <button
                        class="btn btn-primary"
                        :disabled="isSendingEmail || !emailForm.to_email || !emailForm.subject || !emailForm.body"
                        @click="sendEmail"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                        {{ isSendingEmail ? 'Sending...' : 'Send Email' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Generation Progress */
.gen-progress-banner {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
}

.gen-progress-active {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--color-accent);
    font-size: 13px;
    font-weight: 500;
}

.gen-spinner {
    animation: spin 1s linear infinite;
    flex-shrink: 0;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.gen-stage-label {
    flex: 1;
}

.gen-started {
    font-size: 11px;
    color: var(--color-text-tertiary);
    font-weight: 400;
}

.gen-progress-failed {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--color-status-red);
    font-size: 13px;
}

.gen-progress-failed span {
    flex: 1;
}

/* Back link */
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    text-decoration: none;
    margin-bottom: 0.75rem;
}

.back-link:hover {
    color: var(--color-accent);
}

.back-icon {
    width: 16px;
    height: 16px;
}

/* Page Header */
.page-header {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 16px;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
    line-height: 1.3;
}

.org-name {
    font-size: 0.9375rem;
    color: var(--color-text-secondary);
    margin-top: 4px;
}

.header-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

.header-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 6px;
    text-transform: capitalize;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.fit-badge {
    font-size: 13px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
}

.deadline-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    font-weight: 500;
}

.deadline-urgent {
    animation: pulse-subtle 2s infinite;
}

@keyframes pulse-subtle {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.budget-pill {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-status-green);
    background: rgba(34, 197, 94, 0.1);
    padding: 4px 10px;
    border-radius: 6px;
}

/* Tabs */
.tabs-bar {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid var(--color-border-default);
    margin-bottom: 24px;
}

.tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: var(--color-text-tertiary);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
    margin-bottom: -1px;
}

.tab-btn:hover {
    color: var(--color-text-primary);
}

.tab-active {
    color: var(--color-accent);
    border-bottom-color: var(--color-accent);
}

.tab-count {
    font-size: 11px;
    background: var(--color-bg-tertiary);
    padding: 1px 6px;
    border-radius: 10px;
    color: var(--color-text-tertiary);
}

.tab-active .tab-count {
    background: rgba(139, 92, 246, 0.15);
    color: var(--color-accent);
}

/* Tab Content */
.tab-content {
    min-height: 400px;
}

.tab-panel {
    animation: fadeIn 0.15s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Detail Grid */
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.detail-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
    padding: 20px;
}

.detail-card-wide {
    grid-column: 1 / -1;
}

.card-title {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 12px;
}

.description-text {
    font-size: 0.9375rem;
    color: var(--color-text-secondary);
    line-height: 1.7;
}

.empty-text {
    font-size: 0.875rem;
    color: var(--color-text-quaternary);
    font-style: italic;
}

/* Info List */
.info-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
}

.info-label {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

.info-value {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    font-weight: 500;
}

.info-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.875rem;
    color: var(--color-accent);
    text-decoration: none;
}

.info-link:hover {
    text-decoration: underline;
}

.source-pill {
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 4px;
    text-transform: capitalize;
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
    font-weight: 500;
}

/* Tags */
.tags-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.tag-pill {
    font-size: 12px;
    font-weight: 500;
    padding: 3px 10px;
    border-radius: 12px;
    background: rgba(139, 92, 246, 0.1);
    color: var(--color-accent);
}

/* Requirements */
.requirements-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.requirements-list li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
}

.requirements-list li svg {
    color: var(--color-status-green);
    margin-top: 2px;
}

.requirement-main {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.requirement-meta {
    display: flex;
    gap: 6px;
    margin-top: 4px;
}

.requirement-section {
    font-size: 11px;
    color: var(--color-text-tertiary);
    font-style: italic;
}

.requirement-priority {
    font-size: 10px;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 3px;
    text-transform: capitalize;
}

.requirement-priority.priority-required {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
}

.requirement-priority.priority-preferred {
    background: rgba(249, 115, 22, 0.1);
    color: var(--color-status-orange);
}

.requirement-priority.priority-optional {
    background: rgba(156, 163, 175, 0.1);
    color: var(--color-text-tertiary);
}

.rfp-doc-link {
    font-weight: 600;
    color: #8b5cf6;
}

.rfp-doc-link:hover {
    color: #7c3aed;
}

/* Tech Pills */
.tech-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.tech-pill {
    font-size: 12px;
    font-weight: 500;
    padding: 4px 10px;
    border-radius: 6px;
    background: rgba(99, 102, 241, 0.1);
    color: #6366f1;
}

/* Criteria Table */
.criteria-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
}

.criteria-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.criteria-table th {
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
    border-bottom: 1px solid var(--color-border-subtle);
    font-size: 0.8125rem;
}

.criteria-table td {
    padding: 10px 14px;
    color: var(--color-text-secondary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.criteria-table tr:last-child td {
    border-bottom: none;
}

.criterion-name {
    font-weight: 500;
    color: var(--color-text-primary);
}

.criterion-weight {
    font-weight: 600;
    color: var(--color-accent);
    white-space: nowrap;
}

/* Timeline Requirements */
.timeline-items {
    display: flex;
    flex-direction: column;
    gap: 0;
    position: relative;
    padding-left: 20px;
}

.timeline-item {
    position: relative;
    padding: 12px 0;
    border-left: 2px solid var(--color-border-default);
    padding-left: 20px;
    margin-left: -1px;
}

.timeline-dot {
    position: absolute;
    left: -6px;
    top: 16px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--color-accent);
    border: 2px solid var(--color-bg-secondary);
}

.timeline-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.timeline-row {
    display: flex;
    gap: 8px;
    font-size: 0.875rem;
}

.timeline-key {
    color: var(--color-text-tertiary);
    text-transform: capitalize;
    min-width: 100px;
}

.timeline-val {
    color: var(--color-text-primary);
    font-weight: 500;
}

/* Proposals */
.proposals-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.proposals-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.proposal-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
    overflow: hidden;
    transition: border-color 0.15s ease;
}

.proposal-card:hover {
    border-color: var(--color-border-strong);
}

.proposal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    cursor: pointer;
}

.proposal-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.proposal-version {
    font-size: 12px;
    font-weight: 700;
    color: var(--color-accent);
    background: rgba(139, 92, 246, 0.1);
    padding: 2px 8px;
    border-radius: 4px;
}

.proposal-title {
    font-weight: 600;
    color: var(--color-text-primary);
    font-size: 0.9375rem;
}

.proposal-status {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    text-transform: capitalize;
}

.proposal-meta {
    display: flex;
    align-items: center;
    gap: 12px;
}

.proposal-price {
    font-weight: 600;
    color: var(--color-status-green);
    font-size: 0.9375rem;
}

.proposal-date {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.expand-icon {
    width: 16px;
    height: 16px;
    color: var(--color-text-tertiary);
    transition: transform 0.2s ease;
}

.expand-icon-open {
    transform: rotate(180deg);
}

.proposal-expanded {
    border-top: 1px solid var(--color-border-default);
    padding: 20px;
    animation: fadeIn 0.15s ease;
}

.proposal-section {
    margin-bottom: 20px;
}

.proposal-section:last-child {
    margin-bottom: 0;
}

.proposal-section h4 {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 10px;
}

.proposal-text {
    font-size: 0.9375rem;
    color: var(--color-text-secondary);
    line-height: 1.7;
}

.proposal-content-wrap {
    max-height: 500px;
    overflow-y: auto;
    padding: 16px;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
}

/* Pricing Table */
.pricing-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    overflow: hidden;
}

.pricing-table th {
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
    border-bottom: 1px solid var(--color-border-subtle);
    font-size: 0.8125rem;
}

.pricing-table td {
    padding: 10px 14px;
    color: var(--color-text-secondary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.price-cell {
    font-weight: 600;
    color: var(--color-text-primary);
    white-space: nowrap;
}

.desc-cell {
    color: var(--color-text-tertiary);
    font-size: 0.8125rem;
}

.total-label {
    font-weight: 700;
    color: var(--color-text-primary);
}

.total-value {
    font-weight: 700;
    color: var(--color-status-green);
}

.pricing-table tfoot tr {
    background: var(--color-bg-tertiary);
}

.pricing-table tfoot td {
    border-bottom: none;
}

/* Evidence Lists */
.evidence-list {
    list-style: disc;
    padding-left: 20px;
    margin: 0;
}

.evidence-list li {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 4px;
}

/* Requirement Responses */
.requirement-checklist {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.req-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-subtle);
}

.req-met svg {
    color: var(--color-status-green);
}

.req-unmet svg {
    color: var(--color-status-red);
}

.req-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.req-response {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    margin-top: 2px;
}

.proposal-actions {
    padding-top: 16px;
    border-top: 1px solid var(--color-border-subtle);
    margin-top: 16px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* Email Modal */
.email-modal-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.email-proposal-info {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
}

.email-proposal-label {
    font-weight: 600;
    color: var(--color-text-tertiary);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.email-proposal-price {
    margin-left: auto;
    font-weight: 600;
    color: var(--color-accent);
}

.form-grid {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.form-row-half {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

/* Outcome */
.outcome-display {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    padding: 24px;
}

.outcome-header-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.outcome-date {
    font-size: 13px;
    color: var(--color-text-tertiary);
}

.outcome-details {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.outcome-section h4 {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 8px;
}

.outcome-text {
    font-size: 0.9375rem;
    color: var(--color-text-secondary);
    line-height: 1.7;
}

.factor-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.factor-pill {
    font-size: 12px;
    font-weight: 500;
    padding: 4px 10px;
    border-radius: 6px;
}

.factor-win {
    background: rgba(34, 197, 94, 0.1);
    color: var(--color-status-green);
}

.factor-loss {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
}

.lessons-list {
    list-style: disc;
    padding-left: 20px;
    margin: 0;
}

.lessons-list li {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 6px;
    line-height: 1.5;
}

/* Activity Timeline */
.activity-timeline {
    position: relative;
    padding-left: 24px;
}

.activity-item {
    position: relative;
    padding: 16px 0;
    padding-left: 24px;
    border-left: 2px solid var(--color-border-default);
}

.activity-item:last-child {
    border-left-color: transparent;
}

.activity-dot {
    position: absolute;
    left: -7px;
    top: 20px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--color-text-tertiary);
    border: 2px solid var(--color-bg-primary);
}

.activity-dot-created {
    background: var(--color-accent);
}

.activity-dot-proposal {
    background: #a855f7;
}

.activity-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.activity-label {
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.activity-date {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.inline-badge {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 4px;
    text-transform: capitalize;
    margin-left: 4px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--color-text-tertiary);
}

.empty-state svg {
    margin: 0 auto 12px;
    color: var(--color-text-quaternary);
}

.empty-state p {
    font-size: 0.9375rem;
    color: var(--color-text-tertiary);
}

/* Modal */
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

/* Utility */
.capitalize {
    text-transform: capitalize;
}

.mt-4 {
    margin-top: 1rem;
}

.inline {
    display: inline;
}

/* Responsive */
@media (max-width: 768px) {
    .header-top {
        flex-direction: column;
    }

    .header-actions {
        flex-wrap: wrap;
    }

    .detail-grid {
        grid-template-columns: 1fr;
    }

    .proposal-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .proposal-meta {
        width: 100%;
        justify-content: space-between;
    }
}
</style>
