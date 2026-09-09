<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';
import Modal from '@/Components/Modal.vue';
import SyncStatusBadge from '@/Components/SyncStatusBadge.vue';
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

const baseUrl = ref('');

interface GoogleIntegration {
    connected: boolean;
    email?: string;
    expires_at?: string;
    scopes?: string[];
}

interface SlackWorkspace {
    id: number;
    name: string;
    workspace_id: string;
}

interface SlackIntegration {
    connected: boolean;
    workspaces: SlackWorkspace[];
}

interface GitHubInstallation {
    id: number;
    account_login: string;
    account_type: string;
    repos_count: number;
}

interface GitHubIntegration {
    connected: boolean;
    installations: GitHubInstallation[];
    app_slug?: string;
}

interface GitHubUserIntegration {
    connected: boolean;
    username?: string;
    email?: string;
    avatar_url?: string;
    scopes?: string[];
    expires_at?: string;
}

interface HarvestIntegration {
    connected: boolean;
    account_name?: string;
    expires_at?: string;
}

interface NotionWorkspace {
    id: number;
    name: string;
    icon?: string;
    pages_count: number;
}

interface NotionIntegration {
    connected: boolean;
    workspaces: NotionWorkspace[];
}

interface WordPressSite {
    id: number;
    name: string;
    url: string;
    mcp_enabled: boolean;
    posts_count: number;
    last_synced_at?: string;
}

interface WordPressIntegration {
    connected: boolean;
    sites: WordPressSite[];
}

interface QuickBooksCompany {
    id: number;
    company_name: string;
    realm_id: string;
    last_synced_at?: string;
}

interface QuickBooksIntegration {
    connected: boolean;
    companies: QuickBooksCompany[];
}

interface LinkedInIntegration {
    connected: boolean;
    name?: string;
    email?: string;
    headline?: string;
    profile_url?: string;
    organization_name?: string;
    expires_at?: string;
}

interface XAccount {
    id: number;
    username: string;
    name?: string;
    account_type: 'personal' | 'company';
    verified: boolean;
    followers_count: number;
    expires_at?: string;
}

interface XIntegration {
    connected: boolean;
    accounts: XAccount[];
    // Backwards compatibility
    username?: string;
    name?: string;
    followers_count?: number;
    tweet_count?: number;
    verified?: boolean;
    expires_at?: string;
}

interface ClickUpConnection {
    id: number;
    workspace_name: string;
    workspace_id: string;
    client_id: number | null;
    client_name: string | null;
    sources_count: number;
    last_synced_at?: string;
}

interface ClickUpIntegration {
    connected: boolean;
    connections: ClickUpConnection[];
}

interface ClientOption {
    id: number;
    name: string;
}

interface ProjectOption {
    id: number;
    name: string;
    client_id: number | null;
    client?: { id: number; name: string };
}

interface ClickUpListItem {
    id: string;
    name: string;
    type: string;
    task_count?: number;
    folders?: ClickUpFolderItem[];
    lists?: ClickUpListItem[];
}

interface ClickUpFolderItem {
    id: string;
    name: string;
    type: string;
    lists: ClickUpListItem[];
}

interface ClickUpSpaceItem {
    id: string;
    name: string;
    type: string;
    folders: ClickUpFolderItem[];
    lists: ClickUpListItem[];
}

interface SpinupWpServer {
    id: number;
    spinup_id: number;
    name: string;
    ip_address: string;
    provider_name?: string;
    status: string;
    connection_status: string;
    is_default: boolean;
    sites_count: number;
    disk_usage_percent?: number;
    disk_available_gb?: number;
    last_synced_at?: string;
}

interface SpinupWpIntegration {
    configured: boolean;
    connected: boolean;
    servers: SpinupWpServer[];
    default_server_id?: number;
    staging_domain?: string;
}

interface Props {
    integrations: {
        google: GoogleIntegration;
        slack: SlackIntegration;
        github: GitHubIntegration;
        github_user: GitHubUserIntegration;
        harvest: HarvestIntegration;
        notion: NotionIntegration;
        wordpress: WordPressIntegration;
        quickbooks: QuickBooksIntegration;
        linkedin: LinkedInIntegration;
        x: XIntegration;
        clickup: ClickUpIntegration;
        spinupwp: SpinupWpIntegration;
    };
    clients: ClientOption[];
    projects: ProjectOption[];
}

const props = defineProps<Props>();

const syncing = ref<Record<string, boolean>>({});
const expandedSetup = ref<string | null>(null);

// WordPress form state
const wpForm = ref({
    name: '',
    url: '',
    username: '',
    app_password: '',
    mcp_enabled: false,
});
const showWpForm = ref(false);

// ClickUp form state
const clickUpForm = ref({
    access_token: '',
});
const showClickUpForm = ref(false);

// ClickUp configuration modal state
const showClickUpConfig = ref(false);
const configConnection = ref<ClickUpConnection | null>(null);
const clickUpStructure = ref<ClickUpSpaceItem[]>([]);
const loadingStructure = ref(false);
const selectedList = ref<{ id: string; name: string; type: string } | null>(null);
const selectedProjectId = ref<number | null>(null);

onMounted(() => {
    baseUrl.value = window.location.origin;
});

// Helper for CSRF-protected fetch calls
const csrfFetch = (url: string, options: RequestInit = {}) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    return fetch(url, {
        ...options,
        headers: {
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            ...options.headers,
        },
    });
};

const toggleSetup = (integration: string) => {
    expandedSetup.value = expandedSetup.value === integration ? null : integration;
};

// Google handlers
const connectGoogle = () => {
    window.location.href = '/auth/google';
};

const disconnectGoogle = async () => {
    if (!confirm('Disconnect Google? This will stop email and calendar sync.')) return;
    syncing.value.google = true;
    try {
        await csrfFetch('/api/integrations/google/disconnect', { method: 'POST' });
        router.reload();
    } finally {
        syncing.value.google = false;
    }
};

const syncGoogle = async (type: 'emails' | 'calendar') => {
    syncing.value[`google_${type}`] = true;
    try {
        await csrfFetch(`/api/integrations/google/sync/${type}`, { method: 'POST' });
    } finally {
        syncing.value[`google_${type}`] = false;
    }
};

// Slack handlers
const connectSlack = () => {
    window.location.href = '/auth/slack';
};

const disconnectSlack = async (workspaceId: number) => {
    if (!confirm('Disconnect this Slack workspace?')) return;
    syncing.value.slack = true;
    try {
        await csrfFetch(`/api/integrations/slack/workspaces/${workspaceId}`, { method: 'DELETE' });
        router.reload();
    } finally {
        syncing.value.slack = false;
    }
};

const syncSlackChannels = async (workspaceId: number) => {
    syncing.value[`slack_${workspaceId}`] = true;
    try {
        await csrfFetch(`/api/integrations/slack/workspaces/${workspaceId}/sync-channels`, { method: 'POST' });
        router.reload();
    } finally {
        syncing.value[`slack_${workspaceId}`] = false;
    }
};

// GitHub App handlers (org-level access)
const installGitHub = () => {
    const appSlug = props.integrations.github.app_slug || 'your-app';
    window.location.href = `https://github.com/apps/${appSlug}/installations/new`;
};

const syncGitHub = async () => {
    syncing.value.github = true;
    try {
        await csrfFetch('/api/integrations/github/sync', { method: 'POST' });
        router.reload();
    } finally {
        syncing.value.github = false;
    }
};

// GitHub User OAuth handlers (personal repo access)
const connectGitHubUser = () => {
    window.location.href = '/auth/github/user';
};

const disconnectGitHubUser = async () => {
    if (!confirm('Disconnect your personal GitHub account?')) return;
    syncing.value.github_user = true;
    try {
        await csrfFetch('/api/integrations/github/user/disconnect', { method: 'DELETE' });
        router.reload();
    } finally {
        syncing.value.github_user = false;
    }
};

// Harvest handlers
const connectHarvest = () => {
    window.location.href = '/auth/harvest';
};

