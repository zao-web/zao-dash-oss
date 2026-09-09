<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import ProjectFormModal from '@/Components/ProjectFormModal.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormCheckbox from '@/Components/FormCheckbox.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'

interface Contact {
    id: number
    name: string
    email: string
    role: string
    is_primary: boolean
    phone?: string
}

interface Project {
    id: number
    name: string
    slug: string
    status: string
    type: string
    budget: number
    tasks_count: number
    completed_tasks_count: number
}

interface PortalUser {
    id: number
    name: string
    email: string
    created_at: string
}

interface PendingInvitation {
    id: number
    email: string
    invited_by: { id: number; name: string }
    created_at: string
    expires_at: string
}

interface SlackChannel {
    id: number
    name: string
}

interface Note {
    id: number
    content: string
    user: { id: number; name: string }
    created_at: string
}

interface Client {
    id: number
    name: string
    slug: string
    description: string | null
    health_score: number
    status: string
    website: string | null
    slack_channel: string | null
    slack_channel_id: number | null
    contacts: Contact[]
    projects: Project[]
    created_at: string
    // Billing settings
    billing_email: string | null
    billing_cc_emails: string | null
    payment_terms: string | null
    default_hourly_rate: number | null
    default_tax_rate: number | null
    recurring_invoice_enabled: boolean
    recurring_invoice_amount: number | null
    recurring_invoice_day: number | null
    recurring_invoice_auto_send: boolean
    recurring_invoice_description: string | null
    recurring_invoice_project_id: number | null
}

interface Stats {
    total_projects: number
    active_projects: number
    total_budget: number
    total_tasks: number
    completed_tasks: number
}

interface ReminderEntry { offset_days: number; enabled: boolean }
interface ReminderScheduleProp {
    has_override: boolean
    override: { enabled: boolean; entries: ReminderEntry[] } | null
    global: { enabled: boolean; entries: ReminderEntry[] }
    limits: { min_offset: number; max_offset: number }
}

const props = defineProps<{
    client: Client
    stats: Stats
    recentActivity: { id: number; type: string; description: string; time: string }[]
    portalUsers: PortalUser[]
    pendingInvitations: PendingInvitation[]
    slackChannels: SlackChannel[]
    notes: Note[]
    reminderSchedule: ReminderScheduleProp
}>()

const activeTab = ref<'overview' | 'projects' | 'contacts' | 'access' | 'notes' | 'reports' | 'settings'>('overview')
const projectFilter = ref<'all' | 'active' | 'completed' | 'on-hold'>('all')

// Reports tab state
interface ReportSettings {
    id: number
    is_enabled: boolean
    frequency: 'weekly' | 'monthly' | 'quarterly'
    send_day: number
    recipients: string[]
    include_time_breakdown: boolean
    include_github_activity: boolean
    include_tasks_completed: boolean
    include_financials: boolean
    include_upcoming: boolean
    custom_branding: Record<string, string>
}

interface Report {
    id: number
    period_label: string
    period_start: string
    period_end: string
    report_type: string
    status: 'draft' | 'generated' | 'sent' | 'failed'
    total_hours: number | null
    tasks_completed: number | null
    prs_merged: number | null
    sent_at: string | null
    opens_count: number
    can_regenerate: boolean
    has_pdf: boolean
    created_at: string
}

const reportsLoading = ref(false)
const reportsData = ref<{ settings: ReportSettings | null, reports: Report[], contacts: Contact[] }>({
    settings: null,
    reports: [],
    contacts: [],
})
const reportSettingsForm = ref({
    is_enabled: false,
    frequency: 'monthly' as 'weekly' | 'monthly' | 'quarterly',
    send_day: 1,
    recipients: [] as string[],
    include_time_breakdown: true,
    include_github_activity: true,
    include_tasks_completed: true,
    include_financials: false,
    include_upcoming: true,
})
const reportSettingsSaving = ref(false)
const showGenerateReportModal = ref(false)
const showSendReportModal = ref(false)
const selectedReport = ref<Report | null>(null)
const generateForm = ref({
    period_start: '',
    period_end: '',
    report_type: 'monthly',
})
const generating = ref(false)
const sendForm = ref({
    recipients: [] as string[],
})
const sending = ref(false)

// Modal states
const showAddContactModal = ref(false)
const showEditContactModal = ref(false)
const showEmailModal = ref(false)
const showNewProjectModal = ref(false)
const showInviteModal = ref(false)
const showAddNoteModal = ref(false)

// Currently editing contact
const editingContact = ref<Contact | null>(null)
const emailingContact = ref<Contact | null>(null)
const invitingContact = ref<Contact | null>(null)

// Form states
const contactForm = ref({
    name: '',
    email: '',
    role: '',
    phone: '',
    is_primary: false,
})

const editContactForm = ref({
    name: '',
    email: '',
    role: '',
    phone: '',
    is_primary: false,
})

const emailForm = ref({
    subject: '',
    body: '',
})

const contactFormProcessing = ref(false)
const noteFormProcessing = ref(false)

const noteForm = ref({
    content: '',
})

// Settings form
const settingsForm = ref({
    slack_channel_id: props.client.slack_channel_id,
    // Billing
    billing_email: props.client.billing_email ?? '',
    billing_cc_emails: props.client.billing_cc_emails ?? '',
    payment_terms: props.client.payment_terms ?? 'Net 30',
    default_hourly_rate: props.client.default_hourly_rate,
    default_tax_rate: props.client.default_tax_rate,
    // Recurring invoices
    recurring_invoice_enabled: props.client.recurring_invoice_enabled,
    recurring_invoice_amount: props.client.recurring_invoice_amount,
    recurring_invoice_day: props.client.recurring_invoice_day ?? 1,
    recurring_invoice_auto_send: props.client.recurring_invoice_auto_send,
    recurring_invoice_description: props.client.recurring_invoice_description,
    recurring_invoice_project_id: props.client.recurring_invoice_project_id,
})
const settingsFormProcessing = ref(false)

const slackChannelOptions = computed(() => [
    { value: null, label: 'No channel selected' },
    ...props.slackChannels.map(c => ({ value: c.id, label: `#${c.name}` })),
])

const projectOptions = computed(() => [
    { value: null, label: 'No project' },
    ...props.client.projects.map(p => ({ value: p.id, label: p.name })),
])

const dayOptions = Array.from({ length: 28 }, (_, i) => ({
    value: i + 1,
    label: `${i + 1}${i === 0 ? 'st' : i === 1 ? 'nd' : i === 2 ? 'rd' : 'th'}`,
}))

const paymentTermsOptions = [
    { value: 'Due on Receipt', label: 'Due on Receipt' },
    { value: 'Net 15', label: 'Net 15' },
    { value: 'Net 30', label: 'Net 30' },
    { value: 'Net 45', label: 'Net 45' },
    { value: 'Net 60', label: 'Net 60' },
]

// Reminder schedule override form
const reminderUseOverride = ref(props.reminderSchedule.has_override)
const reminderForm = ref<{ enabled: boolean; entries: ReminderEntry[] }>({
    enabled: props.reminderSchedule.override?.enabled ?? props.reminderSchedule.global.enabled,
    entries: (props.reminderSchedule.override?.entries ?? props.reminderSchedule.global.entries).map(e => ({ ...e })),
})
const reminderFormProcessing = ref(false)

const reminderLabel = (offset: number): string => {
    if (offset < 0) return `${Math.abs(offset)} day${offset === -1 ? '' : 's'} before due`
    if (offset === 0) return 'On due date'
    return `${offset} day${offset === 1 ? '' : 's'} after due`
}

const addReminderRow = () => {
    const max = reminderForm.value.entries.reduce((m, e) => Math.max(m, e.offset_days), 0)
    const next = Math.min(props.reminderSchedule.limits.max_offset, max + 7)
    reminderForm.value.entries.push({ offset_days: next, enabled: true })
}

const removeReminderRow = (idx: number) => {
    reminderForm.value.entries.splice(idx, 1)
}

const saveReminderOverride = () => {
    reminderFormProcessing.value = true
    router.put(`/invoices/settings/reminders/clients/${props.client.id}`, {
        enabled: reminderForm.value.enabled,
        entries: reminderForm.value.entries,
    }, {
        preserveScroll: true,
        onFinish: () => { reminderFormProcessing.value = false },
    })
}

