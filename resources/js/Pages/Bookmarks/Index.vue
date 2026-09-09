<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import FormSelect from '@/Components/FormSelect.vue'
import Modal from '@/Components/Modal.vue'
import { RefreshIcon, ExternalLinkIcon, CloseIcon } from '@/Components/Icons'
import { router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import axios from 'axios'

interface XCredential {
    id: number
    username: string
    name: string | null
    profile_image_url: string | null
    has_bookmark_scope: boolean
    sync_status: {
        last_sync: string | null
        can_sync: boolean
    } | null
}

interface Bookmark {
    id: number
    tweet_id: string
    author_username: string
    author_name: string | null
    text: string
    tweet_created_at: string | null
    urls: { url: string; title?: string }[] | null
    hashtags: { tag: string }[] | null
    like_count: number
    retweet_count: number
    status: string
    category: string | null
    relevance_score: number | null
    action_summary: string | null
    ai_analysis: Record<string, any> | null
    pr_url: string | null
    pr_branch: string | null
    created_at: string
    x_credential: {
        id: number
        username: string
        name: string | null
        profile_image_url: string | null
    }
}

interface PaginatedBookmarks {
    data: Bookmark[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
}

interface Stats {
    total: number
    pending: number
    actionable: number
    with_pr: number
    unenriched: number
}

const props = defineProps<{
    bookmarks: PaginatedBookmarks
    credentials: XCredential[]
    stats: Stats
    filters: {
        status: string | null
        category: string | null
        min_relevance: string | null
    }
    categories: Record<string, string>
    statuses: Record<string, string>
}>()

// State
const syncing = ref<Record<number, boolean>>({})
const analyzing = ref<Record<number, boolean>>({})
const enriching = ref<Record<number, boolean>>({})
const creatingPr = ref<Record<number, boolean>>({})
const dismissing = ref<Record<number, boolean>>({})
const showDetailModal = ref(false)
const selectedBookmark = ref<Bookmark | null>(null)

// Import Modal State
const showImportModal = ref(false)
const importCredentialId = ref<number | null>(null)
const importJsonText = ref('')
const importParsedBookmarks = ref<any[]>([])
const importPreviewLoading = ref(false)
const importLoading = ref(false)
const importPreviewResult = ref<{
    total: number
    new_count: number
    duplicate_count: number
    invalid_count: number
    bookmarks: { tweet_id: string; author_username: string | null; text: string; is_duplicate: boolean }[]
} | null>(null)
const skipDuplicates = ref(true)
const importError = ref<string | null>(null)
const importSuccess = ref<string | null>(null)

// Computed
const hasAnyCredentials = computed(() => props.credentials.length > 0)
const credentialsWithScope = computed(() => props.credentials.filter(c => c.has_bookmark_scope))
const credentialsNeedingReauth = computed(() => props.credentials.filter(c => !c.has_bookmark_scope))

// Methods
const syncBookmarks = async (credentialId: number, force = false) => {
    syncing.value[credentialId] = true
    try {
        await axios.post(`/bookmarks/sync/${credentialId}`, { force })
        router.reload({ only: ['bookmarks', 'stats'] })
    } catch (e: any) {
        alert(e.response?.data?.error || 'Sync failed')
    } finally {
        syncing.value[credentialId] = false
    }
}

const analyzeBookmarks = async (credentialId: number) => {
    analyzing.value[credentialId] = true
    try {
        await axios.post(`/bookmarks/analyze/${credentialId}`)
        router.reload({ only: ['bookmarks', 'stats'] })
    } catch (e: any) {
        alert(e.response?.data?.error || 'Analysis failed')
    } finally {
        analyzing.value[credentialId] = false
    }
}

const enrichBookmarks = async (credentialId: number) => {
    enriching.value[credentialId] = true
    try {
        const response = await axios.post(`/bookmarks/enrich/${credentialId}`)
        alert(response.data.message)
        router.reload({ only: ['bookmarks', 'stats'] })
    } catch (e: any) {
        alert(e.response?.data?.error || 'Enrichment failed')
    } finally {
        enriching.value[credentialId] = false
    }
}

const createPr = async (bookmarkId: number) => {
    creatingPr.value[bookmarkId] = true
    try {
        await axios.post(`/bookmarks/${bookmarkId}/create-pr`)
        router.reload({ only: ['bookmarks', 'stats'] })
    } catch (e: any) {
        alert(e.response?.data?.error || 'PR creation failed')
    } finally {
        creatingPr.value[bookmarkId] = false
    }
}

const dismissBookmark = async (bookmarkId: number) => {
    dismissing.value[bookmarkId] = true
    try {
        await axios.post(`/bookmarks/${bookmarkId}/dismiss`)
        router.reload({ only: ['bookmarks', 'stats'] })
    } catch (e: any) {
        alert(e.response?.data?.error || 'Dismiss failed')
    } finally {
        dismissing.value[bookmarkId] = false
    }
}

const openDetail = (bookmark: Bookmark) => {
    selectedBookmark.value = bookmark
    showDetailModal.value = true
}

const getTweetUrl = (bookmark: Bookmark) => {
    return `https://x.com/${bookmark.author_username}/status/${bookmark.tweet_id}`
}

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        pending: 'badge-gray',
        analyzed: 'badge-blue',
        actionable: 'badge-green',
        pr_created: 'badge-purple',
        dismissed: 'badge-red',
    }
    return badges[status] || 'badge-gray'
}