const disconnectHarvest = async () => {
    if (!confirm('Disconnect Harvest? This will stop time tracking sync.')) return;
    syncing.value.harvest = true;
    try {
        await csrfFetch('/api/integrations/harvest/disconnect', { method: 'POST' });
        router.reload();
    } finally {
        syncing.value.harvest = false;
    }
};

const syncHarvest = async () => {
    syncing.value.harvestSync = true;
    try {
        await csrfFetch('/api/integrations/harvest/sync/all', { method: 'POST' });
        router.reload();
    } finally {
        syncing.value.harvestSync = false;
    }
};

// Notion handlers
const connectNotion = () => {
    window.location.href = '/auth/notion';
};

const disconnectNotion = async (connectionId: number) => {
    if (!confirm('Disconnect this Notion workspace?')) return;
    syncing.value[`notion_${connectionId}`] = true;
    try {
        await csrfFetch(`/api/integrations/notion/connections/${connectionId}`, { method: 'DELETE' });
        router.reload();
    } finally {
        syncing.value[`notion_${connectionId}`] = false;
    }
};

const syncNotion = async (connectionId: number) => {
    syncing.value[`notion_sync_${connectionId}`] = true;
    try {
        await csrfFetch(`/api/integrations/notion/connections/${connectionId}/sync`, { method: 'POST' });
        router.reload();
    } finally {
        syncing.value[`notion_sync_${connectionId}`] = false;
    }
};

// WordPress handlers
const addWordPress = async () => {
    if (!wpForm.value.name || !wpForm.value.url || !wpForm.value.username || !wpForm.value.app_password) {
        alert('Please fill in all required fields');
        return;
    }
    syncing.value.wordpress = true;
    try {
        const response = await csrfFetch('/api/integrations/wordpress/sites', {
            method: 'POST',
            body: JSON.stringify(wpForm.value),
        });
        if (response.ok) {
            showWpForm.value = false;
            wpForm.value = { name: '', url: '', username: '', app_password: '', mcp_enabled: false };
            router.reload();
        } else {
            const data = await response.json();
            alert(data.message || 'Failed to connect WordPress site');
        }
    } finally {
        syncing.value.wordpress = false;
    }
};

const disconnectWordPress = async (siteId: number) => {
    if (!confirm('Disconnect this WordPress site?')) return;
    syncing.value[`wp_${siteId}`] = true;
    try {
        await csrfFetch(`/api/integrations/wordpress/sites/${siteId}`, { method: 'DELETE' });
        router.reload();
    } finally {
        syncing.value[`wp_${siteId}`] = false;
    }
};

const syncWordPress = async (siteId: number) => {
    syncing.value[`wp_sync_${siteId}`] = true;
    try {
        await csrfFetch(`/api/integrations/wordpress/sites/${siteId}/sync`, { method: 'POST' });
        router.reload();
    } finally {
        syncing.value[`wp_sync_${siteId}`] = false;
    }
};

// QuickBooks handlers
const connectQuickBooks = () => {
    window.location.href = '/auth/quickbooks';
};

const disconnectQuickBooks = async (connectionId: number) => {
    if (!confirm('Disconnect this QuickBooks company?')) return;
    syncing.value[`qbo_${connectionId}`] = true;
    try {
        await csrfFetch(`/api/integrations/quickbooks/connections/${connectionId}`, { method: 'DELETE' });
        router.reload();
    } finally {
        syncing.value[`qbo_${connectionId}`] = false;
    }
};

const syncQuickBooks = async (connectionId: number) => {
    syncing.value[`qbo_sync_${connectionId}`] = true;
    try {
        await csrfFetch(`/api/integrations/quickbooks/connections/${connectionId}/sync`, { method: 'POST' });
        router.reload();
    } finally {
        syncing.value[`qbo_sync_${connectionId}`] = false;
    }
};

// LinkedIn handlers
const connectLinkedIn = () => {
    window.location.href = '/auth/linkedin';
};

const disconnectLinkedIn = async () => {
    if (!confirm('Disconnect LinkedIn? This will stop social posting.')) return;
    syncing.value.linkedin = true;
    try {
        await csrfFetch('/api/integrations/linkedin/disconnect', { method: 'POST' });
        router.reload();
    } finally {
        syncing.value.linkedin = false;
    }
};

// X (Twitter) handlers
const connectX = (accountType: 'personal' | 'company' = 'personal') => {
    window.location.href = `/auth/x?account_type=${accountType}`;
};

const disconnectX = async (accountId?: number) => {
    if (!confirm('Disconnect this X account?')) return;
    const key = accountId ? `x_${accountId}` : 'x';
    syncing.value[key] = true;
    try {
        const url = accountId
            ? `/api/integrations/x/disconnect/${accountId}`
            : '/api/integrations/x/disconnect';
        await csrfFetch(url, { method: 'POST' });
        router.reload();
    } finally {
        syncing.value[key] = false;
    }
};

// Check if account type is already connected
const hasAccountType = (type: 'personal' | 'company') => {
    return props.integrations.x.accounts?.some(a => a.account_type === type) ?? false;
};

// ClickUp handlers
const connectClickUp = async () => {
    if (!clickUpForm.value.access_token) {
        alert('Please enter your ClickUp Personal API Token');
        return;
    }
    syncing.value.clickup = true;
    try {
        const response = await csrfFetch('/api/integrations/clickup/connect', {
            method: 'POST',
            body: JSON.stringify(clickUpForm.value),
        });
        if (response.ok) {
            showClickUpForm.value = false;
            clickUpForm.value = { access_token: '' };
            router.reload();
        } else {
            const data = await response.json();
            alert(data.message || 'Failed to connect ClickUp');
        }
    } finally {
        syncing.value.clickup = false;
    }
};

const disconnectClickUp = async (connectionId: number) => {
    if (!confirm('Disconnect this ClickUp workspace?')) return;
    syncing.value[`clickup_${connectionId}`] = true;
    try {
        await csrfFetch(`/api/integrations/clickup/connections/${connectionId}`, { method: 'DELETE' });
        router.reload();
    } finally {
        syncing.value[`clickup_${connectionId}`] = false;
    }
};

const syncClickUp = async (connectionId: number) => {
    syncing.value[`clickup_sync_${connectionId}`] = true;
    try {
        await csrfFetch(`/api/integrations/clickup/connections/${connectionId}/sync`, { method: 'POST' });
        router.reload();
    } finally {
        syncing.value[`clickup_sync_${connectionId}`] = false;
    }
};

const updateClickUpClient = async (connectionId: number, clientId: number | null) => {
    syncing.value[`clickup_client_${connectionId}`] = true;
    try {
        await csrfFetch(`/api/integrations/clickup/connections/${connectionId}`, {
            method: 'PATCH',
            body: JSON.stringify({ client_id: clientId }),
        });
        router.reload();
    } finally {
        syncing.value[`clickup_client_${connectionId}`] = false;
    }
};

const openClickUpConfig = async (connection: ClickUpConnection) => {
    configConnection.value = connection;
    showClickUpConfig.value = true;
    loadingStructure.value = true;
    selectedList.value = null;
    selectedProjectId.value = null;

    try {
        const response = await csrfFetch(`/api/integrations/clickup/connections/${connection.id}/structure`);
        const data = await response.json();
        clickUpStructure.value = data.structure || [];
    } catch (e) {
        console.error('Failed to load ClickUp structure', e);
        clickUpStructure.value = [];
    } finally {
        loadingStructure.value = false;
    }
};

const closeClickUpConfig = () => {
    showClickUpConfig.value = false;
    configConnection.value = null;
    clickUpStructure.value = [];
    selectedList.value = null;
    selectedProjectId.value = null;
};

const selectList = (list: { id: string; name: string; type: string }) => {
    selectedList.value = list;
};

const filteredProjects = computed(() => {
    if (!configConnection.value?.client_id) {
        return props.projects;
    }
    return props.projects.filter(p => p.client_id === configConnection.value?.client_id);
});

