<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import FormToggle from '@/Components/FormToggle.vue';

interface VaultSecret {
    id: number;
    name: string;
    key: string;
    value?: string;
    type: 'api_key' | 'oauth_token' | 'password' | 'certificate' | 'other';
    service: string;
    description?: string;
    last_rotated: string | null;
    expires_at: string | null;
    is_expiring_soon: boolean;
    created_at: string;
    access_log?: Array<{
        action: string;
        by: string;
        at: string;
        ip?: string;
    }>;
}

interface Stats {
    total: number;
    expiring_soon: number;
    types: Record<string, number>;
}

const props = defineProps<{
    secrets: VaultSecret[];
    stats: Stats;
}>();

// Filter state
const filters = reactive({
    search: '',
    type: 'all',
    status: 'all',
});

// Modal states
const showAddModal = ref(false);
const showEditModal = ref(false);
const showViewModal = ref(false);
const showRotateModal = ref(false);
const showDeleteModal = ref(false);

const selectedSecret = ref<VaultSecret | null>(null);
const isSubmitting = ref(false);
const copiedId = ref<number | null>(null);
const revealedValue = ref(false);
const isLoadingValue = ref(false);

// Form state
const secretForm = reactive({
    name: '',
    key: '',
    value: '',
    type: 'api_key' as VaultSecret['type'],
    service: '',
    description: '',
    expires_at: '',
    auto_rotate: false,
    rotate_interval_days: 90,
});

// Rotate form state
const rotateForm = reactive({
    new_value: '',
    auto_generate: false,
    notify_agents: true,
});

// Computed filtered secrets
const filteredSecrets = computed(() => {
    return props.secrets.filter(s => {
        if (filters.type !== 'all' && s.type !== filters.type) return false;
        if (filters.status === 'expiring' && !s.is_expiring_soon) return false;
        if (filters.status === 'active' && s.is_expiring_soon) return false;
        if (filters.search) {
            const search = filters.search.toLowerCase();
            return s.name.toLowerCase().includes(search) ||
                   s.service.toLowerCase().includes(search) ||
                   s.key.toLowerCase().includes(search);
        }
        return true;
    });
});

// Get unique services for reference
const uniqueServices = computed(() => {
    return [...new Set(props.secrets.map(s => s.service))];
});

// Reset form
const resetForm = () => {
    secretForm.name = '';
    secretForm.key = '';
    secretForm.value = '';
    secretForm.type = 'api_key';
    secretForm.service = '';
    secretForm.description = '';
    secretForm.expires_at = '';
    secretForm.auto_rotate = false;
    secretForm.rotate_interval_days = 90;
};

// Open add modal
const openAdd = () => {
    resetForm();
    showAddModal.value = true;
};

// Open edit modal
const openEdit = (secret: VaultSecret) => {
    selectedSecret.value = secret;
    secretForm.name = secret.name;
    secretForm.key = secret.key;
    secretForm.value = '';
    secretForm.type = secret.type;
    secretForm.service = secret.service;
    secretForm.description = secret.description || '';
    secretForm.expires_at = secret.expires_at || '';
    showEditModal.value = true;
};

// Open view modal
const openView = (secret: VaultSecret, pushState = true) => {
    selectedSecret.value = secret;
    revealedValue.value = false;
    showViewModal.value = true;
    if (pushState) {
        history.pushState({ modal: 'secret', secretId: secret.id }, '', `#secret-${secret.id}`);
    }
};

const closeViewModal = (goBack = false) => {
    showViewModal.value = false;
    selectedSecret.value = null;
    revealedValue.value = false;
    if (goBack && window.location.hash.startsWith('#secret-')) {
        history.back();
    } else if (window.location.hash.startsWith('#secret-')) {
        history.replaceState({}, '', window.location.pathname);
    }
};

// Open rotate modal
const openRotate = (secret: VaultSecret) => {
    selectedSecret.value = secret;
    rotateForm.new_value = '';
    rotateForm.auto_generate = false;
    rotateForm.notify_agents = true;
    showRotateModal.value = true;
};