const clearReminderOverride = () => {
    if (!confirm(`Remove the custom reminder schedule for ${props.client.name}? Their invoices will use the global schedule.`)) return
    reminderFormProcessing.value = true
    router.delete(`/invoices/settings/reminders/clients/${props.client.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            reminderUseOverride.value = false
            reminderForm.value = {
                enabled: props.reminderSchedule.global.enabled,
                entries: props.reminderSchedule.global.entries.map(e => ({ ...e })),
            }
        },
        onFinish: () => { reminderFormProcessing.value = false },
    })
}

const saveSettings = () => {
    settingsFormProcessing.value = true
    router.put(`/clients/${props.client.id}`, {
        name: props.client.name,
        slack_channel_id: settingsForm.value.slack_channel_id,
        // Billing
        billing_email: settingsForm.value.billing_email || null,
        billing_cc_emails: settingsForm.value.billing_cc_emails || null,
        payment_terms: settingsForm.value.payment_terms,
        default_hourly_rate: settingsForm.value.default_hourly_rate,
        default_tax_rate: settingsForm.value.default_tax_rate,
        // Recurring invoices
        recurring_invoice_enabled: settingsForm.value.recurring_invoice_enabled,
        recurring_invoice_amount: settingsForm.value.recurring_invoice_amount,
        recurring_invoice_day: settingsForm.value.recurring_invoice_day,
        recurring_invoice_auto_send: settingsForm.value.recurring_invoice_auto_send,
        recurring_invoice_description: settingsForm.value.recurring_invoice_description,
        recurring_invoice_project_id: settingsForm.value.recurring_invoice_project_id,
    }, {
        preserveScroll: true,
        onFinish: () => {
            settingsFormProcessing.value = false
        },
    })
}

const healthColor = computed(() => {
    const score = typeof props.client.health_score === 'string' ? parseFloat(props.client.health_score) : props.client.health_score
    if (score >= 8) return 'var(--color-status-green)'
    if (score >= 6) return 'var(--color-status-orange)'
    return 'var(--color-status-red)'
})

const healthLabel = computed(() => {
    const score = props.client.health_score
    if (score >= 8) return 'Excellent'
    if (score >= 6) return 'Good'
    if (score >= 4) return 'At Risk'
    return 'Critical'
})

const getInitials = (name: string) => {
    return name.split(' ').filter(w => /^[a-zA-Z]/.test(w)).map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getProjectProgress = (p: Project) => {
    if (p.tasks_count === 0) return 0
    return Math.round((p.completed_tasks_count / p.tasks_count) * 100)
}

const filteredProjects = computed(() => {
    if (projectFilter.value === 'all') return props.client.projects
    return props.client.projects.filter(p => {
        if (projectFilter.value === 'on-hold') return p.status === 'on-hold' || p.status === 'on_hold'
        return p.status === projectFilter.value
    })
})

const submitContactForm = () => {
    contactFormProcessing.value = true
    router.post(`/clients/${props.client.id}/contacts`, contactForm.value, {
        onSuccess: () => {
            showAddContactModal.value = false
            contactForm.value = {
                name: '',
                email: '',
                role: '',
                phone: '',
                is_primary: false,
            }
        },
        onFinish: () => {
            contactFormProcessing.value = false
        },
    })
}

const handleProjectModalClose = () => {
    showNewProjectModal.value = false
}

// Contact editing functions
const openEditContact = (contact: Contact) => {
    editingContact.value = contact
    editContactForm.value = {
        name: contact.name,
        email: contact.email,
        role: contact.role,
        phone: contact.phone || '',
        is_primary: contact.is_primary,
    }
    showEditContactModal.value = true
}

const updateContact = () => {
    if (!editingContact.value) return
    contactFormProcessing.value = true
    router.put(`/clients/${props.client.id}/contacts/${editingContact.value.id}`, editContactForm.value, {
        onSuccess: () => {
            showEditContactModal.value = false
            editingContact.value = null
        },
        onFinish: () => {
            contactFormProcessing.value = false
        },
    })
}

const deleteContact = () => {
    if (!editingContact.value) return
    if (!confirm(`Delete ${editingContact.value.name}?`)) return
    router.delete(`/clients/${props.client.id}/contacts/${editingContact.value.id}`, {
        onSuccess: () => {
            showEditContactModal.value = false
            editingContact.value = null
        },
    })
}

// Email composition
const openEmailModal = (contact: Contact) => {
    emailingContact.value = contact
    emailForm.value = { subject: '', body: '' }
    showEmailModal.value = true
}

const emailFormProcessing = ref(false)

const sendEmail = () => {
    if (!emailingContact.value) return
    emailFormProcessing.value = true
    router.post(`/clients/${props.client.id}/contacts/${emailingContact.value.id}/email`, emailForm.value, {
        onSuccess: () => {
            showEmailModal.value = false
            emailingContact.value = null
            emailForm.value = { subject: '', body: '' }
        },
        onFinish: () => {
            emailFormProcessing.value = false
        },
    })
}

// Note functions
const submitNoteForm = () => {
    noteFormProcessing.value = true
    router.post(`/clients/${props.client.id}/notes`, noteForm.value, {
        onSuccess: () => {
            showAddNoteModal.value = false
            noteForm.value = { content: '' }
        },
        onFinish: () => {
            noteFormProcessing.value = false
        },
    })
}

const deleteNote = (noteId: number) => {
    if (!confirm('Delete this note?')) return
    router.delete(`/clients/${props.client.id}/notes/${noteId}`)
}

// Portal invitation
const inviteFormProcessing = ref(false)

const openInviteModal = (contact: Contact) => {
    invitingContact.value = contact
    showInviteModal.value = true
}

const inviteError = ref('')

const sendInvitation = () => {
    if (!invitingContact.value) return
    inviteFormProcessing.value = true
    inviteError.value = ''
    router.post(`/clients/${props.client.id}/invite`, {
        email: invitingContact.value.email,
        contact_id: invitingContact.value.id,
    }, {
        onSuccess: () => {
            showInviteModal.value = false
            invitingContact.value = null
        },
        onError: (errors) => {
            inviteError.value = errors.email || errors.invitation || Object.values(errors)[0] || 'Failed to send invitation'
        },
        onFinish: () => {
            inviteFormProcessing.value = false
        },
    })
}

// View portal as client (impersonation)
const viewPortal = () => {
    router.post(`/impersonate/client/${props.client.id}`)
}

// Portal access management
const revokeAccess = (userId: number, userName: string) => {
    if (!confirm(`Revoke portal access for ${userName}?`)) return
    router.delete(`/users/${userId}/revoke-client-access`)
}

const resendInvitation = (invitationId: number) => {
    router.post(`/invitations/${invitationId}/resend`)
}

const cancelInvitation = (invitationId: number, email: string) => {
    if (!confirm(`Cancel invitation for ${email}?`)) return
    router.delete(`/invitations/${invitationId}`)
}

// Reports tab functions
const loadReportsData = async () => {
    if (reportsLoading.value) return
    reportsLoading.value = true
    try {
        const response = await fetch(`/clients/${props.client.id}/reports`)
        const data = await response.json()
        reportsData.value = data
        if (data.settings) {
            reportSettingsForm.value = {
                is_enabled: data.settings.is_enabled,
                frequency: data.settings.frequency,
                send_day: data.settings.send_day,
                recipients: data.settings.recipients || [],
                include_time_breakdown: data.settings.include_time_breakdown,
                include_github_activity: data.settings.include_github_activity,
                include_tasks_completed: data.settings.include_tasks_completed,
                include_financials: data.settings.include_financials,
                include_upcoming: data.settings.include_upcoming,
            }
        }
    } catch (error) {
        console.error('Failed to load reports data:', error)
    } finally {
        reportsLoading.value = false
    }
}

const saveReportSettings = () => {
    reportSettingsSaving.value = true
    router.put(`/clients/${props.client.id}/reports/settings`, reportSettingsForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            loadReportsData()
        },
        onFinish: () => {
            reportSettingsSaving.value = false
        },
    })
}

const generateReport = () => {
    generating.value = true
    router.post(`/clients/${props.client.id}/reports/generate`, generateForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            showGenerateReportModal.value = false
            generateForm.value = { period_start: '', period_end: '', report_type: 'monthly' }
            loadReportsData()
        },
        onFinish: () => {
            generating.value = false
        },
    })
}

const generateLastMonthReport = () => {
    generating.value = true
    router.post(`/clients/${props.client.id}/reports/generate-last-month`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            loadReportsData()
        },
        onFinish: () => {
            generating.value = false
        },
    })
}

const openSendModal = (report: Report) => {
    selectedReport.value = report
    sendForm.value.recipients = reportSettingsForm.value.recipients.length > 0
        ? [...reportSettingsForm.value.recipients]
        : reportsData.value.contacts.filter(c => c.is_primary).map(c => c.email)
    showSendReportModal.value = true
}

const sendReport = () => {
    if (!selectedReport.value) return
    sending.value = true
    router.post(`/clients/${props.client.id}/reports/${selectedReport.value.id}/send`, sendForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            showSendReportModal.value = false
            selectedReport.value = null
            loadReportsData()
        },
        onFinish: () => {
            sending.value = false
        },
    })
}

const regenerateReport = (report: Report) => {
    if (!confirm('Regenerate this report? This will replace the current version.')) return
    router.post(`/clients/${props.client.id}/reports/${report.id}/regenerate`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            loadReportsData()
        },
    })
}

const deleteReport = (report: Report) => {
    if (!confirm(`Delete the ${report.period_label} report?`)) return
    router.delete(`/clients/${props.client.id}/reports/${report.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            loadReportsData()
        },
    })
}