const addSource = async () => {
    if (!selectedList.value || !selectedProjectId.value || !configConnection.value) {
        alert('Please select a list and project');
        return;
    }

    syncing.value.addSource = true;
    try {
        const response = await csrfFetch(`/api/integrations/clickup/connections/${configConnection.value.id}/sources`, {
            method: 'POST',
            body: JSON.stringify({
                external_id: selectedList.value.id,
                name: selectedList.value.name,
                type: selectedList.value.type,
                project_id: selectedProjectId.value,
            }),
        });

        if (response.ok) {
            closeClickUpConfig();
            router.reload();
        } else {
            const data = await response.json();
            alert(data.message || 'Failed to add source');
        }
    } finally {
        syncing.value.addSource = false;
    }
};

// SpinupWP handlers
const syncSpinupWp = async () => {
    syncing.value.spinupwp = true;
    try {
        const response = await csrfFetch('/api/integrations/spinupwp/sync-servers', { method: 'POST' });
        if (response.ok) {
            router.reload();
        } else {
            const data = await response.json();
            alert(data.error || 'Failed to sync servers');
        }
    } finally {
        syncing.value.spinupwp = false;
    }
};

const setDefaultSpinupServer = async (serverId: number) => {
    syncing.value[`spinupwp_default_${serverId}`] = true;
    try {
        const response = await csrfFetch('/api/integrations/spinupwp/default-server', {
            method: 'POST',
            body: JSON.stringify({ server_id: serverId }),
        });
        if (response.ok) {
            router.reload();
        } else {
            const data = await response.json();
            alert(data.error || 'Failed to set default server');
        }
    } finally {
        syncing.value[`spinupwp_default_${serverId}`] = false;
    }
};

const refreshSpinupServer = async (serverId: number) => {
    syncing.value[`spinupwp_refresh_${serverId}`] = true;
    try {
        const response = await csrfFetch(`/api/integrations/spinupwp/servers/${serverId}/refresh`, { method: 'POST' });
        if (response.ok) {
            router.reload();
        } else {
            const data = await response.json();
            alert(data.error || 'Failed to refresh server');
        }
    } finally {
        syncing.value[`spinupwp_refresh_${serverId}`] = false;
    }
};

const removeSpinupServer = async (serverId: number) => {
    if (!confirm('Remove this server from tracking? The server will not be deleted from SpinupWP.')) return;
    syncing.value[`spinupwp_remove_${serverId}`] = true;
    try {
        const response = await csrfFetch(`/api/integrations/spinupwp/servers/${serverId}`, { method: 'DELETE' });
        if (response.ok) {
            router.reload();
        } else {
            const data = await response.json();
            alert(data.error || 'Failed to remove server');
        }
    } finally {
        syncing.value[`spinupwp_remove_${serverId}`] = false;
    }
};
</script>

