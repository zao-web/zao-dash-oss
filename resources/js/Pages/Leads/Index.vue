<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormTextarea from '@/Components/FormTextarea.vue'
import InlineSelect from '@/Components/InlineSelect.vue'
import draggable from 'vuedraggable'
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { getPreference, savePreference } from '@/composables/usePreferences'

interface Lead {
    id: number
    company_name: string
    contact_name: string
    contact_email: string
    contact_phone?: string
    website: string | null
    description: string | null
    stage: 'new' | 'qualified' | 'proposal' | 'negotiation' | 'won' | 'lost'
    position: number
    source: string
    deal_value: number | null
    probability: number
    expected_close_date: string | null
    assignee: { id: number; name: string } | null
    last_contacted_at: string | null
    tags: string[] | null
    notes?: string
    lost_reason?: string
}

interface Stats {
    total: number
    pipeline_value: number
    weighted_value: number
    won_this_month: number
    won_value_this_month: number
    conversion_rate: number
}

const props = defineProps<{
    leads: Lead[]
    stats: Stats
    team: { id: number; name: string }[]
}>()

const viewMode = ref<'pipeline' | 'list'>(getPreference('leads.viewMode', 'pipeline'))
const filterAssignee = ref<string | number>('all')

const ownerFilterOptions = computed(() => [
    { value: 'all', label: 'All Owners' },
    ...props.team.map(t => ({ value: t.id, label: t.name }))
])

// Modal states
const showLeadModal = ref(false)
const showDetailModal = ref(false)
const showCloseModal = ref(false)
const showDeleteModal = ref(false)
const editingLead = ref<Lead | null>(null)
const selectedLead = ref<Lead | null>(null)
const closingLead = ref<Lead | null>(null)
const leadToDelete = ref<Lead | null>(null)
const closeType = ref<'won' | 'lost'>('won')
const isSaving = ref(false)
const isDeleting = ref(false)

// Lead form
const leadForm = reactive({
    company_name: '',
    contact_name: '',
    contact_email: '',
    contact_phone: '',
    website: '',
    description: '',
    stage: 'new' as Lead['stage'],
    source: 'website',
    deal_value: '',
    probability: 50,
    expected_close_date: '',
    assignee_id: '' as string | number,
    notes: '',
    lost_reason: '',
})

// Close deal form
const closeForm = reactive({
    final_value: '',
    close_notes: '',
    lost_reason: '',
})

const stages = [
    { key: 'new', label: 'New', color: 'var(--color-text-tertiary)' },
    { key: 'qualified', label: 'Qualified', color: 'var(--color-status-blue)' },
    { key: 'proposal', label: 'Proposal', color: 'var(--color-accent)' },
    { key: 'negotiation', label: 'Negotiation', color: 'var(--color-status-orange)' },
    { key: 'won', label: 'Won', color: 'var(--color-status-green)' },
    { key: 'lost', label: 'Lost', color: 'var(--color-status-red)' }
]

const activeStages = stages.filter(s => !['won', 'lost'].includes(s.key))

const stageOptions = stages.map(s => ({ value: s.key, label: s.label }))
const activeStageOptions = activeStages.map(s => ({ value: s.key, label: s.label }))

const sourceOptions = [
    { value: 'website', label: 'Website' },
    { value: 'referral', label: 'Referral' },
    { value: 'linkedin', label: 'LinkedIn' },
    { value: 'cold_outreach', label: 'Cold Outreach' },
    { value: 'conference', label: 'Conference' },
    { value: 'other', label: 'Other' },
]

const lostReasonOptions = [
    { value: '', label: 'Select reason...' },
    { value: 'budget', label: 'Budget constraints' },
    { value: 'competitor', label: 'Went with competitor' },
    { value: 'timing', label: 'Bad timing' },
    { value: 'no_decision', label: 'No decision made' },
    { value: 'scope', label: 'Scope mismatch' },
    { value: 'other', label: 'Other' },
]

const filteredLeads = computed(() => {
    let result = props.leads
    if (filterAssignee.value !== 'all') {
        result = result.filter(l => l.assignee?.id === parseInt(filterAssignee.value))
    }
    return result
})

// Local leads state for optimistic drag-and-drop
const localLeads = ref<Lead[]>([...props.leads])

// Mutable arrays for each stage that vuedraggable can modify directly
const stageArrays = reactive<Record<string, Lead[]>>({})

// Initialize stage arrays from local leads
const initStageArrays = () => {
    for (const stage of stages) {
        stageArrays[stage.key] = localLeads.value
            .filter(l => l.stage === stage.key)
            .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
    }
}

// Initialize on mount
initStageArrays()