const previewReport = (report: Report) => {
    window.open(`/clients/${props.client.id}/reports/${report.id}/preview`, '_blank')
}

const downloadReport = (report: Report) => {
    window.location.href = `/clients/${props.client.id}/reports/${report.id}/download`
}

const viewPdf = (report: Report) => {
    window.open(`/clients/${props.client.id}/reports/${report.id}/pdf`, '_blank')
}

const getStatusColor = (status: string) => {
    switch (status) {
        case 'sent': return 'var(--color-status-green)'
        case 'generated': return 'var(--color-status-blue)'
        case 'draft': return 'var(--color-status-orange)'
        case 'failed': return 'var(--color-status-red)'
        default: return 'var(--color-text-tertiary)'
    }
}

// History state management for tabs
const handleHashNavigation = () => {
    const hash = window.location.hash.replace('#', '')
    if (['overview', 'projects', 'contacts', 'access', 'notes', 'reports', 'settings'].includes(hash)) {
        activeTab.value = hash as typeof activeTab.value
    }
}

const handlePopState = () => {
    handleHashNavigation()
}

watch(activeTab, (newTab) => {
    if (window.location.hash.replace('#', '') !== newTab) {
        history.replaceState({ tab: newTab }, '', `#${newTab}`)
    }
    // Load reports data when tab is selected
    if (newTab === 'reports' && !reportsData.value.settings) {
        loadReportsData()
    }
})