// Open delete modal
const openDelete = (secret: VaultSecret) => {
    selectedSecret.value = secret;
    showDeleteModal.value = true;
};

// Submit new secret
const submitAdd = () => {
    isSubmitting.value = true;
    router.post('/vault', {
        name: secretForm.name,
        key: secretForm.key,
        value: secretForm.value,
        type: secretForm.type,
        service: secretForm.service,
        description: secretForm.description,
        expires_at: secretForm.expires_at || null,
        auto_rotate: secretForm.auto_rotate,
        rotate_interval_days: secretForm.auto_rotate ? secretForm.rotate_interval_days : null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            resetForm();
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Submit edit
const submitEdit = () => {
    if (!selectedSecret.value) return;
    isSubmitting.value = true;
    router.put(`/vault/${selectedSecret.value.id}`, {
        name: secretForm.name,
        type: secretForm.type,
        service: secretForm.service,
        description: secretForm.description,
        expires_at: secretForm.expires_at || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            selectedSecret.value = null;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Submit rotation
const submitRotate = () => {
    if (!selectedSecret.value) return;
    isSubmitting.value = true;
    router.post(`/vault/${selectedSecret.value.id}/rotate`, {
        new_value: rotateForm.auto_generate ? null : rotateForm.new_value,
        auto_generate: rotateForm.auto_generate,
        notify_agents: rotateForm.notify_agents,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showRotateModal.value = false;
            selectedSecret.value = null;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Confirm delete
const confirmDelete = () => {
    if (!selectedSecret.value) return;
    isSubmitting.value = true;
    router.delete(`/vault/${selectedSecret.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteModal.value = false;
            selectedSecret.value = null;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Copy secret to clipboard
const copyToClipboard = async (secret: VaultSecret) => {
    try {
        // In real app, this would fetch the actual value from server
        await navigator.clipboard.writeText(secret.key);
        copiedId.value = secret.id;
        setTimeout(() => {
            copiedId.value = null;
        }, 2000);

        // Log access
        router.post(`/vault/${secret.id}/log-access`, {
            action: 'copy',
        }, {
            preserveScroll: true,
            preserveState: true,
        });
    } catch (err) {
        console.error('Failed to copy:', err);
    }
};

// Reveal secret value in view modal
const revealValue = () => {
    if (!selectedSecret.value) return;
    isLoadingValue.value = true;

    // In real app, this would fetch the actual decrypted value
    router.get(`/vault/${selectedSecret.value.id}/reveal`, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page: any) => {
            revealedValue.value = true;
        },
        onFinish: () => {
            isLoadingValue.value = false;
        },
    });
};

// Generate random value for new secret
const generateValue = () => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    let result = '';
    for (let i = 0; i < 32; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    secretForm.value = result;
};

// Get type badge class
const getTypeBadge = (type: string) => {
    const badges: Record<string, string> = {
        api_key: 'type-api',
        oauth_token: 'type-oauth',
        password: 'type-password',
        certificate: 'type-cert',
        other: 'type-other',
    };
    return badges[type] || 'type-other';
};

// Get type label
const getTypeLabel = (type: string) => {
    const labels: Record<string, string> = {
        api_key: 'API Key',
        oauth_token: 'OAuth',
        password: 'Password',
        certificate: 'Certificate',
        other: 'Other',
    };
    return labels[type] || 'Other';
};

// Mask key display
const maskKey = (key: string) => {
    if (key.length <= 8) return '••••••••';
    return key.slice(0, 4) + '••••' + key.slice(-4);
};

// Format type for display
const formatType = (type: string) => {
    return type.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
};

// Type options for select
const typeOptions = [
    { value: 'api_key', label: 'API Key' },
    { value: 'oauth_token', label: 'OAuth Token' },
    { value: 'password', label: 'Password' },
    { value: 'certificate', label: 'Certificate' },
    { value: 'other', label: 'Other' },
];

// History state management
const handleHashNavigation = () => {
    const hash = window.location.hash;
    if (hash.startsWith('#secret-')) {
        const secretId = parseInt(hash.replace('#secret-', ''), 10);
        const secret = props.secrets.find(s => s.id === secretId);
        if (secret && !showViewModal.value) {
            openView(secret, false);
        }
    } else if (showViewModal.value) {
        showViewModal.value = false;
        selectedSecret.value = null;
        revealedValue.value = false;
    }
};

const handlePopState = () => {
    handleHashNavigation();
};

onMounted(() => {
    handleHashNavigation();
    window.addEventListener('popstate', handlePopState);
});

onUnmounted(() => {
    window.removeEventListener('popstate', handlePopState);
});
</script>

<template>
    <AppLayout title="Vault">
        <!-- Stats -->
        <div class="stats-grid">
            <div class="metric-card">
                <div class="metric-label">TOTAL SECRETS</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">EXPIRING SOON</div>
                <div class="metric-row">
                    <span class="metric-value" :class="{ 'warning': stats.expiring_soon > 0 }">{{ stats.expiring_soon }}</span>
                    <div v-if="stats.expiring_soon > 0" class="status-dot"></div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">BY TYPE</div>
                <div class="type-badges">
                    <span v-for="(count, type) in stats.types" :key="type" class="type-count">
                        <span class="type-name">{{ formatType(type as string) }}</span>
                        <span class="type-num">{{ count }}</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="filters">
                <FormInput
                    v-model="filters.search"
                    placeholder="Search secrets..."
                    class="search-input"
                />
                <FormSelect
                    v-model="filters.type"
                    :options="[
                        { value: 'all', label: 'All Types' },
                        ...typeOptions,
                    ]"
                />
                <FormSelect
                    v-model="filters.status"
                    :options="[
                        { value: 'all', label: 'All Status' },
                        { value: 'active', label: 'Active' },
                        { value: 'expiring', label: 'Expiring Soon' },
                    ]"
                />
            </div>
            <button class="btn btn-primary" @click="openAdd">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Secret
            </button>
        </div>

        <!-- Secrets Table -->
        <div class="card">
            <div class="secrets-table">
                <div class="table-head">
                    <div class="col-secret">Secret</div>
                    <div class="col-type">Type</div>
                    <div class="col-service">Service</div>
                    <div class="col-rotated">Last Rotated</div>
                    <div class="col-status">Status</div>
                    <div class="col-actions">Actions</div>
                </div>

                <div
                    v-for="secret in filteredSecrets"
                    :key="secret.id"
                    class="table-row"
                    @click="openView(secret)"
                >
                    <div class="col-secret">
                        <div class="secret-icon">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                            </svg>
                        </div>
                        <div class="secret-info">
                            <span class="secret-name">{{ secret.name }}</span>
                            <span class="secret-key">{{ maskKey(secret.key) }}</span>
                        </div>
                    </div>

                    <div class="col-type">
                        <span :class="['type-badge', getTypeBadge(secret.type)]">
                            {{ getTypeLabel(secret.type) }}
                        </span>
                    </div>

                    <div class="col-service">
                        <span class="service-name">{{ secret.service }}</span>
                    </div>

                    <div class="col-rotated">
                        <span class="rotated-date">{{ secret.last_rotated || 'Never' }}</span>
                    </div>

                    <div class="col-status">
                        <span v-if="secret.is_expiring_soon" class="status-badge expiring">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            Expiring
                        </span>
                        <span v-else class="status-badge active">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Active
                        </span>
                    </div>

                    <div class="col-actions" @click.stop>
                        <button
                            class="action-btn"
                            :class="{ copied: copiedId === secret.id }"
                            title="Copy to clipboard"
                            @click="copyToClipboard(secret)"
                        >
                            <svg v-if="copiedId !== secret.id" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                        <button class="action-btn" title="Rotate secret" @click="openRotate(secret)">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                        </button>
                        <button class="action-btn" title="Edit" @click="openEdit(secret)">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                            </svg>
                        </button>
                        <button class="action-btn action-btn-danger" title="Delete" @click="openDelete(secret)">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div v-if="filteredSecrets.length === 0" class="empty-state">
                    <div class="empty-icon">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <p class="empty-title">{{ filters.search || filters.type !== 'all' || filters.status !== 'all' ? 'No secrets match your filters' : 'Vault is empty' }}</p>
                    <p class="empty-subtitle">{{ filters.search || filters.type !== 'all' || filters.status !== 'all' ? 'Try adjusting your filters' : 'Add your first secret to get started' }}</p>
                    <button v-if="!filters.search && filters.type === 'all' && filters.status === 'all'" class="btn btn-primary mt-4" @click="openAdd">
                        Add Secret
                    </button>
                </div>
            </div>
        </div>

        <!-- Add Secret Modal -->
        <Modal :show="showAddModal" size="md" @close="showAddModal = false">
            <template #header>
                <h2 class="modal-title">Add Secret</h2>
            </template>

            <form @submit.prevent="submitAdd">
                <div class="form-section">
                    <h4>Secret Details</h4>
                    <div class="form-row">
                        <FormInput
                            v-model="secretForm.name"
                            label="Name"
                            placeholder="e.g., OpenAI API Key"
                            required
                        />
                        <FormSelect
                            v-model="secretForm.type"
                            label="Type"
                            :options="typeOptions"
                        />
                    </div>
                    <FormInput
                        v-model="secretForm.service"
                        label="Service"
                        placeholder="e.g., OpenAI, Stripe, GitHub"
                        required
                    />
                </div>

                <div class="form-section">
                    <h4>Secret Value</h4>
                    <FormInput
                        v-model="secretForm.key"
                        label="Key / Identifier"
                        placeholder="e.g., OPENAI_API_KEY"
                        required
                    />
                    <div class="value-field">
                        <FormInput
                            v-model="secretForm.value"
                            label="Value"
                            type="password"
                            placeholder="Enter secret value"
                            required
                        />
                        <button type="button" class="generate-btn" @click="generateValue" title="Generate random value">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-section">
                    <h4>Expiration & Rotation</h4>
                    <FormInput
                        v-model="secretForm.expires_at"
                        label="Expires At (optional)"
                        type="date"
                    />
                    <FormToggle
                        v-model="secretForm.auto_rotate"
                        label="Auto-rotate"
                        description="Automatically rotate this secret on a schedule"
                    />
                    <div v-if="secretForm.auto_rotate" class="rotate-interval">
                        <FormInput
                            v-model.number="secretForm.rotate_interval_days"
                            label="Rotation Interval (days)"
                            type="number"
                            :min="1"
                            :max="365"
                        />
                    </div>
                </div>

                <div class="form-section">
                    <h4>Notes</h4>
                    <FormTextarea
                        v-model="secretForm.description"
                        label="Description (optional)"
                        placeholder="Additional notes about this secret..."
                        :rows="2"
                    />
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showAddModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSubmitting || !secretForm.name || !secretForm.key || !secretForm.value"
                        @click="submitAdd"
                    >
                        {{ isSubmitting ? 'Adding...' : 'Add Secret' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Edit Secret Modal -->
        <Modal :show="showEditModal" size="md" @close="showEditModal = false">
            <template #header>
                <h2 class="modal-title">Edit Secret</h2>
            </template>

            <form @submit.prevent="submitEdit">
                <div class="form-section">
                    <h4>Secret Details</h4>
                    <div class="form-row">
                        <FormInput
                            v-model="secretForm.name"
                            label="Name"
                            required
                        />
                        <FormSelect
                            v-model="secretForm.type"
                            label="Type"
                            :options="typeOptions"
                        />
                    </div>
                    <FormInput
                        v-model="secretForm.service"
                        label="Service"
                        required
                    />
                </div>

                <div class="form-section">
                    <h4>Expiration</h4>
                    <FormInput
                        v-model="secretForm.expires_at"
                        label="Expires At (optional)"
                        type="date"
                    />
                </div>

                <div class="form-section">
                    <h4>Notes</h4>
                    <FormTextarea
                        v-model="secretForm.description"
                        label="Description (optional)"
                        :rows="2"
                    />
                </div>

                <div class="edit-note">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>To change the secret value, use the Rotate function instead.</span>
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showEditModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSubmitting || !secretForm.name"
                        @click="submitEdit"
                    >
                        {{ isSubmitting ? 'Saving...' : 'Save Changes' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- View Secret Modal -->
        <Modal :show="showViewModal" size="md" @close="closeViewModal(true)">
            <template #header>
                <div class="view-header">
                    <h2 class="modal-title">{{ selectedSecret?.name }}</h2>
                    <span :class="['type-badge', getTypeBadge(selectedSecret?.type || 'other')]">
                        {{ getTypeLabel(selectedSecret?.type || 'other') }}
                    </span>
                </div>
            </template>

            <div v-if="selectedSecret" class="view-content">
                <div class="view-section">
                    <div class="view-grid">
                        <div class="view-item">
                            <span class="view-label">Service</span>
                            <span class="view-value">{{ selectedSecret.service }}</span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">Created</span>
                            <span class="view-value">{{ selectedSecret.created_at }}</span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">Last Rotated</span>
                            <span class="view-value">{{ selectedSecret.last_rotated || 'Never' }}</span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">Expires</span>
                            <span class="view-value" :class="{ expiring: selectedSecret.is_expiring_soon }">
                                {{ selectedSecret.expires_at || 'Never' }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="view-section">
                    <h4>Secret Value</h4>
                    <div class="secret-value-display">
                        <div class="value-box">
                            <span class="key-label">{{ selectedSecret.key }}</span>
                            <code v-if="revealedValue" class="revealed-value">{{ selectedSecret.value || '••••••••••••••••' }}</code>
                            <code v-else class="masked-value">••••••••••••••••••••••••••••••••</code>
                        </div>
                        <div class="value-actions">
                            <button
                                class="btn btn-sm btn-secondary"
                                @click="revealValue"
                                :disabled="isLoadingValue || revealedValue"
                            >
                                {{ isLoadingValue ? 'Loading...' : (revealedValue ? 'Revealed' : 'Reveal') }}
                            </button>
                            <button class="btn btn-sm btn-secondary" @click="copyToClipboard(selectedSecret)">
                                {{ copiedId === selectedSecret.id ? 'Copied!' : 'Copy' }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="selectedSecret.description" class="view-section">
                    <h4>Description</h4>
                    <p class="description-text">{{ selectedSecret.description }}</p>
                </div>

                <div v-if="selectedSecret.access_log && selectedSecret.access_log.length > 0" class="view-section">
                    <h4>Recent Access</h4>
                    <div class="access-log">
                        <div v-for="(log, index) in selectedSecret.access_log.slice(0, 5)" :key="index" class="log-item">
                            <span class="log-action">{{ log.action }}</span>
                            <span class="log-meta">by {{ log.by }} · {{ log.at }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showViewModal = false">Close</button>
                    <button type="button" class="btn btn-secondary" @click="openRotate(selectedSecret!)">Rotate</button>
                    <button type="button" class="btn btn-primary" @click="openEdit(selectedSecret!)">Edit</button>
                </div>
            </template>
        </Modal>

        <!-- Rotate Secret Modal -->
        <Modal :show="showRotateModal" size="sm" @close="showRotateModal = false">
            <template #header>
                <h2 class="modal-title">Rotate Secret</h2>
            </template>

            <div class="rotate-content">
                <p class="rotate-description">
                    Rotate <strong>{{ selectedSecret?.name }}</strong>? This will update the secret value and notify all agents using it.
                </p>

                <FormToggle
                    v-model="rotateForm.auto_generate"
                    label="Auto-generate new value"
                    description="Generate a secure random value"
                />

                <FormInput
                    v-if="!rotateForm.auto_generate"
                    v-model="rotateForm.new_value"
                    label="New Value"
                    type="password"
                    placeholder="Enter new secret value"
                    required
                />

                <FormToggle
                    v-model="rotateForm.notify_agents"
                    label="Notify agents"
                    description="Send notification to agents using this secret"
                />

                <div class="rotate-warning">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>The old value will be permanently replaced.</span>
                </div>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showRotateModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSubmitting || (!rotateForm.auto_generate && !rotateForm.new_value)"
                        @click="submitRotate"
                    >
                        {{ isSubmitting ? 'Rotating...' : 'Rotate Secret' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" size="sm" @close="showDeleteModal = false">
            <template #header>
                <h2 class="modal-title">Delete Secret</h2>
            </template>

            <div class="delete-content">
                <div class="delete-icon">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p class="delete-description">
                    Delete <strong>{{ selectedSecret?.name }}</strong>?
                </p>
                <p class="delete-warning-text">
                    This secret will be permanently removed. Any agents or services using this secret will stop working.
                </p>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showDeleteModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isSubmitting"
                        @click="confirmDelete"
                    >
                        {{ isSubmitting ? 'Deleting...' : 'Delete Secret' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Stats */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.metric-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 1rem 1.25rem;
}

.metric-label {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.25rem;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.metric-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.warning {
    color: var(--color-status-yellow);
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--color-status-yellow);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.type-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.type-count {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
}

.type-name {
    color: var(--color-text-secondary);
}

.type-num {
    color: var(--color-text-primary);
    font-weight: 600;
    font-family: 'SF Mono', monospace;
}

/* Toolbar */
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.filters {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    flex: 1;
}

.filters :deep(.form-group) {
    margin-bottom: 0;
}

.search-input :deep(input) {
    min-width: 200px;
}

/* Card */
.card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
}

/* Secrets Table */
.secrets-table {
    display: flex;
    flex-direction: column;
}

.table-head {
    display: grid;
    grid-template-columns: 2fr 100px 120px 120px 100px 140px;
    gap: 0.75rem;
    padding: 0.75rem 1.25rem;
    background: var(--color-bg-elevated);
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.table-row {
    display: grid;
    grid-template-columns: 2fr 100px 120px 120px 100px 140px;
    gap: 0.75rem;
    padding: 0.875rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
    align-items: center;
    transition: background 0.15s ease;
    cursor: pointer;
}

.table-row:hover {
    background: var(--color-bg-elevated);
}

.table-row:last-child {
    border-bottom: none;
}

/* Secret Column */
.col-secret {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.secret-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--color-bg-elevated);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text-tertiary);
    flex-shrink: 0;
}

.secret-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.secret-name {
    font-weight: 500;
    color: var(--color-text-primary);
    font-size: 0.875rem;
}

.secret-key {
    font-family: 'SF Mono', monospace;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

/* Type Badge */
.type-badge {
    display: inline-flex;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.6875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.type-api {
    background: rgba(59, 130, 246, 0.15);
    color: var(--color-status-blue);
}

.type-oauth {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.type-password {
    background: rgba(234, 179, 8, 0.15);
    color: var(--color-status-yellow);
}

.type-cert {
    background: rgba(139, 92, 246, 0.15);
    color: var(--color-accent);
}

.type-other {
    background: var(--color-bg-elevated);
    color: var(--color-text-secondary);
}

/* Service */
.service-name {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
}

/* Rotated Date */
.rotated-date {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    font-family: 'SF Mono', monospace;
}

/* Status Badge */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.6875rem;
    font-weight: 500;
}

.status-badge.active {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.status-badge.expiring {
    background: rgba(234, 179, 8, 0.15);
    color: var(--color-status-yellow);
}

/* Actions */
.col-actions {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: var(--color-text-tertiary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}

.action-btn:hover {
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
}

.action-btn.copied {
    color: var(--color-status-green);
}

.action-btn-danger:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
}

/* Empty State */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem;
}

.empty-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    background: var(--color-bg-elevated);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text-tertiary);
    margin-bottom: 1rem;
}

.empty-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.25rem;
}

.empty-subtitle {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

/* Modal Styles */
.modal-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.form-section {
    margin-bottom: 1.5rem;
}

.form-section:last-child {
    margin-bottom: 0;
}

.form-section h4 {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

/* Value Field with Generate Button */
.value-field {
    position: relative;
}

.value-field :deep(.form-group) {
    margin-bottom: 0;
}

.generate-btn {
    position: absolute;
    right: 0.5rem;
    top: 2rem;
    padding: 0.375rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    color: var(--color-text-tertiary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.generate-btn:hover {
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    border-color: var(--color-border-hover);
}

.rotate-interval {
    margin-top: 1rem;
}

.edit-note {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    background: var(--color-bg-elevated);
    border-radius: 6px;
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

/* View Modal */
.view-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.view-content {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.view-section h4 {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.75rem;
}

.view-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.view-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.view-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.view-value {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    font-weight: 500;
}

.view-value.expiring {
    color: var(--color-status-yellow);
}

/* Secret Value Display */
.secret-value-display {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.value-box {
    background: var(--color-bg-elevated);
    border-radius: 8px;
    padding: 1rem;
}

.key-label {
    display: block;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-bottom: 0.5rem;
}

.revealed-value,
.masked-value {
    font-family: 'SF Mono', monospace;
    font-size: 0.875rem;
    color: var(--color-text-primary);
    word-break: break-all;
}

.masked-value {
    color: var(--color-text-tertiary);
}

.value-actions {
    display: flex;
    gap: 0.5rem;
}

.description-text {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    line-height: 1.6;
}

/* Access Log */
.access-log {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.log-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
}

.log-action {
    font-weight: 500;
    color: var(--color-text-primary);
    text-transform: capitalize;
}

.log-meta {
    color: var(--color-text-tertiary);
}

/* Rotate Modal */
.rotate-content {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.rotate-description {
    font-size: 0.9375rem;
    color: var(--color-text-primary);
}

.rotate-warning {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    background: rgba(234, 179, 8, 0.1);
    border-radius: 6px;
    font-size: 0.8125rem;
    color: var(--color-status-yellow);
}

/* Delete Modal */
.delete-content {
    text-align: center;
    padding: 1rem 0;
}

.delete-icon {
    color: var(--color-status-red);
    margin-bottom: 1rem;
    display: flex;
    justify-content: center;
}

.delete-description {
    font-size: 0.9375rem;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
}

.delete-warning-text {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

/* Modal Actions */
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    border: none;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-primary {
    background: var(--color-accent);
    color: white;
}

.btn-primary:hover:not(:disabled) {
    background: var(--color-accent-hover);
}

.btn-secondary {
    background: var(--color-bg-elevated);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border-default);
}

.btn-secondary:hover:not(:disabled) {
    background: var(--color-bg-surface);
    border-color: var(--color-border-hover);
}

.btn-danger {
    background: var(--color-status-red);
    color: white;
}

.btn-danger:hover:not(:disabled) {
    background: #dc2626;
}

.mt-4 {
    margin-top: 1rem;
}

/* Responsive */
@media (max-width: 1024px) {
    .table-head,
    .table-row {
        grid-template-columns: 1.5fr 80px 100px 100px 80px 120px;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .filters {
        flex-direction: column;
    }

    .table-head {
        display: none;
    }

    .table-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        padding: 1rem;
    }

    .col-secret {
        width: 100%;
    }

    .col-actions {
        width: 100%;
        justify-content: flex-end;
        margin-top: 0.5rem;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .view-grid {
        grid-template-columns: 1fr;
    }
}
</style>