// Sync with props when they change (e.g., after server response)
watch(() => props.leads, (newLeads) => {
    localLeads.value = [...newLeads]
    initStageArrays()
}, { deep: true })

const localFilteredLeads = computed(() => {
    let result = localLeads.value
    if (filterAssignee.value !== 'all') {
        result = result.filter(l => l.assignee?.id === parseInt(filterAssignee.value))
    }
    return result
})

// Computed for reading (used in template for counts, values, etc.)
const leadsByStage = computed(() => stageArrays)

// Drag handler for optimistic updates
const onDragChange = (evt: any, targetStage: string) => {
    // Skip if dropping into won/lost (these require modal confirmation)
    if (targetStage === 'won' || targetStage === 'lost') {
        const item = evt.added?.element
        if (item) {
            // Revert by reinitializing from props
            localLeads.value = [...props.leads]
            initStageArrays()
            openCloseDeal(item, targetStage as 'won' | 'lost')
        }
        return
    }

    // Only process if item was moved or added (not removed - that's handled by the target column)
    if (!evt.moved && !evt.added) {
        return
    }

    const movedLead = evt.moved?.element || evt.added?.element
    if (!movedLead) return

    // If added from another column, update the lead's stage in the element
    if (evt.added) {
        movedLead.stage = targetStage
    }

    // Collect all leads to update - use the current order in stageArrays (which vuedraggable has mutated)
    const leadsToUpdate: { id: number; position: number; stage: string }[] = []

    for (const stage of activeStages) {
        const stageLeads = stageArrays[stage.key] || []
        stageLeads.forEach((lead, index) => {
            // Update position in the lead object
            lead.position = index
            lead.stage = stage.key as Lead['stage']

            leadsToUpdate.push({
                id: lead.id,
                position: index,
                stage: stage.key
            })
        })
    }

    // Also update localLeads to stay in sync
    localLeads.value = activeStages.flatMap(s => stageArrays[s.key] || [])

    // Send all positions to server
    router.post('/leads/reorder', { leads: leadsToUpdate }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            // Revert on error
            localLeads.value = [...props.leads]
            initStageArrays()
        }
    })
}

const stageValue = (stage: string) => {
    return leadsByStage.value[stage]?.reduce((sum, l) => sum + (Number(l.deal_value) || 0), 0) || 0
}

const modalTitle = computed(() => editingLead.value ? 'Edit Lead' : 'New Lead')

const resetForm = () => {
    leadForm.company_name = ''
    leadForm.contact_name = ''
    leadForm.contact_email = ''
    leadForm.contact_phone = ''
    leadForm.website = ''
    leadForm.description = ''
    leadForm.stage = 'new'
    leadForm.source = 'website'
    leadForm.deal_value = ''
    leadForm.probability = 50
    leadForm.expected_close_date = ''
    leadForm.assignee_id = ''
    leadForm.notes = ''
}

const openNewLead = (stage?: string) => {
    editingLead.value = null
    resetForm()
    if (stage) leadForm.stage = stage as Lead['stage']
    showLeadModal.value = true
}

const openEditLead = (lead: Lead, e?: Event) => {
    e?.preventDefault()
    e?.stopPropagation()
    editingLead.value = lead
    leadForm.company_name = lead.company_name
    leadForm.contact_name = lead.contact_name
    leadForm.contact_email = lead.contact_email
    leadForm.contact_phone = lead.contact_phone || ''
    leadForm.website = lead.website || ''
    leadForm.description = lead.description || ''
    leadForm.stage = lead.stage
    leadForm.source = lead.source
    leadForm.deal_value = lead.deal_value?.toString() || ''
    leadForm.probability = lead.probability
    leadForm.expected_close_date = lead.expected_close_date || ''
    leadForm.assignee_id = lead.assignee?.id || ''
    leadForm.notes = lead.notes || ''
    showLeadModal.value = true
}

const closeLeadModal = () => {
    showLeadModal.value = false
    editingLead.value = null
    resetForm()
}

const saveLead = () => {
    isSaving.value = true
    const data = { ...leadForm }

    if (editingLead.value) {
        router.put(`/leads/${editingLead.value.id}`, data, {
            onSuccess: () => closeLeadModal(),
            onFinish: () => isSaving.value = false,
        })
    } else {
        router.post('/leads', data, {
            onSuccess: () => closeLeadModal(),
            onFinish: () => isSaving.value = false,
        })
    }
}

const openLeadDetail = (lead: Lead, pushState = true) => {
    selectedLead.value = lead
    showDetailModal.value = true
    if (pushState) {
        history.pushState(
            { view: viewMode.value, modal: 'lead', leadId: lead.id },
            '',
            `#${viewMode.value}/lead-${lead.id}`
        )
    }
}