onMounted(() => {
    handleHashNavigation()
    window.addEventListener('popstate', handlePopState)

    // Check for create_project query param (from Focus Panel action)
    const urlParams = new URLSearchParams(window.location.search)
    if (urlParams.get('create_project') === '1') {
        // Open the project creation modal
        showNewProjectModal.value = true
        // Clean up URL without reloading
        window.history.replaceState({}, '', window.location.pathname + window.location.hash)
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
            <div class="client-header">
                <div class="client-avatar" :style="{ background: `hsl(${client.id * 40}, 50%, 45%)` }">
                    {{ getInitials(client.name) }}
                </div>
                <div class="client-info">
                    <div class="client-name-row">
                        <h1>{{ client.name }}</h1>
                        <span :class="['badge', `badge-${client.status}`]">{{ client.status }}</span>
                    </div>
                    <p v-if="client.description" class="client-description">{{ client.description }}</p>
                    <div class="client-meta">
                        <a v-if="client.website" :href="client.website" target="_blank" class="meta-link">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M2 8h12M8 2c2 2.5 2 9.5 0 12M8 2c-2 2.5-2 9.5 0 12" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            {{ client.website.replace(/https?:\/\//, '') }}
                        </a>
                        <span v-if="client.slack_channel" class="meta-item">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <path d="M5.5 2a1.5 1.5 0 100 3H7V3.5A1.5 1.5 0 005.5 2zM2 5.5a1.5 1.5 0 103 0V4H3.5A1.5 1.5 0 002 5.5z" fill="currentColor"/>
                                <path d="M10.5 14a1.5 1.5 0 100-3H9v1.5a1.5 1.5 0 001.5 1.5zM14 10.5a1.5 1.5 0 10-3 0V12h1.5a1.5 1.5 0 001.5-1.5z" fill="currentColor"/>
                                <path d="M5.5 14a1.5 1.5 0 001.5-1.5V9H5.5a1.5 1.5 0 000 3zM2 10.5A1.5 1.5 0 003.5 12H7V9H3.5A1.5 1.5 0 002 10.5z" fill="currentColor"/>
                                <path d="M10.5 2A1.5 1.5 0 009 3.5V7h1.5a1.5 1.5 0 000-3zM14 5.5A1.5 1.5 0 0012.5 4H9v3h3.5A1.5 1.5 0 0014 5.5z" fill="currentColor"/>
                            </svg>
                            {{ client.slack_channel }}
                        </span>
                    </div>
                </div>
                <div class="header-actions">
                    <Link :href="`/invoices/create?client_id=${client.id}`" class="btn btn-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Create Invoice
                    </Link>
                    <form method="POST" :action="`/impersonate/client/${client.id}`" @submit.prevent="viewPortal">
                        <button type="submit" class="btn btn-secondary">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M8 3C4.5 3 1.5 6 1.5 8s3 5 6.5 5 6.5-3 6.5-5-3-5-6.5-5z" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            View Portal
                        </button>
                    </form>
                    <div class="health-score">
                        <div class="health-ring" :style="{ '--health-color': healthColor }">
                            <div class="health-value">{{ client.health_score }}</div>
                        </div>
                        <div class="health-label" :style="{ color: healthColor }">{{ healthLabel }}</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button
                    v-for="tab in ['overview', 'projects', 'contacts', 'access', 'notes', 'reports', 'settings']"
                    :key="tab"
                    :class="['tab', { active: activeTab === tab }]"
                    @click="activeTab = tab as any"
                >
                    {{ tab === 'access' ? 'Portal Access' : tab.charAt(0).toUpperCase() + tab.slice(1) }}
                </button>
            </div>

            <!-- Overview Tab -->
            <div v-if="activeTab === 'overview'" class="tab-content">
                <div class="stats-grid">
                    <div class="metric-card">
                        <div class="metric-label">ACTIVE PROJECTS</div>
                        <div class="metric-value">{{ stats.active_projects }}</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">TOTAL BUDGET</div>
                        <div class="metric-value">${{ stats.total_budget.toLocaleString() }}</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">TASKS COMPLETED</div>
                        <div class="metric-value">{{ stats.completed_tasks }}<span class="metric-sub">/{{ stats.total_tasks }}</span></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">PRIMARY CONTACT</div>
                        <div class="metric-value" style="font-size: 18px;">
                            {{ client.contacts.find(c => c.is_primary)?.name || 'None' }}
                        </div>
                    </div>
                </div>

                <div class="two-col">
                    <!-- Health Factors -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Health Score Factors</h3>
                        </div>
                        <div class="health-factors">
                            <div class="factor-item">
                                <div class="factor-header">
                                    <span class="factor-name">Response Time</span>
                                    <span class="factor-score">9.2</span>
                                </div>
                                <div class="factor-bar">
                                    <div class="factor-fill" style="width: 92%; background: var(--color-status-green);"></div>
                                </div>
                            </div>
                            <div class="factor-item">
                                <div class="factor-header">
                                    <span class="factor-name">Project Progress</span>
                                    <span class="factor-score">8.5</span>
                                </div>
                                <div class="factor-bar">
                                    <div class="factor-fill" style="width: 85%; background: var(--color-status-green);"></div>
                                </div>
                            </div>
                            <div class="factor-item">
                                <div class="factor-header">
                                    <span class="factor-name">Payment History</span>
                                    <span class="factor-score">10.0</span>
                                </div>
                                <div class="factor-bar">
                                    <div class="factor-fill" style="width: 100%; background: var(--color-status-green);"></div>
                                </div>
                            </div>
                            <div class="factor-item">
                                <div class="factor-header">
                                    <span class="factor-name">Communication</span>
                                    <span class="factor-score">7.8</span>
                                </div>
                                <div class="factor-bar">
                                    <div class="factor-fill" style="width: 78%; background: var(--color-status-orange);"></div>
                                </div>
                            </div>
                            <div class="factor-item">
                                <div class="factor-header">
                                    <span class="factor-name">Scope Stability</span>
                                    <span class="factor-score">8.0</span>
                                </div>
                                <div class="factor-bar">
                                    <div class="factor-fill" style="width: 80%; background: var(--color-status-green);"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Activity</h3>
                        </div>
                        <div class="activity-list">
                            <div v-for="activity in recentActivity" :key="activity.id" class="activity-item">
                                <div :class="['activity-dot', `activity-${activity.type}`]"></div>
                                <div class="activity-content">
                                    <div class="activity-desc">{{ activity.description }}</div>
                                    <div class="activity-time">{{ activity.time }}</div>
                                </div>
                            </div>
                            <div v-if="recentActivity.length === 0" class="empty-state">
                                No recent activity
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Projects -->
                <div class="card">
                    <div class="card-header">
                        <h3>Active Projects</h3>
                        <button class="btn btn-secondary" @click="activeTab = 'projects'">View All</button>
                    </div>
                    <div class="projects-grid">
                        <Link
                            v-for="project in client.projects.filter(p => p.status === 'active').slice(0, 3)"
                            :key="project.id"
                            :href="`/clients/${client.slug}/projects/${project.slug}`"
                            class="project-card-link"
                        >
                            <div class="project-card-mini">
                                <div class="project-card-header">
                                    <span class="project-name">{{ project.name }}</span>
                                    <span :class="['badge', `badge-${project.type}`]">{{ project.type }}</span>
                                </div>
                                <div class="project-card-progress">
                                    <div class="progress-bar">
                                        <div class="progress-fill" :style="{ width: `${getProjectProgress(project)}%` }"></div>
                                    </div>
                                    <span class="progress-text">{{ getProjectProgress(project) }}%</span>
                                </div>
                                <div class="project-card-footer">
                                    <span class="budget">${{ project.budget?.toLocaleString() || '0' }}</span>
                                    <span class="tasks">{{ project.completed_tasks_count }}/{{ project.tasks_count }} tasks</span>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Projects Tab -->
            <div v-if="activeTab === 'projects'" class="tab-content">
                <div class="projects-header">
                    <div class="project-filters">
                        <button
                            :class="['filter-btn', { active: projectFilter === 'all' }]"
                            @click="projectFilter = 'all'"
                        >
                            All
                        </button>
                        <button
                            :class="['filter-btn', { active: projectFilter === 'active' }]"
                            @click="projectFilter = 'active'"
                        >
                            Active
                        </button>
                        <button
                            :class="['filter-btn', { active: projectFilter === 'completed' }]"
                            @click="projectFilter = 'completed'"
                        >
                            Completed
                        </button>
                        <button
                            :class="['filter-btn', { active: projectFilter === 'on-hold' }]"
                            @click="projectFilter = 'on-hold'"
                        >
                            On Hold
                        </button>
                    </div>
                    <button class="btn btn-primary" @click="showNewProjectModal = true">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        New Project
                    </button>
                </div>

                <div class="card">
                    <div class="project-table">
                        <div class="table-header">
                            <div class="col-name">Project</div>
                            <div class="col-type">Type</div>
                            <div class="col-status">Status</div>
                            <div class="col-budget">Budget</div>
                            <div class="col-progress">Progress</div>
                        </div>
                        <Link
                            v-for="project in filteredProjects"
                            :key="project.id"
                            :href="`/clients/${client.slug}/projects/${project.slug}`"
                            class="table-row"
                        >
                            <div class="col-name">
                                <span class="project-name">{{ project.name }}</span>
                            </div>
                            <div class="col-type">
                                <span class="badge" style="background: var(--color-bg-tertiary);">{{ project.type }}</span>
                            </div>
                            <div class="col-status">
                                <span :class="['badge', `badge-${project.status}`]">{{ project.status }}</span>
                            </div>
                            <div class="col-budget">${{ project.budget?.toLocaleString() || '0' }}</div>
                            <div class="col-progress">
                                <div class="progress-bar" style="width: 100px;">
                                    <div class="progress-fill" :style="{ width: `${getProjectProgress(project)}%` }"></div>
                                </div>
                                <span class="progress-pct">{{ getProjectProgress(project) }}%</span>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Contacts Tab -->
            <div v-if="activeTab === 'contacts'" class="tab-content">
                <div class="contacts-header">
                    <button class="btn btn-primary" @click="showAddContactModal = true">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                            <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Add Contact
                    </button>
                </div>

                <div class="contacts-grid">
                    <div v-for="contact in client.contacts" :key="contact.id" class="contact-card">
                        <div class="contact-header">
                            <div class="contact-avatar" :style="{ background: `hsl(${contact.id * 60}, 50%, 50%)` }">
                                {{ getInitials(contact.name) }}
                            </div>
                            <div v-if="contact.is_primary" class="primary-badge">Primary</div>
                        </div>
                        <div class="contact-name">{{ contact.name }}</div>
                        <div class="contact-role">{{ contact.role }}</div>
                        <div class="contact-details">
                            <a :href="`mailto:${contact.email}`" class="contact-email">{{ contact.email }}</a>
                        </div>
                        <div class="contact-actions">
                            <button class="btn btn-secondary btn-sm" @click="openEmailModal(contact)">Email</button>
                            <button class="btn btn-ghost btn-sm" @click="openEditContact(contact)">Edit</button>
                            <button class="btn btn-accent btn-sm" @click="openInviteModal(contact)">Invite</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Portal Access Tab -->
            <div v-if="activeTab === 'access'" class="tab-content">
                <div class="access-grid">
                    <!-- Users with Access -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Users with Portal Access</h3>
                        </div>
                        <div class="access-list">
                            <div v-for="user in portalUsers" :key="user.id" class="access-item">
                                <div class="access-avatar" :style="{ background: `hsl(${user.id * 60}, 50%, 50%)` }">
                                    {{ getInitials(user.name) }}
                                </div>
                                <div class="access-info">
                                    <div class="access-name">{{ user.name }}</div>
                                    <div class="access-email">{{ user.email }}</div>
                                </div>
                                <button class="btn btn-danger btn-sm" @click="revokeAccess(user.id, user.name)">
                                    Revoke
                                </button>
                            </div>
                            <div v-if="portalUsers.length === 0" class="empty-state">
                                No users have portal access yet
                            </div>
                        </div>
                    </div>

                    <!-- Pending Invitations -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Pending Invitations</h3>
                        </div>
                        <div class="access-list">
                            <div v-for="invitation in pendingInvitations" :key="invitation.id" class="access-item">
                                <div class="access-avatar" style="background: var(--color-status-orange);">
                                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/>
                                        <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/>
                                    </svg>
                                </div>
                                <div class="access-info">
                                    <div class="access-name">{{ invitation.email }}</div>
                                    <div class="access-meta">
                                        Invited by {{ invitation.invited_by.name }}
                                    </div>
                                </div>
                                <div class="access-actions">
                                    <button class="btn btn-secondary btn-sm" @click="resendInvitation(invitation.id)">
                                        Resend
                                    </button>
                                    <button class="btn btn-ghost btn-sm" @click="cancelInvitation(invitation.id, invitation.email)">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                            <div v-if="pendingInvitations.length === 0" class="empty-state">
                                No pending invitations
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes Tab -->
            <div v-if="activeTab === 'notes'" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Internal Notes</h3>
                        <button class="btn btn-primary" @click="showAddNoteModal = true">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Add Note
                        </button>
                    </div>
                    <div class="notes-list">
                        <div v-for="note in notes" :key="note.id" class="note-item">
                            <div class="note-header">
                                <div class="note-author">
                                    <div class="avatar avatar-sm" :style="{ background: `hsl(${note.user.id * 60}, 60%, 50%)` }">
                                        {{ getInitials(note.user.name) }}
                                    </div>
                                    <span>{{ note.user.name }}</span>
                                </div>
                                <div class="note-actions">
                                    <span class="note-date">{{ note.created_at }}</span>
                                    <button class="btn btn-ghost btn-xs" @click="deleteNote(note.id)" title="Delete note">
                                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                            <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="note-content">{{ note.content }}</div>
                        </div>
                        <div v-if="notes.length === 0" class="empty-state">
                            No notes yet. Add a note to keep track of important client information.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reports Tab -->
            <div v-if="activeTab === 'reports'" class="tab-content">
                <!-- Loading State -->
                <div v-if="reportsLoading" class="loading-state">
                    <div class="loading-spinner"></div>
                    <p>Loading reports...</p>
                </div>

                <template v-else>
                    <!-- Report Settings -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Automated Reports</h3>
                            <span :class="['badge', reportSettingsForm.is_enabled ? 'badge-active' : 'badge-inactive']">
                                {{ reportSettingsForm.is_enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                        <div class="settings-form">
                            <FormCheckbox
                                v-model="reportSettingsForm.is_enabled"
                                label="Enable automated monthly reports for this client"
                            />

                            <div v-if="reportSettingsForm.is_enabled" class="report-settings-details">
                                <div class="form-row">
                                    <div class="form-group">
                                        <FormSelect
                                            v-model="reportSettingsForm.frequency"
                                            label="Frequency"
                                            :options="[
                                                { value: 'weekly', label: 'Weekly' },
                                                { value: 'monthly', label: 'Monthly' },
                                                { value: 'quarterly', label: 'Quarterly' },
                                            ]"
                                        />
                                    </div>
                                    <div class="form-group">
                                        <FormSelect
                                            v-model="reportSettingsForm.send_day"
                                            :label="reportSettingsForm.frequency === 'weekly' ? 'Day of Week' : 'Day of Month'"
                                            :options="reportSettingsForm.frequency === 'weekly'
                                                ? [
                                                    { value: 0, label: 'Sunday' },
                                                    { value: 1, label: 'Monday' },
                                                    { value: 2, label: 'Tuesday' },
                                                    { value: 3, label: 'Wednesday' },
                                                    { value: 4, label: 'Thursday' },
                                                    { value: 5, label: 'Friday' },
                                                    { value: 6, label: 'Saturday' },
                                                ]
                                                : Array.from({ length: 28 }, (_, i) => ({
                                                    value: i + 1,
                                                    label: `${i + 1}${i === 0 ? 'st' : i === 1 ? 'nd' : i === 2 ? 'rd' : 'th'}`,
                                                }))"
                                        />
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Recipients</label>
                                    <div class="recipient-chips">
                                        <span
                                            v-for="(email, index) in reportSettingsForm.recipients"
                                            :key="email"
                                            class="recipient-chip"
                                        >
                                            {{ email }}
                                            <button type="button" @click="reportSettingsForm.recipients.splice(index, 1)">&times;</button>
                                        </span>
                                        <FormSelect
                                            v-if="reportsData.contacts.length > 0"
                                            :model-value="null"
                                            placeholder="Add recipient..."
                                            :options="reportsData.contacts
                                                .filter(c => !reportSettingsForm.recipients.includes(c.email))
                                                .map(c => ({ value: c.email, label: `${c.name} (${c.email})` }))"
                                            @update:model-value="(val: string | null) => val && reportSettingsForm.recipients.push(val)"
                                            class="add-recipient-select"
                                        />
                                    </div>
                                    <span class="form-hint">Select contacts to receive automated reports</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Report Contents</label>
                                    <div class="report-content-options">
                                        <FormCheckbox
                                            v-model="reportSettingsForm.include_time_breakdown"
                                            label="Time breakdown by category"
                                        />
                                        <FormCheckbox
                                            v-model="reportSettingsForm.include_github_activity"
                                            label="GitHub activity (PRs, issues)"
                                        />
                                        <FormCheckbox
                                            v-model="reportSettingsForm.include_tasks_completed"
                                            label="Tasks completed"
                                        />
                                        <FormCheckbox
                                            v-model="reportSettingsForm.include_upcoming"
                                            label="Upcoming work preview"
                                        />
                                        <FormCheckbox
                                            v-model="reportSettingsForm.include_financials"
                                            label="Financial summary"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="settings-actions" style="padding: 0; margin-top: 16px;">
                                <button
                                    class="btn btn-primary"
                                    :disabled="reportSettingsSaving"
                                    @click="saveReportSettings"
                                >
                                    {{ reportSettingsSaving ? 'Saving...' : 'Save Settings' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Reports List -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Report History</h3>
                            <div class="header-actions-inline">
                                <button class="btn btn-secondary" :disabled="generating" @click="generateLastMonthReport">
                                    {{ generating ? 'Generating...' : 'Generate Last Month' }}
                                </button>
                                <button class="btn btn-primary" @click="showGenerateReportModal = true">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                        <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    Custom Report
                                </button>
                            </div>
                        </div>
                        <div class="reports-list">
                            <div v-if="reportsData.reports.length === 0" class="empty-state">
                                No reports generated yet. Generate your first report to see how it looks before enabling automation.
                            </div>
                            <div v-else class="report-table">
                                <div class="table-header">
                                    <div class="col-period">Period</div>
                                    <div class="col-metrics">Metrics</div>
                                    <div class="col-status">Status</div>
                                    <div class="col-sent">Sent</div>
                                    <div class="col-actions">Actions</div>
                                </div>
                                <div v-for="report in reportsData.reports" :key="report.id" class="table-row">
                                    <div class="col-period">
                                        <span class="period-label">{{ report.period_label }}</span>
                                        <span class="period-dates">{{ report.period_start }} - {{ report.period_end }}</span>
                                    </div>
                                    <div class="col-metrics">
                                        <span v-if="report.total_hours" class="metric-badge">
                                            {{ Number(report.total_hours).toFixed(1) }}h
                                        </span>
                                        <span v-if="report.tasks_completed" class="metric-badge">
                                            {{ report.tasks_completed }} tasks
                                        </span>
                                        <span v-if="report.prs_merged" class="metric-badge">
                                            {{ report.prs_merged }} PRs
                                        </span>
                                    </div>
                                    <div class="col-status">
                                        <span class="status-badge" :style="{ background: getStatusColor(report.status) }">
                                            {{ report.status }}
                                        </span>
                                    </div>
                                    <div class="col-sent">
                                        <template v-if="report.sent_at">
                                            <span class="sent-date">{{ report.sent_at }}</span>
                                            <span v-if="report.opens_count > 0" class="opens-count">({{ report.opens_count }} opens)</span>
                                        </template>
                                        <span v-else class="not-sent">Not sent</span>
                                    </div>
                                    <div class="col-actions">
                                        <button class="btn btn-ghost btn-xs" title="Preview" @click="previewReport(report)">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                                <path d="M8 3C4.5 3 1.5 6 1.5 8s3 5 6.5 5 6.5-3 6.5-5-3-5-6.5-5z" stroke="currentColor" stroke-width="1.5"/>
                                                <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                                            </svg>
                                        </button>
                                        <button class="btn btn-ghost btn-xs" title="View PDF" @click="viewPdf(report)">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                                <rect x="2" y="2" width="12" height="12" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                                <path d="M5 6h6M5 8h6M5 10h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                            </svg>
                                        </button>
                                        <button v-if="report.has_pdf" class="btn btn-ghost btn-xs" title="Download PDF" @click="downloadReport(report)">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                                <path d="M3 10v3h10v-3M8 3v7M5 7l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                        <button v-if="report.status !== 'sent'" class="btn btn-ghost btn-xs" title="Send" @click="openSendModal(report)">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                                <path d="M2 3l12 5-12 5V9l8-1-8-1V3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                        <button v-if="report.can_regenerate" class="btn btn-ghost btn-xs" title="Regenerate" @click="regenerateReport(report)">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                                <path d="M2 8a6 6 0 1011.5 2.5M14 8a6 6 0 10-2.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                                <path d="M14 4v4h-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                        <button class="btn btn-ghost btn-xs" title="Delete" @click="deleteReport(report)">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                                <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Settings Tab -->
            <div v-if="activeTab === 'settings'" class="tab-content settings-tab">
                <!-- Billing Settings -->
                <div class="card">
                    <div class="card-header">
                        <h3>Billing Defaults</h3>
                    </div>
                    <div class="settings-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Default Hourly Rate</label>
                                <div class="input-with-prefix">
                                    <span class="input-prefix">$</span>
                                    <input
                                        v-model="settingsForm.default_hourly_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="form-input"
                                        placeholder="0.00"
                                    />
                                </div>
                                <span class="form-hint">Used when creating new invoice line items</span>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Default Tax Rate</label>
                                <div class="input-with-suffix">
                                    <input
                                        v-model="settingsForm.default_tax_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        class="form-input"
                                        placeholder="0.00"
                                    />
                                    <span class="input-suffix">%</span>
                                </div>
                                <span class="form-hint">Applied to invoices for this client</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recurring Invoices -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recurring Invoices</h3>
                    </div>
                    <div class="settings-form">
                        <FormCheckbox
                            v-model="settingsForm.recurring_invoice_enabled"
                            label="Enable recurring invoices for this client"
                        />

                        <div v-if="settingsForm.recurring_invoice_enabled" class="recurring-settings">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Invoice Amount</label>
                                    <div class="input-with-prefix">
                                        <span class="input-prefix">$</span>
                                        <input
                                            v-model="settingsForm.recurring_invoice_amount"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="form-input"
                                            placeholder="0.00"
                                        />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <FormSelect
                                        v-model="settingsForm.recurring_invoice_day"
                                        label="Day of Month"
                                        :options="dayOptions"
                                        placeholder="Select day"
                                    />
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Billing Email</label>
                                    <input
                                        v-model="settingsForm.billing_email"
                                        type="email"
                                        class="form-input"
                                        placeholder="billing@example.com"
                                    />
                                    <span class="form-hint">Invoice emails are sent To this address (falls back to the first contact)</span>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Billing CC Emails</label>
                                    <input
                                        v-model="settingsForm.billing_cc_emails"
                                        type="text"
                                        class="form-input"
                                        placeholder="ap@example.com, finance@example.com"
                                    />
                                    <span class="form-hint">Comma-separated; CC'd on recurring invoices and reminders</span>
                                </div>
                            </div>

                            <div class="form-row">
                                <FormSelect
                                    v-model="settingsForm.payment_terms"
                                    label="Payment Terms"
                                    :options="paymentTermsOptions"
                                />
                                <FormSelect
                                    v-model="settingsForm.recurring_invoice_project_id"
                                    label="Project"
                                    :options="projectOptions"
                                    placeholder="Select project (optional)"
                                    searchable
                                />
                            </div>

                            <div class="form-group">
                                <label class="form-label">Invoice Description</label>
                                <textarea
                                    v-model="settingsForm.recurring_invoice_description"
                                    class="form-textarea"
                                    rows="2"
                                    placeholder="e.g., Monthly retainer for {month_year}"
                                ></textarea>
                                <span class="form-hint">
                                    Merge tags: <code>{month}</code> <code>{year}</code> <code>{month_year}</code> <code>{client_name}</code> <code>{project_name}</code>
                                </span>
                            </div>

                            <FormCheckbox
                                v-model="settingsForm.recurring_invoice_auto_send"
                                label="Automatically send invoice when generated"
                            />
                        </div>
                    </div>
                </div>

                <!-- Integrations -->
                <div class="card">
                    <div class="card-header">
                        <h3>Integrations</h3>
                    </div>
                    <div class="settings-form">
                        <FormSelect
                            v-model="settingsForm.slack_channel_id"
                            label="Default Slack Channel"
                            :options="slackChannelOptions"
                            placeholder="Select a channel"
                            hint="Projects under this client will inherit this channel by default"
                            searchable
                        />
                    </div>
                </div>

                <!-- Reminder Schedule Override -->
                <div class="card" id="reminders">
                    <div class="card-header">
                        <h3>Invoice Reminder Schedule</h3>
                    </div>
                    <div class="settings-form">
                        <FormCheckbox
                            v-model="reminderUseOverride"
                            label="Use a custom reminder schedule for this client"
                        />
                        <p class="form-hint">
                            When off, this client inherits the
                            <Link href="/invoices/settings" class="link-action">global reminder schedule</Link>.
                        </p>

                        <div v-if="reminderUseOverride" class="recurring-settings">
                            <FormCheckbox
                                v-model="reminderForm.enabled"
                                label="Send reminders to this client (master switch)"
                            />
                            <div class="reminder-schedule-table" :class="{ disabled: !reminderForm.enabled }">
                                <div class="reminder-schedule-row" v-for="(entry, idx) in reminderForm.entries" :key="idx">
                                    <FormCheckbox v-model="entry.enabled" />
                                    <input
                                        v-model.number="entry.offset_days"
                                        type="number"
                                        :min="reminderSchedule.limits.min_offset"
                                        :max="reminderSchedule.limits.max_offset"
                                        step="1"
                                        class="form-input reminder-offset-input"
                                    />
                                    <span class="reminder-row-label">{{ reminderLabel(entry.offset_days) }}</span>
                                    <button type="button" class="btn-icon" @click="removeReminderRow(idx)" title="Remove">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                                <div v-if="reminderForm.entries.length === 0" class="empty-state-row">
                                    No reminders configured for this client.
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" @click="addReminderRow">+ Add reminder</button>

                            <div class="reminder-actions">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    :disabled="reminderFormProcessing"
                                    @click="saveReminderOverride"
                                >
                                    {{ reminderFormProcessing ? 'Saving…' : 'Save Override' }}
                                </button>
                                <button
                                    v-if="reminderSchedule.has_override"
                                    type="button"
                                    class="btn btn-tertiary"
                                    :disabled="reminderFormProcessing"
                                    @click="clearReminderOverride"
                                >
                                    Remove override
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save Button -->
                <div class="settings-actions">
                    <button
                        class="btn btn-primary"
                        :disabled="settingsFormProcessing"
                        @click="saveSettings"
                    >
                        {{ settingsFormProcessing ? 'Saving...' : 'Save Settings' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Add Contact Modal -->
        <Modal :show="showAddContactModal" @close="showAddContactModal = false" title="Add Contact" size="md">
            <form @submit.prevent="submitContactForm">
                <div class="form-group">
                    <label class="form-label">Name *</label>
                    <input
                        v-model="contactForm.name"
                        type="text"
                        class="form-input"
                        placeholder="John Doe"
                        required
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input
                        v-model="contactForm.email"
                        type="email"
                        class="form-input"
                        placeholder="john@example.com"
                        required
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">Role *</label>
                    <input
                        v-model="contactForm.role"
                        type="text"
                        class="form-input"
                        placeholder="CEO, Project Manager, etc."
                        required
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input
                        v-model="contactForm.phone"
                        type="tel"
                        class="form-input"
                        placeholder="+1 (555) 123-4567"
                    />
                </div>

                <FormCheckbox
                    v-model="contactForm.is_primary"
                    label="Primary Contact"
                />
            </form>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showAddContactModal = false"
                    :disabled="contactFormProcessing"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="btn btn-primary"
                    @click="submitContactForm"
                    :disabled="contactFormProcessing"
                >
                    {{ contactFormProcessing ? 'Adding...' : 'Add Contact' }}
                </button>
            </template>
        </Modal>

        <!-- New Project Modal -->
        <ProjectFormModal
            :show="showNewProjectModal"
            :clients="[client]"
            :client-id="client.id"
            @close="handleProjectModalClose"
        />

        <!-- Edit Contact Modal -->
        <Modal :show="showEditContactModal" @close="showEditContactModal = false" title="Edit Contact" size="md">
            <form @submit.prevent="updateContact">
                <div class="form-group">
                    <label class="form-label">Name *</label>
                    <input
                        v-model="editContactForm.name"
                        type="text"
                        class="form-input"
                        required
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input
                        v-model="editContactForm.email"
                        type="email"
                        class="form-input"
                        required
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">Role *</label>
                    <input
                        v-model="editContactForm.role"
                        type="text"
                        class="form-input"
                        required
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input
                        v-model="editContactForm.phone"
                        type="tel"
                        class="form-input"
                    />
                </div>

                <FormCheckbox
                    v-model="editContactForm.is_primary"
                    label="Primary Contact"
                />
            </form>

            <template #footer>
                <button type="button" class="btn btn-danger" @click="deleteContact">
                    Delete
                </button>
                <div style="flex: 1;"></div>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showEditContactModal = false"
                    :disabled="contactFormProcessing"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="btn btn-primary"
                    @click="updateContact"
                    :disabled="contactFormProcessing"
                >
                    {{ contactFormProcessing ? 'Saving...' : 'Save' }}
                </button>
            </template>
        </Modal>

        <!-- Email Modal -->
        <Modal :show="showEmailModal" @close="showEmailModal = false" :title="`Email ${emailingContact?.name || ''}`" size="lg">
            <form @submit.prevent="">
                <div class="form-group">
                    <label class="form-label">To</label>
                    <input
                        type="email"
                        class="form-input"
                        :value="emailingContact?.email"
                        disabled
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">Subject *</label>
                    <input
                        v-model="emailForm.subject"
                        type="text"
                        class="form-input"
                        placeholder="Project Update"
                        required
                    />
                </div>

                <div class="form-group">
                    <label class="form-label">Message *</label>
                    <textarea
                        v-model="emailForm.body"
                        class="form-textarea"
                        placeholder="Write your message..."
                        rows="8"
                        required
                    ></textarea>
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-secondary" @click="showEmailModal = false" :disabled="emailFormProcessing">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" @click="sendEmail" :disabled="emailFormProcessing || !emailForm.subject || !emailForm.body">
                    {{ emailFormProcessing ? 'Sending...' : 'Send Email' }}
                </button>
            </template>
        </Modal>

        <!-- Invite to Portal Modal -->
        <Modal :show="showInviteModal" @close="showInviteModal = false" title="Invite to Client Portal" size="md">
            <div v-if="invitingContact" class="invite-preview">
                <div class="invite-contact-info">
                    <div class="invite-avatar" :style="{ background: `hsl(${invitingContact.id * 60}, 50%, 50%)` }">
                        {{ getInitials(invitingContact.name) }}
                    </div>
                    <div>
                        <div class="invite-name">{{ invitingContact.name }}</div>
                        <div class="invite-email">{{ invitingContact.email }}</div>
                    </div>
                </div>

                <div class="invite-info">
                    <p>An email invitation will be sent to <strong>{{ invitingContact.email }}</strong> with a link to set up their portal account.</p>
                    <p class="invite-details">Once accepted, they'll be able to:</p>
                    <ul class="invite-features">
                        <li>View project progress and tasks</li>
                        <li>Access invoices and billing history</li>
                        <li>Communicate with your team</li>
                    </ul>
                </div>

                <div v-if="inviteError" class="mt-3 p-3 rounded-lg text-sm" style="background: rgba(239, 68, 68, 0.1); color: var(--color-status-red);">
                    {{ inviteError }}
                </div>
            </div>

            <template #footer>
                <button type="button" class="btn btn-secondary" @click="showInviteModal = false" :disabled="inviteFormProcessing">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" @click="sendInvitation" :disabled="inviteFormProcessing">
                    {{ inviteFormProcessing ? 'Sending...' : 'Send Invitation' }}
                </button>
            </template>
        </Modal>

        <!-- Add Note Modal -->
        <Modal :show="showAddNoteModal" @close="showAddNoteModal = false" title="Add Note" size="md">
            <form @submit.prevent="submitNoteForm">
                <div class="form-group">
                    <label class="form-label">Note *</label>
                    <textarea
                        v-model="noteForm.content"
                        class="form-textarea"
                        placeholder="Enter your internal note about this client..."
                        rows="6"
                        required
                    ></textarea>
                    <p class="form-hint">This note is internal and will not be visible to the client.</p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showAddNoteModal = false"
                    :disabled="noteFormProcessing"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="btn btn-primary"
                    @click="submitNoteForm"
                    :disabled="noteFormProcessing || !noteForm.content.trim()"
                >
                    {{ noteFormProcessing ? 'Saving...' : 'Add Note' }}
                </button>
            </template>
        </Modal>

        <!-- Generate Report Modal -->
        <Modal :show="showGenerateReportModal" @close="showGenerateReportModal = false" title="Generate Custom Report" size="md">
            <form @submit.prevent="generateReport">
                <div class="form-group">
                    <label class="form-label">Period Start *</label>
                    <input
                        v-model="generateForm.period_start"
                        type="date"
                        class="form-input"
                        required
                    />
                </div>
                <div class="form-group">
                    <label class="form-label">Period End *</label>
                    <input
                        v-model="generateForm.period_end"
                        type="date"
                        class="form-input"
                        required
                    />
                </div>
                <div class="form-group">
                    <FormSelect
                        v-model="generateForm.report_type"
                        label="Report Type"
                        :options="[
                            { value: 'weekly', label: 'Weekly' },
                            { value: 'monthly', label: 'Monthly' },
                            { value: 'quarterly', label: 'Quarterly' },
                        ]"
                    />
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showGenerateReportModal = false"
                    :disabled="generating"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="btn btn-primary"
                    @click="generateReport"
                    :disabled="generating || !generateForm.period_start || !generateForm.period_end"
                >
                    {{ generating ? 'Generating...' : 'Generate Report' }}
                </button>
            </template>
        </Modal>

        <!-- Send Report Modal -->
        <Modal :show="showSendReportModal" @close="showSendReportModal = false" :title="`Send ${selectedReport?.period_label || ''} Report`" size="md">
            <form @submit.prevent="sendReport">
                <div class="form-group">
                    <label class="form-label">Recipients *</label>
                    <div class="recipient-chips">
                        <span
                            v-for="(email, index) in sendForm.recipients"
                            :key="email"
                            class="recipient-chip"
                        >
                            {{ email }}
                            <button type="button" @click="sendForm.recipients.splice(index, 1)">&times;</button>
                        </span>
                    </div>
                    <FormSelect
                        v-if="reportsData.contacts.length > 0"
                        :model-value="null"
                        placeholder="Add recipient..."
                        :options="reportsData.contacts
                            .filter(c => !sendForm.recipients.includes(c.email))
                            .map(c => ({ value: c.email, label: `${c.name} (${c.email})` }))"
                        @update:model-value="(val: string | null) => val && sendForm.recipients.push(val)"
                    />
                    <p class="form-hint">Select contacts to receive this report</p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showSendReportModal = false"
                    :disabled="sending"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="btn btn-primary"
                    @click="sendReport"
                    :disabled="sending || sendForm.recipients.length === 0"
                >
                    {{ sending ? 'Sending...' : `Send to ${sendForm.recipients.length} recipient${sendForm.recipients.length !== 1 ? 's' : ''}` }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.page-container {
    padding: 24px 32px;
}

/* Client Header */
.client-header {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    margin-bottom: 24px;
}

.client-avatar {
    width: 72px;
    height: 72px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 600;
    color: white;
    flex-shrink: 0;
}

.client-info {
    flex: 1;
}

.client-name-row {
    display: flex;
    align-items: center;
    gap: 12px;
}

.client-name-row h1 {
    font-size: 28px;
    margin: 0;
}

.client-description {
    color: var(--color-text-secondary);
    margin: 8px 0;
    font-size: 14px;
}

.client-meta {
    display: flex;
    gap: 16px;
    margin-top: 8px;
}

.meta-link {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--color-text-secondary);
    text-decoration: none;
    font-size: 13px;
}

.meta-link:hover {
    color: var(--color-accent);
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--color-text-tertiary);
    font-size: 13px;
}

/* Header Actions */
.header-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 16px;
}

.header-actions .btn {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Health Score */
.health-score {
    text-align: center;
}

.health-ring {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    border: 4px solid var(--health-color, var(--color-status-green));
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-secondary);
}

.health-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--color-text-primary);
}

.health-label {
    font-size: 12px;
    font-weight: 600;
    margin-top: 8px;
    text-transform: uppercase;
}

/* Tabs */
.tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--color-border-primary);
    margin-bottom: 24px;
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

/* Two Column */
.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

/* Health Factors */
.health-factors {
    padding: 8px 16px;
}

.factor-item {
    padding: 12px 0;
    border-bottom: 1px solid var(--color-border-primary);
}

.factor-item:last-child {
    border-bottom: none;
}

.factor-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.factor-name {
    color: var(--color-text-secondary);
    font-size: 13px;
}

.factor-score {
    font-weight: 600;
    color: var(--color-text-primary);
}

.factor-bar {
    height: 4px;
    background: var(--color-bg-tertiary);
    border-radius: 2px;
    overflow: hidden;
}

.factor-fill {
    height: 100%;
    border-radius: 2px;
}

/* Activity */
.activity-list {
    display: flex;
    flex-direction: column;
}

.activity-item {
    display: flex;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-top: 6px;
    flex-shrink: 0;
}

.activity-task { background: var(--color-status-blue); }
.activity-meeting { background: var(--color-status-purple); }
.activity-payment { background: var(--color-status-green); }
.activity-note { background: var(--color-status-orange); }

.activity-desc {
    font-size: 13px;
    color: var(--color-text-primary);
}

.activity-time {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 2px;
}

/* Projects Grid */
.projects-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    padding: 16px;
}

.project-card-link {
    text-decoration: none;
}

.project-card-mini {
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    padding: 16px;
    transition: all 0.15s ease;
}

.project-card-mini:hover {
    background: var(--color-bg-hover);
}

.project-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.project-name {
    font-weight: 500;
    color: var(--color-text-primary);
}

.project-card-progress {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}

.progress-bar {
    flex: 1;
    height: 4px;
    background: var(--color-bg-secondary);
    border-radius: 2px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--color-status-green);
    border-radius: 2px;
}

