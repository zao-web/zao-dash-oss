<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormToggle from '@/Components/FormToggle.vue'
import { ref, reactive, computed, watch } from 'vue'
import { router, Link } from '@inertiajs/vue3'

interface RfpSource {
    id: number
    name: string
    slug: string
    type: string
    config: Record<string, any> | null
    filters: Record<string, any> | null
    is_active: boolean
    check_frequency_minutes: number
    last_checked_at: string | null
    total_opportunities_found: number
    created_at: string
}

const props = defineProps<{
    sources: RfpSource[]
}>()

const showCreateModal = ref(false)
const showDeleteModal = ref(false)
const editingSource = ref<RfpSource | null>(null)
const sourceToDelete = ref<RfpSource | null>(null)
const isSaving = ref(false)
const isDeleting = ref(false)

const sourceForm = reactive({
    name: '',
    type: 'email_sender',
    config_sender_emails: '',
    config_api_key_ref: '',
    config_naics_codes: '',
    config_api_url: '',
    config_board_url: '',
    config_target_urls: '',
    config_feed_url: '',
    config_subject_keywords: '',
    check_frequency_minutes: 60,
    is_active: true,
})

const typeOptions = [
    { value: 'email_sender', label: 'Email Sender' },
    { value: 'government_api', label: 'Government API' },
    { value: 'rfp_board', label: 'RFP Board' },
    { value: 'rss_feed', label: 'RSS Feed' },
    { value: 'web_scrape', label: 'Web Scrape' },
]

const typeBadgeStyles: Record<string, { bg: string; text: string }> = {
    email_sender: { bg: 'rgba(59, 130, 246, 0.1)', text: 'var(--color-status-blue)' },
    government_api: { bg: 'rgba(34, 197, 94, 0.1)', text: 'var(--color-status-green)' },
    rfp_board: { bg: 'rgba(139, 92, 246, 0.1)', text: 'var(--color-accent)' },
    rss_feed: { bg: 'rgba(234, 179, 8, 0.1)', text: '#eab308' },
    web_scrape: { bg: 'rgba(249, 115, 22, 0.1)', text: 'var(--color-status-orange)' },
}

const typeLabel = (type: string) => {
    return typeOptions.find(t => t.value === type)?.label || type
}

const modalTitle = computed(() => editingSource.value ? 'Edit Source' : 'Add Source')

const resetForm = () => {
    sourceForm.name = ''
    sourceForm.type = 'email_sender'
    sourceForm.config_sender_emails = ''
    sourceForm.config_api_key_ref = ''
    sourceForm.config_naics_codes = ''
    sourceForm.config_api_url = ''
    sourceForm.config_board_url = ''
    sourceForm.config_target_urls = ''
    sourceForm.config_feed_url = ''
    sourceForm.config_subject_keywords = ''
    sourceForm.check_frequency_minutes = 60
    sourceForm.is_active = true
}

const openNewSource = () => {
    editingSource.value = null
    resetForm()
    showCreateModal.value = true
}

const openEditSource = (source: RfpSource) => {
    editingSource.value = source
    sourceForm.name = source.name
    sourceForm.type = source.type
    sourceForm.check_frequency_minutes = source.check_frequency_minutes
    sourceForm.is_active = source.is_active

    // Populate config fields based on type
    const config = source.config || {}
    sourceForm.config_sender_emails = (config.sender_emails || []).join(', ')
    sourceForm.config_subject_keywords = (config.subject_keywords || []).join(', ')
    sourceForm.config_api_key_ref = config.api_key_ref || ''
    sourceForm.config_naics_codes = (config.naics_codes || []).join(', ')
    sourceForm.config_api_url = config.api_url || ''
    sourceForm.config_board_url = config.board_url || ''
    sourceForm.config_target_urls = (config.target_urls || []).join('\n')
    sourceForm.config_feed_url = config.feed_url || ''

    showCreateModal.value = true
}

const closeCreateModal = () => {
    showCreateModal.value = false
    editingSource.value = null
    resetForm()
}