const getCategoryBadge = (category: string | null) => {
    if (!category) return 'badge-gray'
    const badges: Record<string, string> = {
        ai_model: 'badge-purple',
        prompt_technique: 'badge-yellow',
        feature_idea: 'badge-green',
        integration: 'badge-blue',
        bug_fix: 'badge-red',
        tool: 'badge-yellow',
        research: 'badge-blue',
        other: 'badge-gray',
    }
    return badges[category] || 'badge-gray'
}

const formatDate = (date: string | null) => {
    if (!date) return ''
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
}

const applyFilter = (key: string, value: string | null) => {
    router.get('/bookmarks', {
        ...props.filters,
        [key]: value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

const reconnectWithBookmarks = () => {
    window.location.href = '/auth/x?include_bookmarks=1'
}

// Import Methods
const openImportModal = () => {
    importJsonText.value = ''
    importParsedBookmarks.value = []
    importPreviewResult.value = null
    importError.value = null
    importSuccess.value = null
    importCredentialId.value = credentialsWithScope.value.length === 1 ? credentialsWithScope.value[0].id : null
    showImportModal.value = true
}

const handleFileUpload = (event: Event) => {
    const target = event.target as HTMLInputElement
    const file = target.files?.[0]
    if (!file) return

    const reader = new FileReader()
    reader.onload = (e) => {
        importJsonText.value = e.target?.result as string
    }
    reader.readAsText(file)
}

const parseAndPreview = async () => {
    importError.value = null
    importPreviewResult.value = null
    
    if (!importCredentialId.value) {
        importError.value = 'Please select an X account'
        return
    }

    let parsed: any[]
    try {
        parsed = JSON.parse(importJsonText.value)
        if (!Array.isArray(parsed)) {
            importError.value = 'JSON must be an array of bookmarks'
            return
        }
    } catch (e) {
        importError.value = 'Invalid JSON format'
        return
    }

    importParsedBookmarks.value = parsed
    importPreviewLoading.value = true

    try {
        const response = await axios.post('/bookmarks/import/preview', {
            credential_id: importCredentialId.value,
            bookmarks: parsed,
        })
        importPreviewResult.value = response.data
    } catch (e: any) {
        importError.value = e.response?.data?.error || e.response?.data?.message || 'Preview failed'
    } finally {
        importPreviewLoading.value = false
    }
}

const executeImport = async () => {
    if (!importCredentialId.value || !importParsedBookmarks.value.length) return

    importLoading.value = true
    importError.value = null
    importSuccess.value = null

    try {
        const response = await axios.post('/bookmarks/import', {
            credential_id: importCredentialId.value,
            bookmarks: importParsedBookmarks.value,
            skip_duplicates: skipDuplicates.value,
        })
        
        const { created, updated, skipped, errors } = response.data
        importSuccess.value = `Import complete: ${created} created, ${updated} updated, ${skipped} skipped${errors > 0 ? `, ${errors} errors` : ''}`
        
        setTimeout(() => {
            showImportModal.value = false
            router.reload()
        }, 2000)
    } catch (e: any) {
        importError.value = e.response?.data?.error || e.response?.data?.message || 'Import failed'
    } finally {
        importLoading.value = false
    }
}
</script>

<template>
    <AppLayout title="X Bookmarks">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-heading text-lg">X Bookmarks</h1>
                <p class="text-caption">AI-analyzed bookmarks from X, ready to become pull requests</p>
            </div>
            <button
                v-if="credentialsWithScope.length > 0"
                class="btn btn-secondary"
                @click="openImportModal"
            >
                Import JSON
            </button>
        </div>

        <!-- No credentials at all -->
        <div v-if="!hasAnyCredentials" class="card p-6 mb-6">
            <h3 class="text-heading mb-2">Connect X Account</h3>
            <p class="text-body mb-4">
                Connect your X account with bookmark permissions to sync and analyze your bookmarks.
            </p>
            <button class="btn btn-primary" @click="reconnectWithBookmarks">
                Connect X Account
            </button>
        </div>

        <!-- Credentials needing re-auth -->
        <div v-else-if="credentialsNeedingReauth.length > 0 && credentialsWithScope.length === 0" class="card p-6 mb-6 border-l-4" style="border-left-color: var(--color-status-yellow);">
            <h3 class="text-heading mb-2">Bookmark Access Required</h3>
            <p class="text-body mb-4">
                Your X account needs to be reconnected to grant bookmark permissions.
                <span v-for="cred in credentialsNeedingReauth" :key="cred.id" class="text-caption">
                    (@{{ cred.username }})
                </span>
            </p>
            <button class="btn btn-primary" @click="reconnectWithBookmarks">
                Reconnect with Bookmark Access
            </button>
        </div>

        <!-- Connected Accounts with scope -->
        <div v-if="credentialsWithScope.length > 0" class="card mb-6">
            <div class="px-4 py-3 border-b" style="border-color: var(--color-border-subtle);">
                <span class="text-heading">Connected Accounts</span>
            </div>
            <div class="divide-y" style="--tw-divide-opacity: 1; border-color: var(--color-border-subtle);">
                <div v-for="cred in credentialsWithScope" :key="cred.id" class="p-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <img
                            v-if="cred.profile_image_url"
                            :src="cred.profile_image_url"
                            class="w-10 h-10 rounded-full"
                            :alt="cred.username"
                        />
                        <div v-else class="w-10 h-10 rounded-full flex items-center justify-center" style="background: var(--color-bg-tertiary);">
                            <span class="text-caption">@</span>
                        </div>
                        <div>
                            <p class="text-heading">@{{ cred.username }}</p>
                            <p class="text-caption">
                                <template v-if="cred.sync_status?.last_sync">
                                    Last synced: {{ formatDate(cred.sync_status.last_sync) }}
                                </template>
                                <template v-else>
                                    Never synced
                                </template>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="stats.unenriched > 0"
                            class="btn btn-secondary btn-sm"
                            :disabled="enriching[cred.id]"
                            @click="enrichBookmarks(cred.id)"
                        >
                            {{ enriching[cred.id] ? 'Enriching...' : `Enrich (${stats.unenriched})` }}
                        </button>
                        <button
                            class="btn btn-secondary btn-sm"
                            :disabled="analyzing[cred.id]"
                            @click="analyzeBookmarks(cred.id)"
                        >
                            {{ analyzing[cred.id] ? 'Analyzing...' : 'Analyze Pending' }}
                        </button>
                        <button
                            class="btn btn-primary btn-sm"
                            :disabled="syncing[cred.id] || !cred.sync_status?.can_sync"
                            @click="syncBookmarks(cred.id)"
                        >
                            <RefreshIcon class="w-4 h-4 mr-1" :class="{ 'animate-spin': syncing[cred.id] }" />
                            <span v-if="syncing[cred.id]">Syncing...</span>
                            <span v-else-if="!cred.sync_status?.can_sync">Synced Today</span>
                            <span v-else>Sync Now</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="metric-card">
                <div class="metric-label">TOTAL</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PENDING ANALYSIS</div>
                <div class="metric-value" style="color: var(--color-status-yellow);">{{ stats.pending }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIONABLE</div>
                <div class="metric-value" style="color: var(--color-status-green);">{{ stats.actionable }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">PRs CREATED</div>
                <div class="metric-value" style="color: var(--color-accent);">{{ stats.with_pr }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card p-4 mb-6">
            <div class="flex flex-wrap gap-4">
                <div class="w-48">
                    <FormSelect
                        label="Status"
                        :model-value="filters.status || ''"
                        @update:model-value="applyFilter('status', $event || null)"
                    >
                        <option value="">All Statuses</option>
                        <option v-for="(label, value) in statuses" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </FormSelect>
                </div>
                <div class="w-48">
                    <FormSelect
                        label="Category"
                        :model-value="filters.category || ''"
                        @update:model-value="applyFilter('category', $event || null)"
                    >
                        <option value="">All Categories</option>
                        <option v-for="(label, value) in categories" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </FormSelect>
                </div>
                <div class="w-48">
                    <FormSelect
                        label="Min Relevance"
                        :model-value="filters.min_relevance || ''"
                        @update:model-value="applyFilter('min_relevance', $event || null)"
                    >
                        <option value="">Any Relevance</option>
                        <option value="50">50+</option>
                        <option value="70">70+</option>
                        <option value="90">90+</option>
                    </FormSelect>
                </div>
            </div>
        </div>

        <!-- Bookmarks List -->
        <div class="card overflow-hidden">
            <div v-if="bookmarks.data.length === 0" class="p-8 text-center">
                <p class="text-body">No bookmarks found.</p>
                <p class="text-caption mt-2" v-if="credentialsWithScope.length > 0">
                    Click "Sync Now" to fetch your X bookmarks.
                </p>
            </div>

            <div v-else>
                <div
                    v-for="bookmark in bookmarks.data"
                    :key="bookmark.id"
                    class="p-4 border-b cursor-pointer transition-colors hover:bg-[var(--color-bg-hover)]"
                    style="border-color: var(--color-border-subtle);"
                    @click="openDetail(bookmark)"
                >
                    <div class="flex items-start justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-2 flex-wrap">
                                <span class="text-heading">@{{ bookmark.author_username }}</span>
                                <span class="text-caption">&middot;</span>
                                <span class="text-caption">{{ formatDate(bookmark.tweet_created_at) }}</span>
                                <span :class="['badge', getStatusBadge(bookmark.status)]">
                                    {{ statuses[bookmark.status] || bookmark.status }}
                                </span>
                                <span
                                    v-if="bookmark.category"
                                    :class="['badge', getCategoryBadge(bookmark.category)]"
                                >
                                    {{ categories[bookmark.category] || bookmark.category }}
                                </span>
                            </div>

                            <p class="text-body line-clamp-2 mb-2">{{ bookmark.text }}</p>

                            <div
                                v-if="bookmark.action_summary"
                                class="text-sm px-2 py-1 rounded inline-block mb-2"
                                style="background: rgba(34, 197, 94, 0.1); color: var(--color-status-green);"
                            >
                                {{ bookmark.action_summary }}
                            </div>

                            <div class="flex items-center gap-4 text-caption">
                                <span v-if="bookmark.relevance_score !== null">
                                    Relevance: {{ bookmark.relevance_score }}%
                                </span>
                                <span>{{ bookmark.like_count }} likes</span>
                                <span>{{ bookmark.retweet_count }} RTs</span>
                            </div>
                        </div>

                        <div class="ml-4 flex items-center gap-2" @click.stop>
                            <a
                                :href="getTweetUrl(bookmark)"
                                target="_blank"
                                class="p-2 rounded transition-colors hover:bg-[var(--color-bg-hover)]"
                                title="View on X"
                            >
                                <ExternalLinkIcon class="w-4 h-4" />
                            </a>

                            <template v-if="bookmark.status === 'actionable'">
                                <button
                                    class="btn btn-primary btn-sm"
                                    :disabled="creatingPr[bookmark.id]"
                                    @click="createPr(bookmark.id)"
                                >
                                    {{ creatingPr[bookmark.id] ? 'Creating...' : 'Create PR' }}
                                </button>
                                <button
                                    class="btn btn-secondary btn-sm"
                                    :disabled="dismissing[bookmark.id]"
                                    @click="dismissBookmark(bookmark.id)"
                                >
                                    <CloseIcon class="w-4 h-4" />
                                </button>
                            </template>

                            <a
                                v-if="bookmark.pr_url"
                                :href="bookmark.pr_url"
                                target="_blank"
                                class="badge badge-purple"
                            >
                                View PR
                                <ExternalLinkIcon class="w-3 h-3 ml-1" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="bookmarks.last_page > 1" class="px-4 py-3 border-t flex items-center justify-between" style="border-color: var(--color-border-subtle);">
                <p class="text-caption">
                    Showing {{ (bookmarks.current_page - 1) * bookmarks.per_page + 1 }} to
                    {{ Math.min(bookmarks.current_page * bookmarks.per_page, bookmarks.total) }} of
                    {{ bookmarks.total }} results
                </p>
                <div class="flex gap-1">
                    <template v-for="link in bookmarks.links" :key="link.label">
                        <button
                            v-if="link.url"
                            :class="[
                                'px-3 py-1 text-sm rounded transition-colors',
                                link.active
                                    ? 'btn-primary'
                                    : 'hover:bg-[var(--color-bg-hover)]'
                            ]"
                            @click="router.get(link.url)"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="px-3 py-1 text-caption"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>

        <!-- Detail Modal -->
        <Modal :show="showDetailModal" max-width="2xl" @close="showDetailModal = false">
            <div v-if="selectedBookmark" class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-heading text-lg">Bookmark Details</h3>
                        <p class="text-caption">@{{ selectedBookmark.author_username }}</p>
                    </div>
                    <button @click="showDetailModal = false" class="p-1 rounded hover:bg-[var(--color-bg-hover)]">
                        <CloseIcon class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    <!-- Tweet Content -->
                    <div class="rounded-lg p-4" style="background: var(--color-bg-tertiary);">
                        <p class="text-body whitespace-pre-wrap">{{ selectedBookmark.text }}</p>
                        <div class="mt-2 flex items-center gap-4 text-caption">
                            <span>{{ selectedBookmark.like_count }} likes</span>
                            <span>{{ selectedBookmark.retweet_count }} RTs</span>
                            <a
                                :href="getTweetUrl(selectedBookmark)"
                                target="_blank"
                                class="text-[var(--color-accent)] hover:underline inline-flex items-center"
                            >
                                View on X <ExternalLinkIcon class="w-3 h-3 ml-1" />
                            </a>
                        </div>
                    </div>

                    <!-- URLs -->
                    <div v-if="selectedBookmark.urls?.length">
                        <h4 class="text-caption uppercase mb-2">Links</h4>
                        <ul class="space-y-1">
                            <li v-for="(url, i) in selectedBookmark.urls" :key="i">
                                <a :href="url.url" target="_blank" class="text-[var(--color-accent)] hover:underline text-sm">
                                    {{ url.title || url.url }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- AI Analysis -->
                    <div v-if="selectedBookmark.ai_analysis" class="border-t pt-4" style="border-color: var(--color-border-subtle);">
                        <h4 class="text-caption uppercase mb-2">AI Analysis</h4>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-caption">Category:</span>
                                <span :class="['ml-2 badge', getCategoryBadge(selectedBookmark.category)]">
                                    {{ categories[selectedBookmark.category || ''] || selectedBookmark.category || 'Unknown' }}
                                </span>
                            </div>
                            <div>
                                <span class="text-caption">Relevance:</span>
                                <span class="ml-2 text-heading">{{ selectedBookmark.relevance_score }}%</span>
                            </div>
                            <div class="col-span-2" v-if="selectedBookmark.action_summary">
                                <span class="text-caption">Action:</span>
                                <p class="mt-1 px-2 py-1 rounded" style="background: rgba(34, 197, 94, 0.1); color: var(--color-status-green);">
                                    {{ selectedBookmark.action_summary }}
                                </p>
                            </div>
                            <div class="col-span-2" v-if="selectedBookmark.ai_analysis.reasoning">
                                <span class="text-caption">Reasoning:</span>
                                <p class="mt-1 text-body">{{ selectedBookmark.ai_analysis.reasoning }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- PR Info -->
                    <div v-if="selectedBookmark.pr_url" class="border-t pt-4" style="border-color: var(--color-border-subtle);">
                        <h4 class="text-caption uppercase mb-2">Pull Request</h4>
                        <a
                            :href="selectedBookmark.pr_url"
                            target="_blank"
                            class="badge badge-purple"
                        >
                            View PR: {{ selectedBookmark.pr_branch }}
                            <ExternalLinkIcon class="w-4 h-4 ml-2" />
                        </a>
                    </div>

                    <!-- Actions -->
                    <div class="border-t pt-4 flex justify-end gap-3" style="border-color: var(--color-border-subtle);">
                        <button
                            v-if="selectedBookmark.status === 'actionable' && !selectedBookmark.pr_url"
                            class="btn btn-primary"
                            :disabled="creatingPr[selectedBookmark.id]"
                            @click="createPr(selectedBookmark.id)"
                        >
                            {{ creatingPr[selectedBookmark.id] ? 'Creating PR...' : 'Create PR' }}
                        </button>
                        <button
                            v-if="selectedBookmark.status !== 'dismissed' && selectedBookmark.status !== 'pr_created'"
                            class="btn btn-secondary"
                            :disabled="dismissing[selectedBookmark.id]"
                            @click="dismissBookmark(selectedBookmark.id); showDetailModal = false"
                        >
                            Dismiss
                        </button>
                    </div>
                </div>
            </div>
        </Modal>

        <!-- Import Modal -->
        <Modal :show="showImportModal" max-width="3xl" @close="showImportModal = false">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-heading text-lg">Import Bookmarks</h3>
                        <p class="text-caption">Paste JSON or upload a file containing your X bookmarks</p>
                    </div>
                    <button @click="showImportModal = false" class="p-1 rounded hover:bg-[var(--color-bg-hover)]">
                        <CloseIcon class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    <!-- Credential Selector -->
                    <div v-if="credentialsWithScope.length > 1">
                        <FormSelect
                            label="Import to Account"
                            v-model="importCredentialId"
                        >
                            <option :value="null">Select account...</option>
                            <option v-for="cred in credentialsWithScope" :key="cred.id" :value="cred.id">
                                @{{ cred.username }}
                            </option>
                        </FormSelect>
                    </div>
                    <div v-else-if="credentialsWithScope.length === 1" class="text-body">
                        Importing to: <span class="text-heading">@{{ credentialsWithScope[0].username }}</span>
                    </div>

                    <!-- JSON Input -->
                    <div>
                        <label class="block text-sm font-medium text-caption mb-2">JSON Data</label>
                        <textarea
                            v-model="importJsonText"
                            class="w-full h-40 px-3 py-2 rounded-lg border text-body text-sm font-mono resize-none"
                            style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle);"
                            placeholder='[{"tweet_id": "123", "text": "...", "author_username": "user"}]'
                        ></textarea>
                    </div>

                    <!-- File Upload -->
                    <div>
                        <label class="block text-sm font-medium text-caption mb-2">Or Upload File</label>
                        <input
                            type="file"
                            accept=".json"
                            class="block w-full text-sm text-caption file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:cursor-pointer"
                            style="--tw-file-bg: var(--color-bg-tertiary);"
                            @change="handleFileUpload"
                        />
                    </div>

                    <!-- Preview Button -->
                    <div class="flex items-center gap-4">
                        <button
                            class="btn btn-secondary"
                            :disabled="!importJsonText || importPreviewLoading"
                            @click="parseAndPreview"
                        >
                            {{ importPreviewLoading ? 'Loading...' : 'Preview Import' }}
                        </button>
                        <label class="flex items-center gap-2 text-sm text-body cursor-pointer">
                            <input type="checkbox" v-model="skipDuplicates" class="rounded" />
                            Skip duplicates
                        </label>
                    </div>

                    <!-- Error Message -->
                    <div
                        v-if="importError"
                        class="px-4 py-3 rounded-lg text-sm"
                        style="background: rgba(239, 68, 68, 0.1); color: var(--color-status-red);"
                    >
                        {{ importError }}
                    </div>

                    <!-- Success Message -->
                    <div
                        v-if="importSuccess"
                        class="px-4 py-3 rounded-lg text-sm"
                        style="background: rgba(34, 197, 94, 0.1); color: var(--color-status-green);"
                    >
                        {{ importSuccess }}
                    </div>

                    <!-- Preview Results -->
                    <div v-if="importPreviewResult" class="border-t pt-4" style="border-color: var(--color-border-subtle);">
                        <!-- Stats -->
                        <div class="grid grid-cols-4 gap-4 mb-4">
                            <div class="text-center p-3 rounded-lg" style="background: var(--color-bg-tertiary);">
                                <div class="text-lg font-semibold text-heading">{{ importPreviewResult.total }}</div>
                                <div class="text-xs text-caption">Total</div>
                            </div>
                            <div class="text-center p-3 rounded-lg" style="background: var(--color-bg-tertiary);">
                                <div class="text-lg font-semibold" style="color: var(--color-status-green);">{{ importPreviewResult.new_count }}</div>
                                <div class="text-xs text-caption">New</div>
                            </div>
                            <div class="text-center p-3 rounded-lg" style="background: var(--color-bg-tertiary);">
                                <div class="text-lg font-semibold" style="color: var(--color-status-yellow);">{{ importPreviewResult.duplicate_count }}</div>
                                <div class="text-xs text-caption">Duplicates</div>
                            </div>
                            <div class="text-center p-3 rounded-lg" style="background: var(--color-bg-tertiary);">
                                <div class="text-lg font-semibold" style="color: var(--color-status-red);">{{ importPreviewResult.invalid_count }}</div>
                                <div class="text-xs text-caption">Invalid</div>
                            </div>
                        </div>

                        <!-- Bookmark List Preview -->
                        <div class="max-h-64 overflow-y-auto rounded-lg border" style="border-color: var(--color-border-subtle);">
                            <div
                                v-for="(bm, idx) in importPreviewResult.bookmarks.slice(0, 50)"
                                :key="idx"
                                class="p-3 border-b last:border-b-0 flex items-start gap-3"
                                :style="{
                                    borderColor: 'var(--color-border-subtle)',
                                    opacity: bm.is_duplicate ? 0.5 : 1,
                                    background: bm.is_duplicate ? 'var(--color-bg-tertiary)' : 'transparent'
                                }"
                            >
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-sm text-heading">@{{ bm.author_username || 'unknown' }}</span>
                                        <span
                                            v-if="bm.is_duplicate"
                                            class="badge badge-gray text-xs"
                                        >
                                            Duplicate
                                        </span>
                                        <span
                                            v-else
                                            class="badge badge-green text-xs"
                                        >
                                            New
                                        </span>
                                    </div>
                                    <p class="text-sm text-body truncate">{{ bm.text }}</p>
                                </div>
                            </div>
                            <div
                                v-if="importPreviewResult.bookmarks.length > 50"
                                class="p-3 text-center text-caption text-sm"
                                style="background: var(--color-bg-tertiary);"
                            >
                                ... and {{ importPreviewResult.bookmarks.length - 50 }} more
                            </div>
                        </div>

                        <!-- Import Button -->
                        <div class="mt-4 flex justify-end gap-3">
                            <button class="btn btn-secondary" @click="showImportModal = false">
                                Cancel
                            </button>
                            <button
                                class="btn btn-primary"
                                :disabled="importLoading || (skipDuplicates && importPreviewResult.new_count === 0)"
                                @click="executeImport"
                            >
                                {{ importLoading ? 'Importing...' : `Import ${skipDuplicates ? importPreviewResult.new_count : importPreviewResult.total} Bookmarks` }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.badge-purple {
    background: rgba(168, 85, 247, 0.12);
    color: #c084fc;
}
</style>