.progress-text {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.project-card-footer {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--color-text-tertiary);
}

/* Projects Table */
.projects-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.project-filters {
    display: flex;
    gap: 8px;
}

.filter-btn {
    padding: 8px 16px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-primary);
    border-radius: 6px;
    color: var(--color-text-secondary);
    font-size: 13px;
    cursor: pointer;
}

.filter-btn.active {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.project-table {
    display: flex;
    flex-direction: column;
}

.table-header {
    display: grid;
    grid-template-columns: 2fr 100px 100px 120px 150px;
    gap: 16px;
    padding: 12px 16px;
    background: var(--color-bg-tertiary);
    font-size: 11px;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
}

.table-row {
    display: grid;
    grid-template-columns: 2fr 100px 100px 120px 150px;
    gap: 16px;
    padding: 16px;
    border-bottom: 1px solid var(--color-border-primary);
    text-decoration: none;
    color: inherit;
    align-items: center;
}

.table-row:hover {
    background: var(--color-bg-tertiary);
}

.col-progress {
    display: flex;
    align-items: center;
    gap: 8px;
}

.progress-pct {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

/* Contacts */
.contacts-header {
    display: flex;
    justify-content: flex-end;
}

.contacts-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

.contact-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-primary);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
}

.contact-header {
    position: relative;
    margin-bottom: 12px;
}