const buildConfigPayload = (): Record<string, any> => {
    switch (sourceForm.type) {
        case 'email_sender':
            return {
                sender_emails: sourceForm.config_sender_emails.split(',').map(e => e.trim()).filter(Boolean),
                subject_keywords: sourceForm.config_subject_keywords.split(',').map(k => k.trim()).filter(Boolean),
            }
        case 'government_api':
            return {
                api_url: sourceForm.config_api_url,
                api_key_ref: sourceForm.config_api_key_ref,
                naics_codes: sourceForm.config_naics_codes.split(',').map(c => c.trim()).filter(Boolean),
            }
        case 'rfp_board':
            return {
                board_url: sourceForm.config_board_url,
            }
        case 'rss_feed':
            return {
                feed_url: sourceForm.config_feed_url,
            }
        case 'web_scrape':
            return {
                target_urls: sourceForm.config_target_urls.split('\n').map(u => u.trim()).filter(Boolean),
            }
        default:
            return {}
    }
}

const saveSource = () => {
    isSaving.value = true
    const data = {
        name: sourceForm.name,
        type: sourceForm.type,
        config: buildConfigPayload(),
        check_frequency_minutes: sourceForm.check_frequency_minutes,
        is_active: sourceForm.is_active,
    }

    if (editingSource.value) {
        router.put(`/rfp/sources/${editingSource.value.id}`, data, {
            onSuccess: () => closeCreateModal(),
            onFinish: () => isSaving.value = false,
        })
    } else {
        router.post('/rfp/sources', data, {
            onSuccess: () => closeCreateModal(),
            onFinish: () => isSaving.value = false,
        })
    }
}

const openDeleteModal = (source: RfpSource) => {
    sourceToDelete.value = source
    showDeleteModal.value = true
}

const confirmDelete = () => {
    if (!sourceToDelete.value) return
    isDeleting.value = true
    router.delete(`/rfp/sources/${sourceToDelete.value.id}`, {
        onSuccess: () => {
            showDeleteModal.value = false
            sourceToDelete.value = null
        },
        onFinish: () => isDeleting.value = false,
    })
}

const toggleActive = (source: RfpSource) => {
    router.put(`/rfp/sources/${source.id}`, { is_active: !source.is_active }, {
        preserveScroll: true,
    })
}

const getConfigDisplay = (source: RfpSource): { label: string; value: string }[] => {
    const config = source.config || {}
    const items: { label: string; value: string }[] = []

    switch (source.type) {
        case 'email_sender':
            if (config.sender_emails?.length) {
                items.push({ label: 'Sender Emails', value: config.sender_emails.join(', ') })
            }
            if (config.subject_keywords?.length) {
                items.push({ label: 'Keywords', value: config.subject_keywords.join(', ') })
            }
            break
        case 'government_api':
            if (config.api_url) {
                items.push({ label: 'API URL', value: config.api_url })
            }
            if (config.naics_codes?.length) {
                items.push({ label: 'NAICS Codes', value: config.naics_codes.join(', ') })
            }
            break
        case 'rfp_board':
            if (config.board_url) {
                items.push({ label: 'Board URL', value: config.board_url })
            }
            break
        case 'rss_feed':
            if (config.feed_url) {
                items.push({ label: 'Feed URL', value: config.feed_url })
            }
            break
        case 'web_scrape':
            if (config.target_urls?.length) {
                items.push({ label: 'Target URLs', value: config.target_urls.join(', ') })
            }
            break
    }

    return items
}
</script>