<template>
    <AppLayout title="Integrations">
        <div class="max-w-4xl">
            <div class="mb-6">
                <h2 class="text-xl font-semibold mb-2" style="color: var(--color-text-primary)">
                    Connected Services
                </h2>
                <p class="text-sm" style="color: var(--color-text-tertiary)">
                    Connect your external services to enable data sync and automation.
                </p>
            </div>

            <!-- Google Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #4285F4, #34A853)">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">Google Workspace</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Gmail, Calendar, Drive</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.google.connected" class="badge badge-success">Connected</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                        <SyncStatusBadge v-if="integrations.google.connected" service="google" @complete="router.reload()" />
                    </div>
                </div>

                <div v-if="integrations.google.connected" class="mb-4 p-4 rounded-lg" style="background: var(--color-bg-secondary)">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--color-text-secondary)">{{ integrations.google.email }}</div>
                            <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                Token expires {{ new Date(integrations.google.expires_at || '').toLocaleDateString() }}
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="ghost" @click="syncGoogle('emails')" :loading="syncing.google_emails">
                                Sync Emails
                            </Button>
                            <Button size="sm" variant="ghost" @click="syncGoogle('calendar')" :loading="syncing.google_calendar">
                                Sync Calendar
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button v-if="!integrations.google.connected" variant="primary" @click="connectGoogle">
                        Connect Google
                    </Button>
                    <Button v-else variant="danger" @click="disconnectGoogle" :loading="syncing.google">
                        Disconnect
                    </Button>
                    <button @click="toggleSetup('google')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'google' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'google'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">Google Cloud Setup</h4>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Go to <a href="https://console.cloud.google.com" target="_blank" class="text-accent underline">Google Cloud Console</a> and create a new project</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Enable APIs: Gmail API, Google Calendar API, Google Drive API</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Configure OAuth consent screen (External or Internal for Workspace)</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Create OAuth 2.0 credentials (Web application type)</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">5</span>
                            <span>Add authorized redirect URI: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/auth/google/callback</code></span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>GOOGLE_CLIENT_ID=your-client-id</div>
                        <div>GOOGLE_CLIENT_SECRET=your-client-secret</div>
                    </div>
                </div>
            </div>

            <!-- Slack Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: #4A154B">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zM8.834 6.313a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zM18.956 8.834a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zM17.688 8.834a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zM15.165 18.956a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zM15.165 17.688a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">Slack</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Channels, Messages, Threads</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.slack.connected" class="badge badge-success">{{ integrations.slack.workspaces.length }} Workspace(s)</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                        <SyncStatusBadge v-if="integrations.slack.connected" service="slack" @complete="router.reload()" />
                    </div>
                </div>

                <div v-if="integrations.slack.workspaces.length" class="mb-4 space-y-2">
                    <div v-for="workspace in integrations.slack.workspaces" :key="workspace.id" class="p-4 rounded-lg flex items-center justify-between" style="background: var(--color-bg-secondary)">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--color-text-secondary)">{{ workspace.name }}</div>
                            <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">Workspace ID: {{ workspace.workspace_id }}</div>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="ghost" @click="syncSlackChannels(workspace.id)" :loading="syncing[`slack_${workspace.id}`]">
                                Sync Channels
                            </Button>
                            <Button size="sm" variant="danger" @click="disconnectSlack(workspace.id)">
                                Remove
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button variant="primary" @click="connectSlack">
                        {{ integrations.slack.connected ? 'Add Another Workspace' : 'Connect Slack' }}
                    </Button>
                    <button @click="toggleSetup('slack')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'slack' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'slack'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">Slack App Setup</h4>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Go to <a href="https://api.slack.com/apps" target="_blank" class="text-accent underline">Slack API Apps</a> and create a new app</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Under OAuth & Permissions, add scopes: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">channels:history, channels:read, chat:write, users:read</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Add redirect URL: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/auth/slack/callback</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Under Event Subscriptions, enable events and set URL: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/webhooks/slack/events</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">5</span>
                            <span>Copy the Signing Secret from Basic Information</span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>SLACK_CLIENT_ID=your-client-id</div>
                        <div>SLACK_CLIENT_SECRET=your-client-secret</div>
                        <div>SLACK_SIGNING_SECRET=your-signing-secret</div>
                    </div>
                </div>
            </div>

            <!-- GitHub Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: #24292e">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">GitHub</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Repos, Issues, Pull Requests</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.github.connected" class="badge badge-success">{{ integrations.github.installations.length }} Installation(s)</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                        <SyncStatusBadge v-if="integrations.github.connected" service="github" @complete="router.reload()" />
                    </div>
                </div>

                <!-- GitHub App Installations (Org-level access) -->
                <div v-if="integrations.github.installations.length" class="mb-4 space-y-2">
                    <div class="text-xs font-medium uppercase tracking-wide mb-2" style="color: var(--color-text-tertiary)">Organization Access (via GitHub App)</div>
                    <div v-for="installation in integrations.github.installations" :key="installation.id" class="p-4 rounded-lg flex items-center justify-between" style="background: var(--color-bg-secondary)">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--color-text-secondary)">{{ installation.account_login }}</div>
                            <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">{{ installation.account_type }} &bull; {{ installation.repos_count }} repos</div>
                        </div>
                        <Button size="sm" variant="ghost" @click="syncGitHub" :loading="syncing.github">
                            Sync Repos
                        </Button>
                    </div>
                </div>

                <!-- GitHub User OAuth (Personal access) -->
                <div class="mb-4">
                    <div class="text-xs font-medium uppercase tracking-wide mb-2" style="color: var(--color-text-tertiary)">Personal Access (via OAuth)</div>
                    <div v-if="integrations.github_user.connected" class="p-4 rounded-lg flex items-center justify-between" style="background: var(--color-bg-secondary)">
                        <div class="flex items-center gap-3">
                            <img v-if="integrations.github_user.avatar_url" :src="integrations.github_user.avatar_url" class="w-8 h-8 rounded-full" alt="" />
                            <div>
                                <div class="text-sm font-medium" style="color: var(--color-text-secondary)">@{{ integrations.github_user.username }}</div>
                                <div class="text-xs mt-0.5" style="color: var(--color-text-quaternary)">{{ integrations.github_user.email }}</div>
                            </div>
                        </div>
                        <Button size="sm" variant="danger" @click="disconnectGitHubUser" :loading="syncing.github_user">
                            Disconnect
                        </Button>
                    </div>
                    <div v-else class="p-4 rounded-lg" style="background: var(--color-bg-secondary)">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm" style="color: var(--color-text-secondary)">Connect your personal GitHub account</div>
                                <div class="text-xs mt-0.5" style="color: var(--color-text-quaternary)">Access repos you have permission for, even without the GitHub App</div>
                            </div>
                            <Button size="sm" variant="primary" @click="connectGitHubUser">
                                Connect
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button variant="primary" @click="installGitHub">
                        {{ integrations.github.connected ? 'Add Another Org' : 'Install GitHub App' }}
                    </Button>
                    <button @click="toggleSetup('github')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'github' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'github'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">GitHub App Setup</h4>
                    <div class="mb-3 p-3 rounded" style="background: rgba(234, 179, 8, 0.1); border: 1px solid rgba(234, 179, 8, 0.3)">
                        <p class="text-sm" style="color: var(--color-status-yellow)">
                            <strong>Note:</strong> GitHub uses App authentication (not OAuth). You need to create a GitHub App, not an OAuth App.
                        </p>
                    </div>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Go to <a href="https://github.com/settings/apps/new" target="_blank" class="text-accent underline">GitHub App Settings</a> and create a new GitHub App</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Set Callback URL: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/auth/github/callback</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Set Webhook URL: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/webhooks/github</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Add permissions: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">issues:read/write, pull_requests:read/write, contents:read</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">5</span>
                            <span>Subscribe to events: Issues, Pull request, Push</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">6</span>
                            <span>Generate a private key and download it</span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>GITHUB_APP_ID=123456</div>
                        <div>GITHUB_APP_SLUG=your-app-name</div>
                        <div>GITHUB_WEBHOOK_SECRET=your-webhook-secret</div>
                        <div>GITHUB_APP_PRIVATE_KEY="-----BEGIN RSA..."</div>
                    </div>
                </div>
            </div>

            <!-- Harvest Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #F36C00, #FA5D00)">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">Harvest</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Time Tracking, Invoices, Reports</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.harvest.connected" class="badge badge-success">Connected</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                        <SyncStatusBadge v-if="integrations.harvest.connected" service="harvest" @complete="router.reload()" />
                    </div>
                </div>

                <div v-if="integrations.harvest.connected" class="mb-4 p-4 rounded-lg" style="background: var(--color-bg-secondary)">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--color-text-secondary)">{{ integrations.harvest.account_name }}</div>
                            <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                Token expires {{ new Date(integrations.harvest.expires_at || '').toLocaleDateString() }}
                            </div>
                        </div>
                        <Button size="sm" variant="ghost" @click="syncHarvest" :loading="syncing.harvestSync">
                            Sync All Data
                        </Button>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button v-if="!integrations.harvest.connected" variant="primary" @click="connectHarvest">
                        Connect Harvest
                    </Button>
                    <Button v-else variant="danger" @click="disconnectHarvest" :loading="syncing.harvest">
                        Disconnect
                    </Button>
                    <button @click="toggleSetup('harvest')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'harvest' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'harvest'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">Harvest OAuth Setup</h4>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Go to <a href="https://id.getharvest.com/oauth2/clients" target="_blank" class="text-accent underline">Harvest OAuth2 Clients</a></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Create a new OAuth2 application</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Set redirect URI: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/auth/harvest/callback</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Copy the Client ID and Client Secret</span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>HARVEST_CLIENT_ID=your-client-id</div>
                        <div>HARVEST_CLIENT_SECRET=your-client-secret</div>
                    </div>
                </div>
            </div>

            <!-- Notion Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: #000">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M4.459 4.208c.746.606 1.026.56 2.428.466l13.215-.793c.28 0 .047-.28-.046-.326L17.86 1.968c-.42-.326-.98-.7-2.055-.607L3.01 2.295c-.466.046-.56.28-.374.466l1.823 1.447zm.793 3.08v13.904c0 .747.373 1.027 1.214.98l14.523-.84c.841-.046.935-.56.935-1.167V6.354c0-.606-.233-.933-.748-.887l-15.177.887c-.56.046-.747.327-.747.934zm14.337.746c.093.42 0 .84-.42.888l-.7.14v10.264c-.608.327-1.168.514-1.635.514-.748 0-.935-.234-1.495-.933l-4.577-7.186v6.952l1.448.327s0 .84-1.168.84l-3.22.187c-.094-.187 0-.653.327-.746l.84-.233V9.854L7.822 9.76c-.094-.42.14-1.026.793-1.073l3.454-.233 4.764 7.279v-6.44l-1.215-.14c-.093-.513.28-.887.747-.933l3.222-.187zM2.877.466L16.553.046c1.214-.093 1.54.14 2.287.7L22.456 3.08c.466.327.607.747.607 1.26v16.6c0 1.027-.373 1.634-1.68 1.727L5.45 23.56c-.98.046-1.448-.094-1.962-.747l-2.288-2.987c-.373-.56-.56-1.027-.56-1.634V2.012c0-.793.373-1.453 1.214-1.546l1.024-.046z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">Notion</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Pages, Databases, To-Dos</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.notion.connected" class="badge badge-success">{{ integrations.notion.workspaces.length }} Workspace(s)</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                        <SyncStatusBadge v-if="integrations.notion.connected" service="notion" @complete="router.reload()" />
                    </div>
                </div>

                <div v-if="integrations.notion.workspaces.length" class="mb-4 space-y-2">
                    <div v-for="workspace in integrations.notion.workspaces" :key="workspace.id" class="p-4 rounded-lg flex items-center justify-between" style="background: var(--color-bg-secondary)">
                        <div class="flex items-center gap-3">
                            <img v-if="workspace.icon?.startsWith('http')" :src="workspace.icon" class="w-6 h-6 rounded" alt="" />
                            <span v-else-if="workspace.icon" class="text-xl">{{ workspace.icon }}</span>
                            <div>
                                <div class="text-sm font-medium" style="color: var(--color-text-primary)">{{ workspace.name }}</div>
                                <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">{{ workspace.pages_count }} pages synced</div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="ghost" @click="syncNotion(workspace.id)" :loading="syncing[`notion_sync_${workspace.id}`]">
                                Sync Pages
                            </Button>
                            <Button size="sm" variant="danger" @click="disconnectNotion(workspace.id)" :loading="syncing[`notion_${workspace.id}`]">
                                Remove
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button variant="primary" @click="connectNotion">
                        {{ integrations.notion.connected ? 'Add Another Workspace' : 'Connect Notion' }}
                    </Button>
                    <button @click="toggleSetup('notion')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'notion' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'notion'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">Notion Integration Setup</h4>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Go to <a href="https://www.notion.so/my-integrations" target="_blank" class="text-accent underline">Notion Integrations</a> and create a new integration</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Choose "Public integration" for OAuth (allows multiple workspaces)</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Set redirect URI: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/auth/notion/callback</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Request capabilities: Read content, Read user information (no write needed)</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">5</span>
                            <span>Copy the OAuth client ID and OAuth client secret</span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>NOTION_CLIENT_ID=your-oauth-client-id</div>
                        <div>NOTION_CLIENT_SECRET=your-oauth-client-secret</div>
                    </div>
                </div>
            </div>

            <!-- WordPress Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: #21759b">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm0 1.8c4.53 0 8.2 3.67 8.2 8.2 0 4.53-3.67 8.2-8.2 8.2-4.53 0-8.2-3.67-8.2-8.2 0-4.53 3.67-8.2 8.2-8.2zM4.8 12l2.81 7.69c-1.71-1.62-2.78-3.91-2.78-6.44 0-.42.03-.84.09-1.25h-.12zm3.25 8.15L12 7.5l3.95 12.65c-1.22.55-2.56.85-3.95.85-1.39 0-2.73-.3-3.95-.85zm8.15-.46l2.65-7.65c.49-1.23.65-2.21.65-3.09 0-.32-.02-.62-.06-.9.61 1.21.96 2.58.96 4.02 0 2.53-1.07 4.82-2.78 6.44-.48-.16-.97-.37-1.42-.62v-.2zm.75-11.89c.36 1.04.56 2.19.56 3.42 0 1.06-.2 2.26-.79 3.75l-3.19 9.23C16.53 19.01 19.2 15.78 19.2 12c0-1.61-.39-3.13-1.08-4.47.55.43.98.99 1.26 1.63l.02.04c-.75-1.23-1.89-2.21-3.25-2.8z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">WordPress</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Posts, Content, MCP</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.wordpress.connected" class="badge badge-success">{{ integrations.wordpress.sites.length }} Site(s)</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                        <SyncStatusBadge v-if="integrations.wordpress.connected" service="wordpress" @complete="router.reload()" />
                    </div>
                </div>

                <div v-if="integrations.wordpress.sites.length" class="mb-4 space-y-2">
                    <div v-for="site in integrations.wordpress.sites" :key="site.id" class="p-4 rounded-lg flex items-center justify-between" style="background: var(--color-bg-secondary)">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--color-text-secondary)">{{ site.name }}</div>
                            <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                {{ site.url }} &bull; {{ site.posts_count }} posts
                                <span v-if="site.mcp_enabled" class="ml-2 px-1.5 py-0.5 rounded text-[10px] font-medium" style="background: var(--color-accent); color: white">MCP</span>
                            </div>
                            <div v-if="site.last_synced_at" class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                Last synced {{ site.last_synced_at }}
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="ghost" @click="syncWordPress(site.id)" :loading="syncing[`wp_sync_${site.id}`]">
                                Sync Posts
                            </Button>
                            <Button size="sm" variant="danger" @click="disconnectWordPress(site.id)" :loading="syncing[`wp_${site.id}`]">
                                Remove
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Add WordPress Form -->
                <div v-if="showWpForm" class="mb-4 p-4 rounded-lg border" style="background: var(--color-bg-secondary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3 text-sm" style="color: var(--color-text-primary)">Add WordPress Site</h4>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--color-text-tertiary)">Site Name</label>
                            <input v-model="wpForm.name" type="text" placeholder="My WordPress Site" class="w-full px-3 py-2 rounded-lg text-sm" style="background: var(--color-bg-primary); border: 1px solid var(--color-border-subtle); color: var(--color-text-primary)">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--color-text-tertiary)">Site URL</label>
                            <input v-model="wpForm.url" type="url" placeholder="https://example.com" class="w-full px-3 py-2 rounded-lg text-sm" style="background: var(--color-bg-primary); border: 1px solid var(--color-border-subtle); color: var(--color-text-primary)">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--color-text-tertiary)">Username</label>
                            <input v-model="wpForm.username" type="text" placeholder="admin" class="w-full px-3 py-2 rounded-lg text-sm" style="background: var(--color-bg-primary); border: 1px solid var(--color-border-subtle); color: var(--color-text-primary)">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--color-text-tertiary)">Application Password</label>
                            <input v-model="wpForm.app_password" type="password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" class="w-full px-3 py-2 rounded-lg text-sm" style="background: var(--color-bg-primary); border: 1px solid var(--color-border-subtle); color: var(--color-text-primary)">
                        </div>
                        <FormCheckbox
                            v-model="wpForm.mcp_enabled"
                            label="Enable MCP (requires WP MCP Adapter plugin)"
                        />
                        <div class="flex gap-2">
                            <Button variant="primary" @click="addWordPress" :loading="syncing.wordpress">Add Site</Button>
                            <Button variant="ghost" @click="showWpForm = false">Cancel</Button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button v-if="!showWpForm" variant="primary" @click="showWpForm = true">
                        {{ integrations.wordpress.connected ? 'Add Another Site' : 'Add WordPress Site' }}
                    </Button>
                    <button @click="toggleSetup('wordpress')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'wordpress' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'wordpress'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">WordPress Setup</h4>
                    <div class="mb-3 p-3 rounded" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3)">
                        <p class="text-sm" style="color: var(--color-accent)">
                            <strong>Note:</strong> WordPress uses Application Passwords (no OAuth needed). MCP support requires the WP MCP Adapter plugin.
                        </p>
                    </div>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>In WordPress Admin, go to Users &rarr; Profile</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Scroll to "Application Passwords" section</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Enter a name (e.g., "Zao Dash") and click "Add New Application Password"</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Copy the generated password (spaces are okay)</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">5</span>
                            <span><strong>Optional:</strong> Install the WP MCP Adapter plugin for AI-powered content creation</span>
                        </li>
                    </ol>
                </div>
            </div>

            <!-- QuickBooks Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: #2CA01C">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13v6h2V7h-2zm0 8v2h2v-2h-2z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">QuickBooks Online</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Invoices, Expenses, Reports</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.quickbooks.connected" class="badge badge-success">{{ integrations.quickbooks.companies.length }} Company</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                        <SyncStatusBadge v-if="integrations.quickbooks.connected" service="quickbooks" @complete="router.reload()" />
                    </div>
                </div>

                <div v-if="integrations.quickbooks.companies.length" class="mb-4 space-y-2">
                    <div v-for="company in integrations.quickbooks.companies" :key="company.id" class="p-4 rounded-lg flex items-center justify-between" style="background: var(--color-bg-secondary)">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--color-text-secondary)">{{ company.company_name }}</div>
                            <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                Realm ID: {{ company.realm_id }}
                                <span v-if="company.last_synced_at"> &bull; Last synced {{ company.last_synced_at }}</span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="ghost" @click="syncQuickBooks(company.id)" :loading="syncing[`qbo_sync_${company.id}`]">
                                Sync Data
                            </Button>
                            <Button size="sm" variant="danger" @click="disconnectQuickBooks(company.id)" :loading="syncing[`qbo_${company.id}`]">
                                Remove
                            </Button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button variant="primary" @click="connectQuickBooks">
                        {{ integrations.quickbooks.connected ? 'Add Another Company' : 'Connect QuickBooks' }}
                    </Button>
                    <button @click="toggleSetup('quickbooks')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'quickbooks' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'quickbooks'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">QuickBooks Developer Setup</h4>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Go to <a href="https://developer.intuit.com" target="_blank" class="text-accent underline">Intuit Developer Portal</a> and create an account</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Create a new app and select "QuickBooks Online and Payments"</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Under Keys & OAuth, set redirect URI: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/auth/quickbooks/callback</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Select scopes: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">com.intuit.quickbooks.accounting</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">5</span>
                            <span>Copy Client ID and Client Secret from the Development or Production keys</span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>QUICKBOOKS_CLIENT_ID=your-client-id</div>
                        <div>QUICKBOOKS_CLIENT_SECRET=your-client-secret</div>
                        <div>QUICKBOOKS_ENVIRONMENT=sandbox # or 'production'</div>
                    </div>
                </div>
            </div>

            <!-- LinkedIn Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: #0A66C2">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">LinkedIn</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Posts, Articles, Company Pages</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.linkedin.connected" class="badge badge-success">Connected</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                    </div>
                </div>

                <div v-if="integrations.linkedin.connected" class="mb-4 p-4 rounded-lg" style="background: var(--color-bg-secondary)">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-medium" style="color: var(--color-text-secondary)">{{ integrations.linkedin.name }}</div>
                            <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                {{ integrations.linkedin.headline }}
                            </div>
                            <div v-if="integrations.linkedin.organization_name" class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                <span class="px-1.5 py-0.5 rounded" style="background: var(--color-accent); color: white">Org</span>
                                {{ integrations.linkedin.organization_name }}
                            </div>
                            <div v-if="integrations.linkedin.expires_at" class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                Token expires {{ new Date(integrations.linkedin.expires_at).toLocaleDateString() }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button v-if="!integrations.linkedin.connected" variant="primary" @click="connectLinkedIn">
                        Connect LinkedIn
                    </Button>
                    <Button v-else variant="danger" @click="disconnectLinkedIn" :loading="syncing.linkedin">
                        Disconnect
                    </Button>
                    <button @click="toggleSetup('linkedin')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'linkedin' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'linkedin'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">LinkedIn Developer Setup</h4>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Go to <a href="https://developer.linkedin.com" target="_blank" class="text-accent underline">LinkedIn Developers</a> and create an app</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Request products: "Share on LinkedIn", "Sign In with LinkedIn using OpenID Connect"</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Under Auth, add OAuth 2.0 redirect URL: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/auth/linkedin/callback</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Copy Client ID and Client Secret from the app settings</span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>LINKEDIN_CLIENT_ID=your-client-id</div>
                        <div>LINKEDIN_CLIENT_SECRET=your-client-secret</div>
                    </div>
                </div>
            </div>

            <!-- X (Twitter) Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: #000">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">X (Twitter)</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Posts, Threads, Engagement</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.x.connected" class="badge badge-success">
                            {{ integrations.x.accounts?.length || 1 }} Account{{ (integrations.x.accounts?.length || 1) > 1 ? 's' : '' }}
                        </span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                    </div>
                </div>

                <!-- Connected Accounts List -->
                <div v-if="integrations.x.accounts?.length" class="space-y-3 mb-4">
                    <div
                        v-for="account in integrations.x.accounts"
                        :key="account.id"
                        class="p-4 rounded-lg flex items-center justify-between"
                        style="background: var(--color-bg-secondary)"
                    >
                        <div>
                            <div class="text-sm font-medium flex items-center gap-2" style="color: var(--color-text-secondary)">
                                @{{ account.username }}
                                <svg v-if="account.verified" class="w-4 h-4" viewBox="0 0 24 24" fill="#1D9BF0">
                                    <path d="M22.5 12.5c0-1.58-.875-2.95-2.148-3.6.154-.435.238-.905.238-1.4 0-2.21-1.71-3.998-3.818-3.998-.47 0-.92.084-1.336.25C14.818 2.415 13.51 1.5 12 1.5s-2.816.917-3.437 2.25c-.415-.165-.866-.25-1.336-.25-2.11 0-3.818 1.79-3.818 4 0 .494.083.964.237 1.4-1.272.65-2.147 2.018-2.147 3.6 0 1.495.782 2.798 1.942 3.486-.02.17-.032.34-.032.514 0 2.21 1.708 4 3.818 4 .47 0 .92-.086 1.335-.25.62 1.334 1.926 2.25 3.437 2.25 1.512 0 2.818-.916 3.437-2.25.415.163.865.248 1.336.248 2.11 0 3.818-1.79 3.818-4 0-.174-.012-.344-.033-.513 1.158-.687 1.943-1.99 1.943-3.484zm-6.616-3.334l-4.334 6.5c-.145.217-.382.334-.625.334-.143 0-.288-.04-.416-.126l-.115-.094-2.415-2.415c-.293-.293-.293-.768 0-1.06s.768-.294 1.06 0l1.77 1.767 3.825-5.74c.23-.345.696-.436 1.04-.207.346.23.44.696.21 1.04z"/>
                                </svg>
                                <span
                                    class="badge text-xs"
                                    :class="account.account_type === 'company' ? 'badge-blue' : 'badge-gray'"
                                >
                                    {{ account.account_type === 'company' ? 'Company' : 'Personal' }}
                                </span>
                            </div>
                            <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                {{ account.name }} · {{ account.followers_count?.toLocaleString() }} followers
                            </div>
                            <div v-if="account.expires_at" class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                Token expires {{ new Date(account.expires_at).toLocaleDateString() }}
                            </div>
                        </div>
                        <Button
                            variant="danger"
                            size="sm"
                            @click="disconnectX(account.id)"
                            :loading="syncing[`x_${account.id}`]"
                        >
                            Disconnect
                        </Button>
                    </div>
                </div>

                <!-- Add Account Buttons -->
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        v-if="!hasAccountType('personal')"
                        variant="primary"
                        @click="connectX('personal')"
                    >
                        {{ integrations.x.connected ? 'Add Personal Account' : 'Connect Personal' }}
                    </Button>
                    <Button
                        v-if="!hasAccountType('company')"
                        variant="secondary"
                        @click="connectX('company')"
                    >
                        {{ integrations.x.connected ? 'Add Company Account' : 'Connect Company' }}
                    </Button>
                    <button @click="toggleSetup('x')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'x' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'x'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">X Developer Setup</h4>
                    <div class="mb-3 p-3 rounded" style="background: rgba(234, 179, 8, 0.1); border: 1px solid rgba(234, 179, 8, 0.3)">
                        <p class="text-sm" style="color: var(--color-status-yellow)">
                            <strong>Note:</strong> X API requires a Developer Account. Basic tier ($100/mo) is needed for posting. Free tier is read-only.
                        </p>
                    </div>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Go to <a href="https://developer.x.com" target="_blank" class="text-accent underline">X Developer Portal</a> and create a project</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Create an app with "Read and write" permissions</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Enable OAuth 2.0 with "Confidential client" type</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Set callback URL: <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">{{ baseUrl }}/auth/x/callback</code></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">5</span>
                            <span>Copy Client ID and Client Secret from Keys and tokens</span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>X_CLIENT_ID=your-client-id</div>
                        <div>X_CLIENT_SECRET=your-client-secret</div>
                    </div>
                </div>
            </div>

            <!-- ClickUp Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #7B68EE, #49CCF9)">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">ClickUp</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">Tasks, Lists, Projects</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.clickup.connected" class="badge badge-success">{{ integrations.clickup.connections.length }} Workspace(s)</span>
                        <span v-else class="badge badge-gray">Not Connected</span>
                        <SyncStatusBadge v-if="integrations.clickup.connected" service="clickup" @complete="router.reload()" />
                    </div>
                </div>

                <div v-if="integrations.clickup.connections.length" class="mb-4 space-y-3">
                    <div v-for="connection in integrations.clickup.connections" :key="connection.id" class="p-4 rounded-lg" style="background: var(--color-bg-secondary)">
                        <div class="flex items-center justify-between mb-3">
                            <div class="text-sm font-medium" style="color: var(--color-text-primary)">{{ connection.workspace_name }}</div>
                            <div class="flex gap-2">
                                <Button size="sm" variant="ghost" @click="openClickUpConfig(connection)">
                                    Configure
                                </Button>
                                <Button size="sm" variant="ghost" @click="syncClickUp(connection.id)" :loading="syncing[`clickup_sync_${connection.id}`]">
                                    Sync
                                </Button>
                                <Button size="sm" variant="danger" @click="disconnectClickUp(connection.id)" :loading="syncing[`clickup_${connection.id}`]">
                                    Remove
                                </Button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--color-text-tertiary)">Client</label>
                                <select
                                    :value="connection.client_id"
                                    @change="updateClickUpClient(connection.id, ($event.target as HTMLSelectElement).value ? parseInt(($event.target as HTMLSelectElement).value) : null)"
                                    class="w-full px-3 py-1.5 rounded text-sm"
                                    style="background: var(--color-bg-primary); border: 1px solid var(--color-border-subtle); color: var(--color-text-primary)"
                                >
                                    <option :value="null">No client</option>
                                    <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--color-text-tertiary)">Status</label>
                                <div class="text-sm" style="color: var(--color-text-secondary)">
                                    {{ connection.sources_count }} source(s)
                                    <span v-if="connection.last_synced_at" class="text-xs" style="color: var(--color-text-quaternary)"> &bull; {{ connection.last_synced_at }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add ClickUp Form -->
                <div v-if="showClickUpForm" class="mb-4 p-4 rounded-lg border" style="background: var(--color-bg-secondary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3 text-sm" style="color: var(--color-text-primary)">Connect ClickUp Workspace</h4>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--color-text-tertiary)">Personal API Token</label>
                            <input v-model="clickUpForm.access_token" type="password" placeholder="pk_..." class="w-full px-3 py-2 rounded-lg text-sm" style="background: var(--color-bg-primary); border: 1px solid var(--color-border-subtle); color: var(--color-text-primary)">
                        </div>
                        <div class="flex gap-2">
                            <Button variant="primary" @click="connectClickUp" :loading="syncing.clickup">Connect</Button>
                            <Button variant="ghost" @click="showClickUpForm = false">Cancel</Button>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button v-if="!showClickUpForm" variant="primary" @click="showClickUpForm = true">
                        {{ integrations.clickup.connected ? 'Add Another Workspace' : 'Connect ClickUp' }}
                    </Button>
                    <button @click="toggleSetup('clickup')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'clickup' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'clickup'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">ClickUp API Token Setup</h4>
                    <div class="mb-3 p-3 rounded" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3)">
                        <p class="text-sm" style="color: var(--color-accent)">
                            <strong>Note:</strong> ClickUp uses Personal API Tokens (no OAuth needed). This works even for workspaces where you're a guest.
                        </p>
                    </div>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Click your avatar (top-right) in ClickUp</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Go to <strong>Settings</strong></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Click <strong>Apps</strong> in the left sidebar</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Click <strong>Generate</strong> under API Token (or copy existing)</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">5</span>
                            <span>Paste the token (starts with <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">pk_</code>) above</span>
                        </li>
                    </ol>
                </div>
            </div>

            <!-- SpinupWP Integration -->
            <div class="surface-elevated p-6 mb-4">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #F97316, #EA580C)">
                            <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                <line x1="8" y1="21" x2="16" y2="21"/>
                                <line x1="12" y1="17" x2="12" y2="21"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">SpinupWP</h3>
                            <p class="text-sm" style="color: var(--color-text-tertiary)">WordPress Hosting & Provisioning</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="integrations.spinupwp.configured && integrations.spinupwp.connected" class="badge badge-success">{{ integrations.spinupwp.servers.length }} Server(s)</span>
                        <span v-else-if="integrations.spinupwp.configured" class="badge badge-warning">No Servers</span>
                        <span v-else class="badge badge-gray">Not Configured</span>
                    </div>
                </div>

                <div v-if="integrations.spinupwp.servers.length" class="mb-4 space-y-3">
                    <div v-for="server in integrations.spinupwp.servers" :key="server.id" class="p-4 rounded-lg" style="background: var(--color-bg-secondary)">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <div class="text-sm font-medium" style="color: var(--color-text-primary)">{{ server.name }}</div>
                                <span v-if="server.is_default" class="badge badge-success text-xs">Default</span>
                                <span v-if="server.connection_status === 'connected'" class="badge badge-success text-xs">Connected</span>
                                <span v-else class="badge badge-warning text-xs">{{ server.connection_status }}</span>
                            </div>
                            <div class="flex gap-2">
                                <Button v-if="!server.is_default && server.connection_status === 'connected'" size="sm" variant="ghost" @click="setDefaultSpinupServer(server.id)" :loading="syncing[`spinupwp_default_${server.id}`]">
                                    Set Default
                                </Button>
                                <Button size="sm" variant="ghost" @click="refreshSpinupServer(server.id)" :loading="syncing[`spinupwp_refresh_${server.id}`]">
                                    Refresh
                                </Button>
                                <Button size="sm" variant="danger" @click="removeSpinupServer(server.id)" :loading="syncing[`spinupwp_remove_${server.id}`]">
                                    Remove
                                </Button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 text-sm" style="color: var(--color-text-secondary)">
                            <div>
                                <span class="text-xs" style="color: var(--color-text-tertiary)">IP Address:</span>
                                <span class="ml-1">{{ server.ip_address || 'N/A' }}</span>
                            </div>
                            <div>
                                <span class="text-xs" style="color: var(--color-text-tertiary)">Provider:</span>
                                <span class="ml-1">{{ server.provider_name || 'N/A' }}</span>
                            </div>
                            <div>
                                <span class="text-xs" style="color: var(--color-text-tertiary)">Sites:</span>
                                <span class="ml-1">{{ server.sites_count }}</span>
                            </div>
                            <div v-if="server.disk_usage_percent !== null">
                                <span class="text-xs" style="color: var(--color-text-tertiary)">Disk:</span>
                                <span class="ml-1">{{ server.disk_usage_percent }}% used</span>
                                <span v-if="server.disk_available_gb" class="text-xs" style="color: var(--color-text-quaternary)"> ({{ server.disk_available_gb }} GB free)</span>
                            </div>
                        </div>
                        <div v-if="server.last_synced_at" class="mt-2 text-xs" style="color: var(--color-text-quaternary)">
                            Last synced {{ server.last_synced_at }}
                        </div>
                    </div>
                </div>

                <div v-else-if="integrations.spinupwp.configured" class="mb-4 p-4 rounded-lg" style="background: var(--color-bg-secondary)">
                    <p class="text-sm" style="color: var(--color-text-secondary)">
                        No servers synced yet. Click "Sync Servers" to fetch your SpinupWP servers.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <Button v-if="integrations.spinupwp.configured" variant="primary" @click="syncSpinupWp" :loading="syncing.spinupwp">
                        Sync Servers
                    </Button>
                    <button @click="toggleSetup('spinupwp')" class="text-sm" style="color: var(--color-accent)">
                        {{ expandedSetup === 'spinupwp' ? 'Hide' : 'Show' }} Setup Guide
                    </button>
                </div>

                <div v-if="expandedSetup === 'spinupwp'" class="mt-4 p-4 rounded-lg border" style="background: var(--color-bg-tertiary); border-color: var(--color-border-subtle)">
                    <h4 class="font-semibold mb-3" style="color: var(--color-text-primary)">SpinupWP API Setup</h4>
                    <div class="mb-3 p-3 rounded" style="background: rgba(249, 115, 22, 0.1); border: 1px solid rgba(249, 115, 22, 0.3)">
                        <p class="text-sm" style="color: #F97316">
                            <strong>Note:</strong> SpinupWP uses a personal API token (no OAuth). Get your token from the SpinupWP dashboard.
                        </p>
                    </div>
                    <ol class="space-y-3 text-sm" style="color: var(--color-text-secondary)">
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">1</span>
                            <span>Log in to <a href="https://spinupwp.app" target="_blank" class="text-accent underline">SpinupWP</a></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">2</span>
                            <span>Go to <strong>Account Settings</strong> &rarr; <strong>API</strong></span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">3</span>
                            <span>Create a new API token or use an existing one</span>
                        </li>
                        <li class="flex gap-2">
                            <span class="font-mono text-xs px-2 py-0.5 rounded" style="background: var(--color-accent); color: white">4</span>
                            <span>Add the token to your <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">.env</code> file</span>
                        </li>
                    </ol>
                    <div class="mt-4 p-3 rounded font-mono text-xs" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                        <div class="mb-1"># Add to .env</div>
                        <div>SPINUPWP_API_TOKEN=your-api-token</div>
                        <div>SPINUPWP_DEFAULT_SERVER_ID=123  # optional</div>
                        <div>SPINUPWP_STAGING_DOMAIN=staging.example.com  # optional</div>
                    </div>
                </div>
            </div>

            <!-- Environment Variables Summary -->
            <div class="surface-elevated p-6">
                <h3 class="text-lg font-semibold mb-4" style="color: var(--color-text-primary)">All Environment Variables</h3>
                <p class="text-sm mb-4" style="color: var(--color-text-tertiary)">Add these to your <code class="px-1 py-0.5 rounded" style="background: var(--color-bg-secondary)">.env</code> file:</p>
                <div class="p-4 rounded font-mono text-xs overflow-x-auto" style="background: var(--color-bg-primary); color: var(--color-text-tertiary)">
                    <pre class="whitespace-pre-wrap"># Google Workspace
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI={{ baseUrl }}/auth/google/callback

# Slack
SLACK_CLIENT_ID=
SLACK_CLIENT_SECRET=
SLACK_SIGNING_SECRET=
SLACK_REDIRECT_URI={{ baseUrl }}/auth/slack/callback

# GitHub App (for org access)
GITHUB_APP_ID=
GITHUB_APP_SLUG=
GITHUB_APP_PRIVATE_KEY=
GITHUB_WEBHOOK_SECRET=

# GitHub User OAuth (for personal access)
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_REDIRECT_URI={{ baseUrl }}/auth/github/user/callback

# Harvest
HARVEST_CLIENT_ID=
HARVEST_CLIENT_SECRET=
HARVEST_REDIRECT_URI={{ baseUrl }}/auth/harvest/callback

# Notion
NOTION_CLIENT_ID=
NOTION_CLIENT_SECRET=
NOTION_REDIRECT_URI={{ baseUrl }}/auth/notion/callback

# QuickBooks
QUICKBOOKS_CLIENT_ID=
QUICKBOOKS_CLIENT_SECRET=
QUICKBOOKS_ENVIRONMENT=sandbox

# LinkedIn
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=
LINKEDIN_REDIRECT_URI={{ baseUrl }}/auth/linkedin/callback

# X (Twitter)
X_CLIENT_ID=
X_CLIENT_SECRET=
X_REDIRECT_URI={{ baseUrl }}/auth/x/callback

# SpinupWP (WordPress Hosting)
SPINUPWP_API_TOKEN=
SPINUPWP_DEFAULT_SERVER_ID=  # optional
SPINUPWP_STAGING_DOMAIN=  # optional, e.g. staging.example.com</pre>
                </div>
            </div>
        </div>

        <!-- ClickUp Configuration Modal -->
        <Modal :show="showClickUpConfig" @close="closeClickUpConfig" maxWidth="2xl">
            <div class="p-6">
                <h2 class="text-lg font-semibold mb-4" style="color: var(--color-text-primary)">
                    Configure {{ configConnection?.workspace_name }}
                </h2>

                <div v-if="loadingStructure" class="py-8 text-center" style="color: var(--color-text-tertiary)">
                    Loading ClickUp structure...
                </div>

                <div v-else class="grid grid-cols-2 gap-6">
                    <!-- Left: ClickUp Structure -->
                    <div>
                        <h3 class="text-sm font-medium mb-3" style="color: var(--color-text-secondary)">Select a List to Sync</h3>
                        <div class="max-h-80 overflow-y-auto space-y-2 pr-2">
                            <div v-for="space in clickUpStructure" :key="space.id" class="space-y-1">
                                <div class="text-xs font-semibold uppercase tracking-wide px-2 py-1" style="color: var(--color-text-tertiary)">
                                    {{ space.name }}
                                </div>

                                <!-- Folders -->
                                <div v-for="folder in space.folders" :key="folder.id" class="ml-2">
                                    <div class="text-xs px-2 py-0.5" style="color: var(--color-text-quaternary)">📁 {{ folder.name }}</div>
                                    <button
                                        v-for="list in folder.lists"
                                        :key="list.id"
                                        @click="selectList(list)"
                                        :class="['w-full text-left px-3 py-2 rounded text-sm ml-2', selectedList?.id === list.id ? 'ring-2 ring-offset-1' : '']"
                                        :style="{
                                            background: selectedList?.id === list.id ? 'var(--color-accent-subtle)' : 'var(--color-bg-tertiary)',
                                            color: 'var(--color-text-primary)',
                                            '--tw-ring-color': 'var(--color-accent)'
                                        }"
                                    >
                                        {{ list.name }}
                                        <span class="text-xs ml-1" style="color: var(--color-text-quaternary)">({{ list.task_count }} tasks)</span>
                                    </button>
                                </div>

                                <!-- Folderless Lists -->
                                <button
                                    v-for="list in space.lists"
                                    :key="list.id"
                                    @click="selectList(list)"
                                    :class="['w-full text-left px-3 py-2 rounded text-sm ml-2', selectedList?.id === list.id ? 'ring-2 ring-offset-1' : '']"
                                    :style="{
                                        background: selectedList?.id === list.id ? 'var(--color-accent-subtle)' : 'var(--color-bg-tertiary)',
                                        color: 'var(--color-text-primary)',
                                        '--tw-ring-color': 'var(--color-accent)'
                                    }"
                                >
                                    {{ list.name }}
                                    <span class="text-xs ml-1" style="color: var(--color-text-quaternary)">({{ list.task_count }} tasks)</span>
                                </button>
                            </div>

                            <div v-if="clickUpStructure.length === 0" class="text-sm py-4 text-center" style="color: var(--color-text-quaternary)">
                                No spaces found in this workspace
                            </div>
                        </div>
                    </div>

                    <!-- Right: Project Mapping -->
                    <div>
                        <h3 class="text-sm font-medium mb-3" style="color: var(--color-text-secondary)">Map to Project</h3>

                        <div v-if="selectedList" class="space-y-4">
                            <div class="p-3 rounded" style="background: var(--color-bg-tertiary)">
                                <div class="text-sm font-medium" style="color: var(--color-text-primary)">{{ selectedList.name }}</div>
                                <div class="text-xs mt-1" style="color: var(--color-text-quaternary)">Tasks from this list will sync to the selected project</div>
                            </div>

                            <div>
                                <label class="block text-xs mb-1" style="color: var(--color-text-tertiary)">Target Project</label>
                                <select
                                    v-model="selectedProjectId"
                                    class="w-full px-3 py-2 rounded text-sm"
                                    style="background: var(--color-bg-primary); border: 1px solid var(--color-border-subtle); color: var(--color-text-primary)"
                                >
                                    <option :value="null">Select a project...</option>
                                    <option v-for="project in filteredProjects" :key="project.id" :value="project.id">
                                        {{ project.name }}
                                        <template v-if="project.client"> ({{ project.client.name }})</template>
                                    </option>
                                </select>
                                <p v-if="configConnection?.client_id" class="text-xs mt-1" style="color: var(--color-text-quaternary)">
                                    Showing projects for {{ configConnection.client_name }}
                                </p>
                            </div>

                            <Button variant="primary" @click="addSource" :loading="syncing.addSource" :disabled="!selectedProjectId">
                                Add Sync Source
                            </Button>
                        </div>

                        <div v-else class="py-8 text-center text-sm" style="color: var(--color-text-quaternary)">
                            Select a list from the left to configure sync
                        </div>
                    </div>
                </div>

                <div class="flex justify-end mt-6 pt-4 border-t" style="border-color: var(--color-border-subtle)">
                    <Button variant="ghost" @click="closeClickUpConfig">Close</Button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 6px;
}

.badge-success {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
    border: 1px solid rgba(34, 197, 94, 0.2);
}

.badge-gray {
    background: var(--color-bg-tertiary);
    color: var(--color-text-quaternary);
    border: 1px solid var(--color-border-subtle);
}

.text-accent {
    color: var(--color-accent);
}

code {
    font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
}

pre {
    font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
}
</style>