.contact-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 600;
    color: white;
    margin: 0 auto;
}

.primary-badge {
    position: absolute;
    top: 0;
    right: 0;
    background: var(--color-status-green);
    color: white;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: 600;
}

.contact-name {
    font-weight: 600;
    color: var(--color-text-primary);
    font-size: 16px;
}

.contact-role {
    color: var(--color-text-secondary);
    font-size: 13px;
    margin-top: 2px;
}

.contact-details {
    margin-top: 12px;
}

.contact-email {
    color: var(--color-accent);
    text-decoration: none;
    font-size: 13px;
}

.contact-email:hover {
    text-decoration: underline;
}

.contact-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
    justify-content: center;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

.btn-ghost {
    background: transparent;
    border: 1px solid var(--color-border-primary);
    color: var(--color-text-secondary);
}

.btn-danger {
    background: var(--color-status-red);
    border: 1px solid var(--color-status-red);
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.btn-accent {
    background: var(--color-accent);
    border: 1px solid var(--color-accent);
    color: white;
}

.btn-accent:hover {
    opacity: 0.9;
}

/* Invite Modal */
.invite-preview {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.invite-contact-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
}

.invite-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 600;
    color: white;
}

.invite-name {
    font-weight: 600;
    color: var(--color-text-primary);
}