<template>
    <AppLayout title="RFP Sources">
        <!-- Sub-nav -->
        <div class="sub-nav-bar">
            <div class="sub-nav">
                <Link href="/rfp" class="sub-nav-link">Pipeline</Link>
                <Link href="/rfp/sources" class="sub-nav-link sub-nav-active">Sources</Link>
                <Link href="/rfp/learning" class="sub-nav-link">Learning</Link>
            </div>
        </div>

        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">RFP Sources</h1>
                <p class="page-subtitle">Configure where opportunities are discovered from</p>
            </div>
            <button class="btn btn-primary" @click="openNewSource">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                Add Source
            </button>
        </div>

        <!-- Sources Grid -->
        <div v-if="sources.length" class="sources-grid">
            <div v-for="source in sources" :key="source.id" class="source-card">
                <div class="source-header">
                    <div class="source-title-row">
                        <h3 class="source-name">{{ source.name }}</h3>
                        <div class="source-actions">
                            <button class="action-btn" @click="openEditSource(source)" title="Edit">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <button class="action-btn action-btn-danger" @click="openDeleteModal(source)" title="Delete">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="source-badges">
                        <span
                            class="type-badge"
                            :style="{ background: typeBadgeStyles[source.type]?.bg, color: typeBadgeStyles[source.type]?.text }"
                        >
                            {{ typeLabel(source.type) }}
                        </span>
                        <button
                            class="status-toggle"
                            :class="source.is_active ? 'status-active' : 'status-inactive'"
                            @click="toggleActive(source)"
                        >
                            <span class="status-dot-sm"></span>
                            {{ source.is_active ? 'Active' : 'Inactive' }}
                        </button>
                    </div>
                </div>

                <!-- Config details -->
                <div class="source-config">
                    <div v-for="item in getConfigDisplay(source)" :key="item.label" class="config-item">
                        <span class="config-label">{{ item.label }}</span>
                        <span class="config-value">{{ item.value }}</span>
                    </div>
                </div>

                <!-- Filters -->
                <div v-if="source.filters && Object.keys(source.filters).length" class="source-filters">
                    <span class="filters-label">Filters:</span>
                    <span v-if="source.filters.industries" class="filter-tag" v-for="industry in source.filters.industries" :key="industry">
                        {{ industry }}
                    </span>
                    <span v-if="source.filters.budget_min" class="filter-tag">
                        Min ${{ Number(source.filters.budget_min).toLocaleString() }}
                    </span>
                </div>

                <!-- Footer stats -->
                <div class="source-footer">
                    <div class="source-stat">
                        <span class="stat-label">Frequency</span>
                        <span class="stat-value">{{ source.check_frequency_minutes }}min</span>
                    </div>
                    <div class="source-stat">
                        <span class="stat-label">Found</span>
                        <span class="stat-value">{{ source.total_opportunities_found ?? 0 }}</span>
                    </div>
                    <div class="source-stat">
                        <span class="stat-label">Last Checked</span>
                        <span class="stat-value">{{ source.last_checked_at || 'Never' }}</span>
                    </div>
                    <div class="source-stat">
                        <span class="stat-label">Added</span>
                        <span class="stat-value">{{ source.created_at }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-else class="empty-state">
            <div class="empty-icon">
                <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
            </div>
            <h3>No sources configured</h3>
            <p>Add your first RFP source to start discovering opportunities automatically.</p>
            <button class="btn btn-primary mt-4" @click="openNewSource">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                Add Source
            </button>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showCreateModal" size="lg" @close="closeCreateModal">
            <template #header>
                <h2 class="modal-title">{{ modalTitle }}</h2>
            </template>

            <form @submit.prevent="saveSource">
                <div class="form-section">
                    <h3 class="form-section-title">Source Details</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="sourceForm.name"
                            label="Name"
                            placeholder="e.g., Rob Williams (Folyo)"
                            required
                        />
                        <FormSelect
                            v-model="sourceForm.type"
                            label="Type"
                            :options="typeOptions"
                            :disabled="!!editingSource"
                        />
                    </div>
                </div>

                <!-- Dynamic config fields based on type -->
                <div class="form-section">
                    <h3 class="form-section-title">Configuration</h3>

                    <!-- Email Sender -->
                    <template v-if="sourceForm.type === 'email_sender'">
                        <FormInput
                            v-model="sourceForm.config_sender_emails"
                            label="Sender Emails"
                            placeholder="rob@folyo.me, alerts@rfpsite.com"
                            hint="Comma-separated email addresses"
                        />
                        <FormInput
                            v-model="sourceForm.config_subject_keywords"
                            label="Subject Keywords"
                            placeholder="RFP, opportunity, agencies"
                            hint="Comma-separated keywords to match in email subjects"
                        />
                    </template>

                    <!-- Government API -->
                    <template v-if="sourceForm.type === 'government_api'">
                        <FormInput
                            v-model="sourceForm.config_api_url"
                            label="API URL"
                            placeholder="https://api.sam.gov/opportunities/v2/search"
                        />
                        <div class="form-grid">
                            <FormInput
                                v-model="sourceForm.config_api_key_ref"
                                label="API Key Reference"
                                placeholder="SAM_GOV_API_KEY"
                                hint="Environment variable name"
                            />
                            <FormInput
                                v-model="sourceForm.config_naics_codes"
                                label="NAICS Codes"
                                placeholder="541511, 541512, 541519"
                                hint="Comma-separated NAICS codes"
                            />
                        </div>
                    </template>

                    <!-- RFP Board -->
                    <template v-if="sourceForm.type === 'rfp_board'">
                        <FormInput
                            v-model="sourceForm.config_board_url"
                            label="Board URL"
                            placeholder="https://example.com/rfp-listings"
                        />
                    </template>

                    <!-- RSS Feed -->
                    <template v-if="sourceForm.type === 'rss_feed'">
                        <FormInput
                            v-model="sourceForm.config_feed_url"
                            label="Feed URL"
                            placeholder="https://example.com/rfp/feed.xml"
                        />
                    </template>

                    <!-- Web Scrape -->
                    <template v-if="sourceForm.type === 'web_scrape'">
                        <FormInput
                            v-model="sourceForm.config_target_urls"
                            label="Target URLs"
                            placeholder="https://example.com/rfps&#10;https://another.com/opportunities"
                            hint="One URL per line"
                        />
                    </template>
                </div>

                <!-- Settings -->
                <div class="form-section">
                    <h3 class="form-section-title">Settings</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="sourceForm.check_frequency_minutes"
                            label="Check Frequency (minutes)"
                            type="number"
                            :min="15"
                            :max="1440"
                        />
                        <div></div>
                    </div>
                    <FormToggle
                        v-model="sourceForm.is_active"
                        label="Active"
                        description="When active, this source will be checked on schedule"
                    />
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeCreateModal">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSaving || !sourceForm.name"
                        @click="saveSource"
                    >
                        {{ isSaving ? 'Saving...' : (editingSource ? 'Update Source' : 'Create Source') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" size="sm" @close="showDeleteModal = false">
            <template #header>
                <h2 class="modal-title">Delete RFP Source</h2>
            </template>

            <div class="delete-warning">
                <div class="delete-icon">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p>Delete <strong>{{ sourceToDelete?.name }}</strong>?</p>
                <p class="text-caption mt-2">This will not delete any discovered opportunities from this source.</p>
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
                        {{ isDeleting ? 'Deleting...' : 'Delete Source' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
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

/* Page header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
    margin-bottom: 4px;
}

.page-subtitle {
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
}

/* Sources Grid */
.sources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 16px;
}

.source-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    padding: 20px;
    transition: border-color 0.15s ease;
}

.source-card:hover {
    border-color: var(--color-border-strong);
}

.source-header {
    margin-bottom: 16px;
}

.source-title-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
}

.source-name {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.source-actions {
    display: flex;
    gap: 4px;
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
    opacity: 0;
}

.source-card:hover .action-btn {
    opacity: 1;
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

.source-badges {
    display: flex;
    align-items: center;
    gap: 8px;
}

.type-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 4px;
}

.status-toggle {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 500;
    padding: 3px 8px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease;
}

.status-active {
    background: rgba(34, 197, 94, 0.1);
    color: var(--color-status-green);
}

.status-active:hover {
    background: rgba(34, 197, 94, 0.2);
}

.status-inactive {
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
}

.status-inactive:hover {
    background: var(--color-bg-elevated);
}

.status-dot-sm {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

/* Config */
.source-config {
    padding: 12px 0;
    border-top: 1px solid var(--color-border-subtle);
    border-bottom: 1px solid var(--color-border-subtle);
}

.config-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 4px 0;
    gap: 12px;
}

.config-label {
    font-size: 12px;
    color: var(--color-text-tertiary);
    flex-shrink: 0;
}

.config-value {
    font-size: 12px;
    color: var(--color-text-secondary);
    text-align: right;
    word-break: break-all;
}

/* Filters */
.source-filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    padding: 10px 0;
}

.filters-label {
    font-size: 11px;
    color: var(--color-text-tertiary);
    font-weight: 500;
}

.filter-tag {
    font-size: 10px;
    font-weight: 500;
    padding: 2px 6px;
    border-radius: 4px;
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
    text-transform: capitalize;
}

/* Footer stats */
.source-footer {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    padding-top: 12px;
}

.source-stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.stat-label {
    font-size: 10px;
    font-weight: 500;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.stat-value {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-primary);
}

/* Empty state */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 20px;
    text-align: center;
}

.empty-icon {
    color: var(--color-text-tertiary);
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 6px;
}

.empty-state p {
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
    max-width: 360px;
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

/* Delete modal */
.delete-warning {
    text-align: center;
    padding: 1rem 0;
}

.delete-icon {
    color: var(--color-status-red);
    margin-bottom: 1rem;
    display: flex;
    justify-content: center;
}

.delete-warning p {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

/* Button variants */
.btn-danger {
    background: var(--color-status-red);
    color: white;
    border: none;
}

.btn-danger:hover:not(:disabled) {
    background: #dc2626;
}
</style>