const closeDetailModal = (goBack = false) => {
    showDetailModal.value = false
    selectedLead.value = null
    if (goBack && window.location.hash.includes('/lead-')) {
        history.back()
    } else if (window.location.hash.includes('/lead-')) {
        history.replaceState({ view: viewMode.value }, '', `#${viewMode.value}`)
    }
}

const changeStage = (lead: Lead, newStage: string) => {
    if (newStage === 'won' || newStage === 'lost') {
        openCloseDeal(lead, newStage as 'won' | 'lost')
        return
    }
    router.post(`/leads/${lead.id}/stage`, { stage: newStage }, {
        preserveScroll: true,
    })
}

const openCloseDeal = (lead: Lead, type: 'won' | 'lost') => {
    closingLead.value = lead
    closeType.value = type
    closeForm.final_value = lead.deal_value?.toString() || ''
    closeForm.close_notes = ''
    closeForm.lost_reason = ''
    showCloseModal.value = true
}

const confirmCloseDeal = () => {
    if (!closingLead.value) return
    isSaving.value = true

    const data = {
        stage: closeType.value,
        deal_value: closeForm.final_value,
        notes: closeForm.close_notes,
        lost_reason: closeType.value === 'lost' ? closeForm.lost_reason : null,
    }

    router.post(`/leads/${closingLead.value.id}/close`, data, {
        onSuccess: () => {
            showCloseModal.value = false
            closingLead.value = null
        },
        onFinish: () => isSaving.value = false,
    })
}

const openDeleteModal = (lead: Lead, e?: Event) => {
    e?.preventDefault()
    e?.stopPropagation()
    leadToDelete.value = lead
    showDeleteModal.value = true
}

const confirmDelete = () => {
    if (!leadToDelete.value) return
    isDeleting.value = true
    router.delete(`/leads/${leadToDelete.value.id}`, {
        onSuccess: () => {
            showDeleteModal.value = false
            leadToDelete.value = null
        },
        onFinish: () => isDeleting.value = false,
    })
}

const reopenLead = (lead: Lead) => {
    router.put(`/leads/${lead.id}`, { stage: 'negotiation' }, {
        preserveScroll: true,
    })
}

const assignLead = (lead: Lead, assigneeId: number | string) => {
    router.post(`/leads/${lead.id}/assign`, { assignee_id: assigneeId }, {
        preserveScroll: true,
    })
}