.invite-email {
    font-size: 13px;
    color: var(--color-text-secondary);
}

.invite-info p {
    color: var(--color-text-secondary);
    font-size: 14px;
    margin: 0 0 12px;
}

.invite-details {
    margin-top: 16px !important;
}

.invite-features {
    margin: 8px 0 0 20px;
    padding: 0;
    list-style: disc;
    color: var(--color-text-secondary);
    font-size: 13px;
}

.invite-features li {
    margin: 4px 0;
}

/* Portal Access Tab */
.access-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

.access-list {
    display: flex;
    flex-direction: column;
}

.access-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.access-item:last-child {
    border-bottom: none;
}

.access-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    color: white;
    flex-shrink: 0;
}

.access-info {
    flex: 1;
    min-width: 0;
}

.access-name {
    font-weight: 500;
    color: var(--color-text-primary);
}

.access-email,
.access-meta {
    font-size: 13px;
    color: var(--color-text-secondary);
}

.access-actions {
    display: flex;
    gap: 8px;
}

/* Notes */
.notes-list {
    display: flex;
    flex-direction: column;
}

.note-item {
    padding: 16px;
    border-bottom: 1px solid var(--color-border-primary);
}

.note-item:last-child {
    border-bottom: none;
}

.note-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.note-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-xs {
    padding: 4px 6px;
    font-size: 11px;
}

.btn-xs svg {
    opacity: 0.6;
}

.btn-xs:hover svg {
    opacity: 1;
}

.note-author {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    color: var(--color-text-primary);
}

.avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    color: white;
}

.avatar-sm {
    width: 24px;
    height: 24px;
    font-size: 9px;
}

.note-date {
    font-size: 12px;
    color: var(--color-text-tertiary);
}

.note-content {
    color: var(--color-text-secondary);
    font-size: 14px;
    line-height: 1.6;
}

.empty-state {
    padding: 32px;
    text-align: center;
    color: var(--color-text-tertiary);
}

/* Forms */
.form-group {
    margin-bottom: 20px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-primary);
    margin-bottom: 8px;
}

.form-input,
.form-textarea,
.form-select {
    width: 100%;
    padding: 10px 12px;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-primary);
    border-radius: 8px;
    color: var(--color-text-primary);
    font-size: 14px;
    transition: all 0.15s ease;
}

.form-input:focus,
.form-textarea:focus,
.form-select:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.form-textarea {
    resize: vertical;
    font-family: inherit;
}

.form-hint {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 8px;
}

.form-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.form-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--color-accent);
}

.form-checkbox span {
    font-size: 14px;
    color: var(--color-text-primary);
}

/* Settings */
.settings-form {
    padding: 16px;
}

.settings-tab {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.settings-actions {
    display: flex;
    justify-content: flex-end;
    padding: 8px 0;
}

.recurring-settings {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border-primary);
}

/* Input with prefix/suffix */
.input-with-prefix,
.input-with-suffix {
    display: flex;
    align-items: center;
}

.input-with-prefix .form-input {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    flex: 1;
}

.input-with-suffix .form-input {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
    flex: 1;
}

.input-prefix,
.input-suffix {
    padding: 10px 12px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-primary);
    color: var(--color-text-tertiary);
    font-size: 14px;
    white-space: nowrap;
}

.input-prefix {
    border-right: none;
    border-radius: 8px 0 0 8px;
}

.input-suffix {
    border-left: none;
    border-radius: 0 8px 8px 0;
}

/* Reports Tab */
.loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    color: var(--color-text-secondary);
}

.loading-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid var(--color-border-primary);
    border-top-color: var(--color-accent-primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 16px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.badge-active {
    background: var(--color-status-green);
    color: white;
}

.badge-inactive {
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.report-settings-details {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--color-border-primary);
}

.recipient-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.recipient-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: var(--color-bg-tertiary);
    border-radius: 16px;
    font-size: 13px;
    color: var(--color-text-primary);
}

.recipient-chip button {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--color-text-tertiary);
    font-size: 16px;
    line-height: 1;
    padding: 0;
}

.recipient-chip button:hover {
    color: var(--color-status-red);
}

.add-recipient-select {
    min-width: 200px;
}

.report-content-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.header-actions-inline {
    display: flex;
    gap: 8px;
}

.reports-list {
    padding: 0;
}

.report-table {
    display: table;
    width: 100%;
}

.table-header {
    display: table-row;
    background: var(--color-bg-tertiary);
}

.table-header > div {
    display: table-cell;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    border-bottom: 1px solid var(--color-border-primary);
}

.table-row {
    display: table-row;
}

.table-row:hover {
    background: var(--color-bg-secondary);
}

.table-row > div {
    display: table-cell;
    padding: 16px;
    vertical-align: middle;
    border-bottom: 1px solid var(--color-border-primary);
}

.col-period {
    width: 200px;
}

.col-metrics {
    width: 180px;
}

.col-status {
    width: 100px;
}

.col-sent {
    width: 180px;
}

.col-actions {
    width: 160px;
    text-align: right;
}

.period-label {
    display: block;
    font-weight: 500;
    color: var(--color-text-primary);
}

.period-dates {
    display: block;
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 2px;
}

.metric-badge {
    display: inline-flex;
    padding: 3px 8px;
    background: var(--color-bg-tertiary);
    border-radius: 12px;
    font-size: 12px;
    color: var(--color-text-secondary);
    margin-right: 4px;
}

.status-badge {
    display: inline-flex;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    color: white;
    text-transform: capitalize;
}

.sent-date {
    color: var(--color-text-secondary);
    font-size: 13px;
}

.opens-count {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-left: 4px;
}

.not-sent {
    color: var(--color-text-tertiary);
    font-size: 13px;
}

.btn-xs {
    padding: 4px 8px;
    font-size: 12px;
}

.col-actions .btn {
    margin-left: 4px;
}

.col-actions .btn:first-child {
    margin-left: 0;
}

.reminder-schedule-table {
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 0.375rem;
    margin-top: 0.5rem;
    margin-bottom: 0.75rem;
}
.reminder-schedule-table.disabled { opacity: 0.5; pointer-events: none; }
.reminder-schedule-row {
    display: grid;
    grid-template-columns: 32px 100px 1fr 32px;
    gap: 0.75rem;
    align-items: center;
    padding: 0.5rem 0.75rem;
}
.reminder-schedule-row + .reminder-schedule-row {
    border-top: 1px solid var(--border, #e5e7eb);
}
.reminder-offset-input { width: 100%; }
.reminder-row-label { color: var(--text-secondary, #6b7280); font-size: 0.875rem; }
.empty-state-row { padding: 1rem; text-align: center; color: var(--text-secondary, #6b7280); font-size: 0.875rem; }
.btn-icon {
    background: none;
    border: none;
    color: var(--text-secondary, #6b7280);
    cursor: pointer;
    padding: 0.25rem;
}
.btn-icon:hover { color: var(--danger, #dc2626); }
.reminder-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.75rem;
}
.link-action { color: var(--primary, #2563eb); text-decoration: underline; }
</style>