const getInitials = (name: string) => {
    return name.split(' ').filter(w => /^[a-zA-Z]/.test(w)).map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const formatCurrency = (value: number | string | null) => {
    const num = Number(value) || 0
    if (num >= 1000000) return `$${(num / 1000000).toFixed(1)}M`
    if (num >= 1000) return `$${(num / 1000).toFixed(0)}K`
    return `$${num.toFixed(0)}`
}

const sourceColors: Record<string, string> = {
    referral: 'var(--color-status-green)',
    website: 'var(--color-status-blue)',
    linkedin: 'var(--color-accent)',
    cold_outreach: 'var(--color-status-orange)',
    conference: 'var(--color-status-blue)',
    other: 'var(--color-text-tertiary)'
}

const getStageColor = (stage: string) => {
    return stages.find(s => s.key === stage)?.color || 'var(--color-text-tertiary)'
}

// History state management
const parseHash = () => {
    const hash = window.location.hash.replace('#', '')
    if (!hash) return { view: 'pipeline', leadId: null }

    const parts = hash.split('/')
    const view = ['pipeline', 'list'].includes(parts[0]) ? parts[0] : 'pipeline'
    let leadId = null

    if (parts[1]?.startsWith('lead-')) {
        leadId = parseInt(parts[1].replace('lead-', ''), 10)
    }

    return { view, leadId }
}

const handleHashNavigation = () => {
    const { view, leadId } = parseHash()

    // Set the view mode
    viewMode.value = view as typeof viewMode.value

    // Handle lead modal
    if (leadId) {
        const lead = props.leads.find(l => l.id === leadId)
        if (lead && !showDetailModal.value) {
            openLeadDetail(lead, false)
        }
    } else if (showDetailModal.value) {
        showDetailModal.value = false
        selectedLead.value = null
    }
}

const handlePopState = () => {
    handleHashNavigation()
}

// Watch viewMode to update URL and save preference
watch(viewMode, (newView) => {
    savePreference('leads.viewMode', newView)
    if (!showDetailModal.value) {
        const currentHash = window.location.hash.replace('#', '').split('/')[0]
        if (currentHash !== newView) {
            history.replaceState({ view: newView }, '', `#${newView}`)
        }
    }
})

onMounted(() => {
    handleHashNavigation()
    window.addEventListener('popstate', handlePopState)
})

onUnmounted(() => {
    window.removeEventListener('popstate', handlePopState)
})
</script>

<template>
    <AppLayout title="Pipeline">
        <!-- Stats -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6 mb-6">
            <div class="metric-card">
                <div class="metric-label">TOTAL LEADS</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PIPELINE VALUE</div>
                <div class="metric-value">{{ formatCurrency(stats.pipeline_value) }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">WEIGHTED VALUE</div>
                <div class="metric-value">{{ formatCurrency(stats.weighted_value) }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">WON THIS MONTH</div>
                <div class="metric-value" style="color: var(--color-status-green);">{{ stats.won_this_month }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">WON VALUE</div>
                <div class="metric-value" style="color: var(--color-status-green);">{{ formatCurrency(stats.won_value_this_month) }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">CONVERSION RATE</div>
                <div class="metric-value">{{ stats.conversion_rate }}%</div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar mb-4">
            <div class="toolbar-left">
                <div class="view-toggle">
                    <button :class="['toggle-btn', { active: viewMode === 'pipeline' }]" @click="viewMode = 'pipeline'">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <rect x="2" y="2" width="4" height="12" rx="1" stroke="currentColor" stroke-width="1.5"/>
                            <rect x="10" y="2" width="4" height="8" rx="1" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                    </button>
                    <button :class="['toggle-btn', { active: viewMode === 'list' }]" @click="viewMode = 'list'">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <InlineSelect
                    v-model="filterAssignee"
                    :options="ownerFilterOptions"
                />
            </div>

            <button class="btn btn-primary" @click="openNewLead()">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                New Lead
            </button>
        </div>

        <!-- Pipeline View -->
        <div v-if="viewMode === 'pipeline'" class="pipeline">
            <div
                v-for="stage in activeStages"
                :key="stage.key"
                class="pipeline-column"
            >
                <div class="column-header">
                    <div class="column-title">
                        <div class="stage-dot" :style="{ background: stage.color }"></div>
                        {{ stage.label }}
                    </div>
                    <div class="column-meta">
                        <span class="column-count">{{ leadsByStage[stage.key]?.length || 0 }}</span>
                        <span class="column-value">{{ formatCurrency(stageValue(stage.key)) }}</span>
                    </div>
                </div>
                <draggable
                    :list="leadsByStage[stage.key]"
                    group="leads"
                    item-key="id"
                    class="column-cards"
                    ghost-class="lead-ghost"
                    drag-class="lead-dragging"
                    :animation="150"
                    @change="(evt: any) => onDragChange(evt, stage.key)"
                >
                    <template #item="{ element: lead }">
                        <div class="lead-card" @click="openLeadDetail(lead)">
                            <div class="lead-header">
                                <span class="lead-company">{{ lead.company_name }}</span>
                                <div class="lead-actions">
                                    <span v-if="lead.deal_value" class="lead-value">{{ formatCurrency(lead.deal_value) }}</span>
                                    <button class="action-btn" @click="openEditLead(lead, $event)" title="Edit">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button class="action-btn action-btn-danger" @click="openDeleteModal(lead, $event)" title="Delete">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="lead-contact">{{ lead.contact_name }}</div>
                            <div v-if="lead.description" class="lead-desc">{{ lead.description }}</div>
                            <div class="lead-footer">
                                <div v-if="lead.assignee" class="avatar avatar-xs" :style="{ background: `hsl(${lead.assignee.id * 40}, 60%, 50%)` }">
                                    {{ getInitials(lead.assignee.name) }}
                                </div>
                                <div class="lead-meta">
                                    <span class="source-badge" :style="{ background: sourceColors[lead.source] + '20', color: sourceColors[lead.source] }">
                                        {{ lead.source }}
                                    </span>
                                    <span v-if="lead.probability" class="probability">{{ lead.probability }}%</span>
                                </div>
                            </div>
                            <div v-if="lead.expected_close_date" class="lead-close-date">
                                Close: {{ lead.expected_close_date }}
                            </div>
                        </div>
                    </template>
                    <template #footer>
                        <button class="add-lead-btn" @click="openNewLead(stage.key)">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Add lead
                        </button>
                    </template>
                </draggable>
            </div>
        </div>

        <!-- List View -->
        <div v-if="viewMode === 'list'" class="card">
            <div class="leads-table">
                <div class="table-header">
                    <div class="col-company">Company</div>
                    <div class="col-contact">Contact</div>
                    <div class="col-stage">Stage</div>
                    <div class="col-value">Value</div>
                    <div class="col-probability">Prob.</div>
                    <div class="col-source">Source</div>
                    <div class="col-owner">Owner</div>
                    <div class="col-actions">Actions</div>
                </div>
                <div v-for="lead in filteredLeads" :key="lead.id" class="table-row" @click="openLeadDetail(lead)">
                    <div class="col-company">
                        <span class="company-name">{{ lead.company_name }}</span>
                        <a v-if="lead.website" :href="lead.website" target="_blank" class="company-website" @click.stop>{{ lead.website.replace(/https?:\/\//, '') }}</a>
                    </div>
                    <div class="col-contact">
                        <span class="contact-name">{{ lead.contact_name }}</span>
                        <span class="contact-email">{{ lead.contact_email }}</span>
                    </div>
                    <div class="col-stage">
                        <select
                            :value="lead.stage"
                            class="stage-select"
                            :class="`stage-${lead.stage}`"
                            @click.stop
                            @change="changeStage(lead, ($event.target as HTMLSelectElement).value)"
                        >
                            <option v-for="s in stageOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div class="col-value">
                        {{ lead.deal_value ? formatCurrency(lead.deal_value) : '—' }}
                    </div>
                    <div class="col-probability">
                        <span class="probability-value">{{ lead.probability }}%</span>
                    </div>
                    <div class="col-source">
                        <span class="source-badge" :style="{ background: sourceColors[lead.source] + '20', color: sourceColors[lead.source] }">
                            {{ lead.source }}
                        </span>
                    </div>
                    <div class="col-owner">
                        <select
                            :value="lead.assignee?.id || ''"
                            class="assignee-select-compact"
                            @click.stop
                            @change="assignLead(lead, ($event.target as HTMLSelectElement).value)"
                        >
                            <option value="">—</option>
                            <option v-for="member in team" :key="member.id" :value="member.id">
                                {{ member.name.split(' ')[0] }}
                            </option>
                        </select>
                    </div>
                    <div class="col-actions">
                        <button class="action-btn" @click="openEditLead(lead, $event)" title="Edit">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                        <button class="action-btn action-btn-danger" @click="openDeleteModal(lead, $event)" title="Delete">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Won/Lost Summary -->
        <div class="closed-deals">
            <div class="closed-section">
                <h3>
                    <span class="stage-dot" style="background: var(--color-status-green);"></span>
                    Won ({{ leadsByStage['won']?.length || 0 }})
                </h3>
                <div class="closed-list">
                    <div v-for="lead in leadsByStage['won']?.slice(0, 5)" :key="lead.id" class="closed-item won">
                        <span>{{ lead.company_name }}</span>
                        <div class="closed-actions">
                            <span>{{ lead.deal_value ? formatCurrency(lead.deal_value) : '' }}</span>
                            <button class="reopen-btn" @click="reopenLead(lead)" title="Reopen">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div v-if="!leadsByStage['won']?.length" class="empty-closed">No won deals yet</div>
                </div>
            </div>
            <div class="closed-section">
                <h3>
                    <span class="stage-dot" style="background: var(--color-status-red);"></span>
                    Lost ({{ leadsByStage['lost']?.length || 0 }})
                </h3>
                <div class="closed-list">
                    <div v-for="lead in leadsByStage['lost']?.slice(0, 5)" :key="lead.id" class="closed-item lost">
                        <span>{{ lead.company_name }}</span>
                        <div class="closed-actions">
                            <span>{{ lead.deal_value ? formatCurrency(lead.deal_value) : '' }}</span>
                            <button class="reopen-btn" @click="reopenLead(lead)" title="Reopen">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div v-if="!leadsByStage['lost']?.length" class="empty-closed">No lost deals</div>
                </div>
            </div>
        </div>

        <!-- New/Edit Lead Modal -->
        <Modal :show="showLeadModal" size="lg" @close="closeLeadModal">
            <template #header>
                <h2 class="modal-title">{{ modalTitle }}</h2>
            </template>

            <form @submit.prevent="saveLead">
                <!-- Company Info -->
                <div class="form-section">
                    <h3 class="form-section-title">Company Information</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="leadForm.company_name"
                            label="Company Name"
                            placeholder="Acme Inc."
                            required
                        />
                        <FormInput
                            v-model="leadForm.website"
                            label="Website"
                            placeholder="https://example.com"
                        />
                    </div>
                    <FormTextarea
                        v-model="leadForm.description"
                        label="Description"
                        placeholder="Brief description of the opportunity..."
                        :rows="2"
                    />
                </div>

                <!-- Contact Info -->
                <div class="form-section">
                    <h3 class="form-section-title">Contact Information</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="leadForm.contact_name"
                            label="Contact Name"
                            placeholder="John Doe"
                            required
                        />
                        <FormInput
                            v-model="leadForm.contact_email"
                            label="Contact Email"
                            placeholder="john@example.com"
                            type="email"
                            required
                        />
                    </div>
                    <FormInput
                        v-model="leadForm.contact_phone"
                        label="Phone"
                        placeholder="+1 (555) 123-4567"
                    />
                </div>

                <!-- Deal Info -->
                <div class="form-section">
                    <h3 class="form-section-title">Deal Information</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="leadForm.deal_value"
                            label="Deal Value"
                            placeholder="50000"
                            type="number"
                            prefix="$"
                        />
                        <FormSelect
                            v-model="leadForm.stage"
                            label="Stage"
                            :options="activeStageOptions"
                        />
                    </div>
                    <div class="form-grid">
                        <FormSelect
                            v-model="leadForm.source"
                            label="Lead Source"
                            :options="sourceOptions"
                        />
                        <FormInput
                            v-model="leadForm.expected_close_date"
                            label="Expected Close Date"
                            type="date"
                        />
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Probability: {{ leadForm.probability }}%</label>
                            <input
                                type="range"
                                v-model.number="leadForm.probability"
                                min="0"
                                max="100"
                                step="5"
                                class="range-slider"
                            />
                        </div>
                        <FormSelect
                            v-model="leadForm.assignee_id"
                            label="Owner"
                            :options="[{ value: '', label: 'Unassigned' }, ...team.map(t => ({ value: t.id, label: t.name }))]"
                        />
                    </div>
                </div>

                <!-- Notes -->
                <div class="form-section">
                    <h3 class="form-section-title">Notes</h3>
                    <FormTextarea
                        v-model="leadForm.notes"
                        placeholder="Internal notes about this lead..."
                        :rows="3"
                    />
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeLeadModal">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSaving || !leadForm.company_name || !leadForm.contact_name"
                        @click="saveLead"
                    >
                        {{ isSaving ? 'Saving...' : (editingLead ? 'Update Lead' : 'Create Lead') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Lead Detail Modal -->
        <Modal :show="showDetailModal" size="lg" @close="closeDetailModal(true)">
            <template #header>
                <div class="detail-header">
                    <h2 class="modal-title">{{ selectedLead?.company_name }}</h2>
                    <span v-if="selectedLead?.deal_value" class="deal-value-large">
                        {{ formatCurrency(selectedLead.deal_value) }}
                    </span>
                </div>
            </template>

            <div v-if="selectedLead" class="lead-detail">
                <!-- Stage & Status -->
                <div class="detail-row">
                    <div class="detail-label">Stage</div>
                    <div class="detail-value">
                        <select
                            :value="selectedLead.stage"
                            class="stage-select"
                            :class="`stage-${selectedLead.stage}`"
                            @change="changeStage(selectedLead, ($event.target as HTMLSelectElement).value)"
                        >
                            <option v-for="s in stageOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                </div>

                <!-- Contact -->
                <div class="detail-section">
                    <h4>Contact</h4>
                    <div class="detail-row">
                        <div class="detail-label">Name</div>
                        <div class="detail-value">{{ selectedLead.contact_name }}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email</div>
                        <div class="detail-value">
                            <a :href="`mailto:${selectedLead.contact_email}`">{{ selectedLead.contact_email }}</a>
                        </div>
                    </div>
                    <div v-if="selectedLead.contact_phone" class="detail-row">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value">{{ selectedLead.contact_phone }}</div>
                    </div>
                </div>

                <!-- Deal Details -->
                <div class="detail-section">
                    <h4>Deal Details</h4>
                    <div class="detail-row">
                        <div class="detail-label">Source</div>
                        <div class="detail-value">
                            <span class="source-badge" :style="{ background: sourceColors[selectedLead.source] + '20', color: sourceColors[selectedLead.source] }">
                                {{ selectedLead.source }}
                            </span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Probability</div>
                        <div class="detail-value">{{ selectedLead.probability }}%</div>
                    </div>
                    <div v-if="selectedLead.expected_close_date" class="detail-row">
                        <div class="detail-label">Expected Close</div>
                        <div class="detail-value">{{ selectedLead.expected_close_date }}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Owner</div>
                        <div class="detail-value">
                            <select
                                :value="selectedLead.assignee?.id || ''"
                                class="assignee-select"
                                @change="assignLead(selectedLead, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="">Unassigned</option>
                                <option v-for="member in team" :key="member.id" :value="member.id">
                                    {{ member.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div v-if="selectedLead.description" class="detail-section">
                    <h4>Description</h4>
                    <p class="detail-description">{{ selectedLead.description }}</p>
                </div>

                <!-- Quick Actions -->
                <div class="detail-actions">
                    <button class="btn btn-secondary" @click="openEditLead(selectedLead); closeDetailModal()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit
                    </button>
                    <button class="btn btn-success" @click="openCloseDeal(selectedLead, 'won'); closeDetailModal()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Mark Won
                    </button>
                    <button class="btn btn-danger-outline" @click="openCloseDeal(selectedLead, 'lost'); closeDetailModal()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Mark Lost
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Close Deal Modal -->
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
                    <p>Congratulations on closing <strong>{{ closingLead?.company_name }}</strong>!</p>
                </div>

                <div v-if="closeType === 'lost'" class="lost-header">
                    <div class="lost-icon">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p>Mark <strong>{{ closingLead?.company_name }}</strong> as lost?</p>
                </div>

                <FormInput
                    v-if="closeType === 'won'"
                    v-model="closeForm.final_value"
                    label="Final Deal Value"
                    placeholder="50000"
                    type="number"
                    prefix="$"
                />

                <FormSelect
                    v-if="closeType === 'lost'"
                    v-model="closeForm.lost_reason"
                    label="Reason for Loss"
                    :options="lostReasonOptions"
                />

                <FormTextarea
                    v-model="closeForm.close_notes"
                    :label="closeType === 'won' ? 'Closing Notes' : 'Additional Notes'"
                    :placeholder="closeType === 'won' ? 'Any notes about the deal...' : 'What happened? What could we do better?'"
                    :rows="3"
                />
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showCloseModal = false">Cancel</button>
                    <button
                        type="button"
                        :class="closeType === 'won' ? 'btn btn-success' : 'btn btn-danger'"
                        :disabled="isSaving"
                        @click="confirmCloseDeal"
                    >
                        {{ isSaving ? 'Saving...' : (closeType === 'won' ? 'Mark as Won' : 'Mark as Lost') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" size="sm" @close="showDeleteModal = false">
            <template #header>
                <h2 class="modal-title">Delete Lead</h2>
            </template>

            <div class="delete-warning">
                <div class="delete-icon">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p>Delete <strong>{{ leadToDelete?.company_name }}</strong>?</p>
                <p class="text-caption mt-2">This action cannot be undone.</p>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showDeleteModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isDeleting"
                        @click="confirmDelete"
                    >
                        {{ isDeleting ? 'Deleting...' : 'Delete Lead' }}
                    </button>
                </div>
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
    margin-bottom: 20px;
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

/* Pipeline View */
.pipeline {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    min-height: 500px;
}

.pipeline-column {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
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
    font-size: 14px;
}

.stage-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
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

.column-value {
    font-size: 12px;
    color: var(--color-text-secondary);
    font-weight: 500;
}

.column-cards {
    flex: 1;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    overflow-y: auto;
}

.lead-card {
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 12px;
    cursor: grab;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.15s ease;
}

.lead-card:hover {
    border-color: var(--color-border-strong);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -4px rgba(0, 0, 0, 0.15), 0 4px 8px -2px rgba(0, 0, 0, 0.1);
}

.lead-card:active {
    cursor: grabbing;
    transform: scale(0.98);
}

/* Drag and drop styles */
.lead-ghost {
    opacity: 0.5;
    background: var(--color-bg-tertiary);
    border: 2px dashed var(--color-accent);
    transform: scale(0.98);
}

.lead-dragging {
    transform: rotate(2deg) scale(1.02);
    box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.25), 0 5px 15px rgba(0, 0, 0, 0.1);
    cursor: grabbing !important;
    z-index: 100;
}

.lead-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 4px;
}

.lead-company {
    font-weight: 600;
    color: var(--color-text-primary);
    font-size: 14px;
}

.lead-value {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-status-green);
    background: rgba(34, 197, 94, 0.1);
    padding: 2px 6px;
    border-radius: 4px;
}

.lead-contact {
    font-size: 13px;
    color: var(--color-text-secondary);
}

.lead-desc {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 8px;
    line-height: 1.4;
}

.lead-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
}

.lead-meta {
    display: flex;
    align-items: center;
    gap: 8px;
}

.source-badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 4px;
    text-transform: capitalize;
}

.probability {
    font-size: 11px;
    color: var(--color-text-tertiary);
}

.lead-close-date {
    font-size: 11px;
    color: var(--color-text-tertiary);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--color-border-default);
}

.avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: white;
    font-weight: 600;
}

.avatar-xs {
    width: 20px;
    height: 20px;
    font-size: 8px;
}

.avatar-sm {
    width: 24px;
    height: 24px;
    font-size: 10px;
}

.add-lead-btn {
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

.add-lead-btn:hover {
    border-color: var(--color-status-blue);
    color: var(--color-status-blue);
}

/* List View */
.leads-table {
    display: flex;
    flex-direction: column;
}

.table-header {
    display: grid;
    grid-template-columns: 1.5fr 1.5fr 100px 100px 70px 100px 70px 110px;
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
    grid-template-columns: 1.5fr 1.5fr 100px 100px 70px 100px 70px 110px;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-default);
    align-items: center;
}

.table-row:hover {
    background: var(--color-bg-tertiary);
}

.company-name {
    font-weight: 500;
    color: var(--color-text-primary);
    display: block;
}

.company-website {
    font-size: 12px;
    color: var(--color-text-tertiary);
    text-decoration: none;
}

.contact-name {
    display: block;
    color: var(--color-text-primary);
}

.contact-email {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.stage-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    text-transform: capitalize;
}

.stage-new { background: var(--color-bg-tertiary); color: var(--color-text-secondary); }
.stage-qualified { background: rgba(59, 130, 246, 0.15); color: var(--color-status-blue); }
.stage-proposal { background: rgba(139, 92, 246, 0.15); color: var(--color-accent); }
.stage-negotiation { background: rgba(249, 115, 22, 0.15); color: var(--color-status-orange); }
.stage-won { background: rgba(34, 197, 94, 0.15); color: var(--color-status-green); }
.stage-lost { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }

.probability-value {
    font-size: 13px;
    color: var(--color-text-secondary);
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

.empty-closed {
    padding: 24px;
    text-align: center;
    color: var(--color-text-tertiary);
    font-size: 13px;
}

/* Updated grid for actions column */
.table-header,
.table-row {
    grid-template-columns: 1.5fr 1.5fr 110px 100px 70px 100px 70px 90px;
}

.col-actions {
    display: flex;
    gap: 4px;
    justify-content: flex-end;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.table-row:hover .col-actions {
    opacity: 1;
}

.action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.action-btn:hover {
    background: var(--color-bg-elevated);
    color: var(--color-text-primary);
    border-color: var(--color-border-strong);
}

.action-btn-danger:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
    border-color: var(--color-status-red);
}

/* Lead card actions */
.lead-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

.lead-card .action-btn {
    width: 22px;
    height: 22px;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.lead-card:hover .action-btn {
    opacity: 1;
}

/* Stage select */
.stage-select {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.stage-select.stage-new { background: var(--color-bg-tertiary); color: var(--color-text-secondary); }
.stage-select.stage-qualified { background: rgba(59, 130, 246, 0.15); color: var(--color-status-blue); }
.stage-select.stage-proposal { background: rgba(139, 92, 246, 0.15); color: var(--color-accent); }
.stage-select.stage-negotiation { background: rgba(249, 115, 22, 0.15); color: var(--color-status-orange); }
.stage-select.stage-won { background: rgba(34, 197, 94, 0.15); color: var(--color-status-green); }
.stage-select.stage-lost { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }

/* Assignee select */
.assignee-select {
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 13px;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-secondary);
    color: var(--color-text-primary);
    cursor: pointer;
    min-width: 150px;
}

.assignee-select:hover {
    border-color: var(--color-border-strong);
}

.assignee-select-compact {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-secondary);
    color: var(--color-text-primary);
    cursor: pointer;
    max-width: 100px;
}

.assignee-select-compact:hover {
    border-color: var(--color-border-strong);
}

/* Won/Lost actions */
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

.form-group {
    margin-bottom: 1rem;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.5rem;
}

.range-slider {
    width: 100%;
    height: 6px;
    -webkit-appearance: none;
    background: var(--color-bg-tertiary);
    border-radius: 3px;
    outline: none;
    margin-top: 0.5rem;
}

.range-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    background: var(--color-accent);
    border-radius: 50%;
    cursor: pointer;
}

/* Detail modal */
.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
}

.deal-value-large {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-status-green);
}

.lead-detail {
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

.owner-display {
    display: flex;
    align-items: center;
    gap: 8px;
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
.delete-warning {
    text-align: center;
    padding: 1rem 0;
}

.delete-icon {
    display: flex;
    justify-content: center;
    margin-bottom: 1rem;
}

.delete-icon svg {
    width: 48px;
    height: 48px;
    color: var(--color-status-red);
}

.delete-warning p {
    color: var(--color-text-primary);
}
</style>
