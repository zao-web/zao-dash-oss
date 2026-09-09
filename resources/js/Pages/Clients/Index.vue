<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import InlineSelect from '@/Components/InlineSelect.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';

interface Contact {
    id: number;
    name: string;
    email: string;
    phone?: string;
    role: string;
    is_primary: boolean;
}

interface Client {
    id: number;
    name: string;
    slug: string;
    description: string;
    health_score: number;
    status: string;
    website: string;
    slack_channel: string;
    industry?: string;
    address?: string;
    billing_email?: string;
    billing_cc_emails?: string;
    contacts: Contact[];
    projects_count: number;
    active_projects_count: number;
}

interface Stats {
    total: number;
    active: number;
    archived: number;
    avg_health: number;
}

const props = defineProps<{
    clients: Client[];
    stats: Stats;
    showArchived?: boolean;
    filters?: {
        search?: string;
        status?: string;
    };
}>();

// Search and filter state
const searchQuery = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || 'active');
const selectedClients = ref<number[]>([]);
const showBulkActions = ref(false);

// Debounce search
let searchTimeout: ReturnType<typeof setTimeout>;
const debouncedSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        applyFilters();
    }, 300);
};

watch(searchQuery, debouncedSearch);

// Apply filters
const applyFilters = () => {
    const params: Record<string, string | undefined> = {};
    if (searchQuery.value) params.search = searchQuery.value;
    if (statusFilter.value && statusFilter.value !== 'all') params.status = statusFilter.value;
    router.get('/clients', params, { preserveState: true, preserveScroll: true });
};

// Change status filter
const changeStatusFilter = (status: string) => {
    statusFilter.value = status;
    applyFilters();
};

// Toggle archived view (legacy - kept for backward compatibility)
const toggleArchived = () => {
    statusFilter.value = props.showArchived ? 'active' : 'archived';
    applyFilters();
};

// Filtered clients (client-side filtering for instant feedback)
const filteredClients = computed(() => {
    if (!searchQuery.value) return props.clients;
    const q = searchQuery.value.toLowerCase();
    return props.clients.filter(c => 
        c.name.toLowerCase().includes(q) ||
        c.slack_channel?.toLowerCase().includes(q) ||
        c.description?.toLowerCase().includes(q) ||
        c.contacts.some(contact => 
            contact.name.toLowerCase().includes(q) ||
            contact.email.toLowerCase().includes(q)
        )
    );
});

// Bulk selection
const toggleSelectAll = () => {
    if (selectedClients.value.length === filteredClients.value.length) {
        selectedClients.value = [];
    } else {
        selectedClients.value = filteredClients.value.map(c => c.id);
    }
};

const toggleSelect = (clientId: number, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    const idx = selectedClients.value.indexOf(clientId);
    if (idx >= 0) {
        selectedClients.value.splice(idx, 1);
    } else {
        selectedClients.value.push(clientId);
    }
};

const isSelected = (clientId: number) => selectedClients.value.includes(clientId);

// Bulk actions
const isBulkUpdating = ref(false);

const bulkUpdateStatus = (newStatus: string) => {
    if (selectedClients.value.length === 0) return;
    isBulkUpdating.value = true;
    router.post('/clients/bulk-update', {
        client_ids: selectedClients.value,
        status: newStatus,
    }, {
        onSuccess: () => {
            selectedClients.value = [];
            showBulkActions.value = false;
        },
        onFinish: () => isBulkUpdating.value = false,
    });
};

const statusFilterOptions = [
    { value: 'all', label: 'All Clients' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'prospect', label: 'Prospects' },
    { value: 'churned', label: 'Churned' },
    { value: 'archived', label: 'Archived' },
];

// Modal states
const showClientModal = ref(false);
const showDeleteModal = ref(false);
const editingClient = ref<Client | null>(null);
const clientToDelete = ref<Client | null>(null);
const isSaving = ref(false);
const isDeleting = ref(false);

// Client form
const clientForm = reactive({
    name: '',
    description: '',
    website: '',
    slack_channel: '',
    status: 'active',
    health_score: 8,
    industry: '',
    address: '',
    billing_email: '',
    billing_cc_emails: '',
    contacts: [] as Array<{
        id?: number;
        name: string;
        email: string;
        phone: string;
        role: string;
        is_primary: boolean;
    }>,
});

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'churned', label: 'Churned' },
    { value: 'prospect', label: 'Prospect' },
];

const industryOptions = [
    { value: '', label: 'Select industry...' },
    { value: 'technology', label: 'Technology' },
    { value: 'healthcare', label: 'Healthcare' },
    { value: 'finance', label: 'Finance' },
    { value: 'ecommerce', label: 'E-Commerce' },
    { value: 'education', label: 'Education' },
    { value: 'media', label: 'Media & Entertainment' },
    { value: 'manufacturing', label: 'Manufacturing' },
    { value: 'professional_services', label: 'Professional Services' },
    { value: 'nonprofit', label: 'Non-Profit' },
    { value: 'other', label: 'Other' },
];

const modalTitle = computed(() => editingClient.value ? 'Edit Client' : 'New Client');

const resetForm = () => {
    clientForm.name = '';
    clientForm.description = '';
    clientForm.website = '';
    clientForm.slack_channel = '';
    clientForm.status = 'active';
    clientForm.health_score = 8;
    clientForm.industry = '';
    clientForm.address = '';
    clientForm.billing_email = '';
    clientForm.billing_cc_emails = '';
    clientForm.contacts = [];
};

const openNewClient = () => {
    editingClient.value = null;
    resetForm();
    // Add a default primary contact
    addContact();
    showClientModal.value = true;
};

const openEditClient = (client: Client, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    editingClient.value = client;
    clientForm.name = client.name;
    clientForm.description = client.description || '';
    clientForm.website = client.website || '';
    clientForm.slack_channel = client.slack_channel || '';
    clientForm.status = client.status;
    clientForm.health_score = client.health_score;
    clientForm.industry = client.industry || '';
    clientForm.address = client.address || '';
    clientForm.billing_email = client.billing_email || '';
    clientForm.billing_cc_emails = client.billing_cc_emails || '';
    clientForm.contacts = client.contacts.map(c => ({
        id: c.id,
        name: c.name,
        email: c.email,
        phone: c.phone || '',
        role: c.role,
        is_primary: c.is_primary,
    }));
    if (clientForm.contacts.length === 0) {
        addContact();
    }
    showClientModal.value = true;
};

const closeClientModal = () => {
    showClientModal.value = false;
    editingClient.value = null;
    resetForm();
};

const addContact = () => {
    const isPrimary = clientForm.contacts.length === 0;
    clientForm.contacts.push({
        name: '',
        email: '',
        phone: '',
        role: '',
        is_primary: isPrimary,
    });
};

const removeContact = (index: number) => {
    const wasP = clientForm.contacts[index].is_primary;
    clientForm.contacts.splice(index, 1);
    // If removed was primary and others exist, make first one primary
    if (wasP && clientForm.contacts.length > 0) {
        clientForm.contacts[0].is_primary = true;
    }
};

const setPrimaryContact = (index: number) => {
    clientForm.contacts.forEach((c, i) => {
        c.is_primary = i === index;
    });
};

const saveClient = () => {
    isSaving.value = true;
    const data = { ...clientForm };

    if (editingClient.value) {
        router.put(`/clients/${editingClient.value.id}`, data, {
            onSuccess: () => closeClientModal(),
            onFinish: () => isSaving.value = false,
        });
    } else {
        router.post('/clients', data, {
            onSuccess: () => closeClientModal(),
            onFinish: () => isSaving.value = false,
        });
    }
};

const openDeleteModal = (client: Client, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    clientToDelete.value = client;
    showDeleteModal.value = true;
};

const confirmDelete = () => {
    if (!clientToDelete.value) return;
    isDeleting.value = true;
    router.delete(`/clients/${clientToDelete.value.id}`, {
        onSuccess: () => {
            showDeleteModal.value = false;
            clientToDelete.value = null;
        },
        onFinish: () => isDeleting.value = false,
    });
};

const getHealthClass = (score: number | string) => {
    const numScore = typeof score === 'string' ? parseFloat(score) : score;
    if (numScore >= 8) return 'high';
    if (numScore >= 6) return 'medium';
    return 'low';
};

const formatScore = (score: number | string) => {
    const numScore = typeof score === 'string' ? parseFloat(score) : score;
    return numScore.toFixed(1);
};

const getPrimaryContact = (contacts: Contact[]) => {
    return contacts.find(c => c.is_primary) || contacts[0];
};

const getInitials = (name: string) => {
    return name.split(' ').filter(w => /^[a-zA-Z]/.test(w)).map(w => w[0]).join('').slice(0, 2).toUpperCase();
};

const getStatusBadgeClass = (status: string) => {
    const classes: Record<string, string> = {
        active: 'badge-green',
        inactive: 'badge-gray',
        prospect: 'badge-blue',
        churned: 'badge-red',
        archived: 'badge-yellow',
    };
    return classes[status] || 'badge-gray';
};
</script>

<template>
    <AppLayout title="Clients">
        <!-- Header -->
        <div class="flex flex-col gap-4 mb-6">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Clients</h1>
                <button class="btn btn-primary" @click="openNewClient">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    New Client
                </button>
            </div>

            <!-- Search and Filters Row -->
            <div class="flex flex-col sm:flex-row gap-3">
                <!-- Search -->
                <div class="relative flex-1">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--color-text-tertiary)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search clients, contacts, channels..."
                        class="search-input"
                    />
                    <button
                        v-if="searchQuery"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)]"
                        @click="searchQuery = ''; applyFilters()"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Status Filter -->
                <div class="flex items-center gap-2">
                    <InlineSelect
                        v-model="statusFilter"
                        :options="statusFilterOptions"
                        @update:model-value="changeStatusFilter"
                    />

                    <!-- Bulk Edit Toggle -->
                    <button
                        class="btn btn-secondary btn-icon"
                        :class="{ 'btn-active': showBulkActions }"
                        @click="showBulkActions = !showBulkActions; selectedClients = []"
                        title="Bulk edit mode"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Bulk Actions Bar -->
            <div v-if="showBulkActions && selectedClients.length > 0" class="bulk-actions-bar">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium">{{ selectedClients.length }} selected</span>
                    <button class="btn btn-xs btn-secondary" @click="selectedClients = []">Clear</button>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-caption">Set status:</span>
                    <button
                        class="btn btn-xs btn-secondary"
                        :disabled="isBulkUpdating"
                        @click="bulkUpdateStatus('active')"
                    >Active</button>
                    <button
                        class="btn btn-xs btn-secondary"
                        :disabled="isBulkUpdating"
                        @click="bulkUpdateStatus('inactive')"
                    >Inactive</button>
                    <button
                        class="btn btn-xs btn-secondary"
                        :disabled="isBulkUpdating"
                        @click="bulkUpdateStatus('archived')"
                    >Archive</button>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
            <div class="metric-card">
                <div class="metric-label">TOTAL CLIENTS</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIVE</div>
                <div class="metric-value">{{ stats.active }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">AVG HEALTH</div>
                <div class="flex items-baseline gap-1">
                    <span class="metric-value">{{ Number(stats.avg_health || 0).toFixed(1) }}</span>
                    <span class="metric-suffix">/10</span>
                </div>
            </div>
        </div>

        <!-- Bulk Select All (when in bulk mode) -->
        <div v-if="showBulkActions" class="flex items-center gap-3 mb-4">
            <label class="bulk-select-all">
                <input
                    type="checkbox"
                    :checked="selectedClients.length === filteredClients.length && filteredClients.length > 0"
                    :indeterminate="selectedClients.length > 0 && selectedClients.length < filteredClients.length"
                    @change="toggleSelectAll"
                />
                <span>Select all ({{ filteredClients.length }})</span>
            </label>
        </div>

        <!-- Clients Grid -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="client in filteredClients"
                :key="client.id"
                class="card cursor-pointer transition-all hover:border-[var(--color-border-strong)] relative"
                :class="{ 'card-selected': isSelected(client.id) }"
                @click="showBulkActions ? toggleSelect(client.id, $event) : router.visit(`/clients/${client.slug}`)"
            >
                <!-- Bulk Select Checkbox -->
                <div v-if="showBulkActions" class="bulk-checkbox" @click.stop="toggleSelect(client.id, $event)">
                    <input
                        type="checkbox"
                        :checked="isSelected(client.id)"
                        @click.stop
                        @change="toggleSelect(client.id, $event)"
                    />
                </div>

                <div class="p-5">
                    <!-- Header -->
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="avatar avatar-md avatar-muted">
                                {{ getInitials(client.name) }}
                            </div>
                            <div>
                                <div class="text-heading">{{ client.name }}</div>
                                <div class="text-caption flex items-center gap-2">
                                    <span>{{ client.slack_channel }}</span>
                                    <span v-if="client.status !== 'active'" :class="['badge badge-xs', getStatusBadgeClass(client.status)]">
                                        {{ client.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span :class="['health-score', getHealthClass(client.health_score)]">
                                {{ formatScore(client.health_score) }}
                            </span>
                            <div v-if="!showBulkActions" class="card-actions">
                                <button class="action-btn" @click="openEditClient(client, $event)" title="Edit">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <button class="action-btn action-btn-danger" @click="openDeleteModal(client, $event)" title="Delete">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <p class="text-body mb-4">{{ client.description }}</p>

                    <!-- Stats Row -->
                    <div class="flex items-center gap-4 mb-4">
                        <div>
                            <span class="text-mono" style="color: var(--color-text-primary)">{{ client.active_projects_count }}</span>
                            <span class="text-caption ml-1">active projects</span>
                        </div>
                        <div>
                            <span class="text-mono" style="color: var(--color-text-primary)">{{ client.contacts.length }}</span>
                            <span class="text-caption ml-1">contacts</span>
                        </div>
                    </div>

                    <!-- Primary Contact -->
                    <div v-if="getPrimaryContact(client.contacts)" class="flex items-center gap-2 pt-4" style="border-top: 1px solid var(--color-border-subtle)">
                        <div class="avatar avatar-sm avatar-gradient">
                            {{ getInitials(getPrimaryContact(client.contacts)!.name) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-body truncate" style="color: var(--color-text-primary)">{{ getPrimaryContact(client.contacts)!.name }}</div>
                            <div class="text-caption truncate">{{ getPrimaryContact(client.contacts)!.role }}</div>
                        </div>
                        <span class="badge badge-gray">Primary</span>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="filteredClients.length === 0" class="card p-8 text-center">
            <p class="text-body">No clients yet</p>
            <p class="text-caption mt-1">Add your first client to get started</p>
            <button class="btn btn-primary mt-4" @click="openNewClient">Add Client</button>
        </div>

        <!-- New/Edit Client Modal -->
        <Modal :show="showClientModal" size="lg" @close="closeClientModal">
            <template #header>
                <h2 class="modal-title">{{ modalTitle }}</h2>
            </template>

            <form @submit.prevent="saveClient">
                <!-- Basic Info Section -->
                <div class="form-section">
                    <h3 class="form-section-title">Basic Information</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="clientForm.name"
                            label="Client Name"
                            placeholder="Acme Corporation"
                            required
                        />
                        <FormSelect
                            v-model="clientForm.status"
                            label="Status"
                            :options="statusOptions"
                        />
                    </div>
                    <FormTextarea
                        v-model="clientForm.description"
                        label="Description"
                        placeholder="Brief description of the client..."
                        :rows="3"
                    />
                    <div class="form-grid">
                        <FormInput
                            v-model="clientForm.website"
                            label="Website"
                            placeholder="https://example.com"
                        />
                        <FormSelect
                            v-model="clientForm.industry"
                            label="Industry"
                            :options="industryOptions"
                        />
                    </div>
                </div>

                <!-- Communication Section -->
                <div class="form-section">
                    <h3 class="form-section-title">Communication</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="clientForm.slack_channel"
                            label="Slack Channel"
                            placeholder="#client-acme"
                        />
                        <FormInput
                            v-model="clientForm.billing_email"
                            label="Billing Email"
                            placeholder="billing@example.com"
                            type="email"
                        />
                    </div>
                    <FormInput
                        v-model="clientForm.billing_cc_emails"
                        label="Billing CC Emails"
                        placeholder="ap@example.com, finance@example.com (comma-separated)"
                    />
                    <FormTextarea
                        v-model="clientForm.address"
                        label="Address"
                        placeholder="123 Main St, City, State, ZIP"
                        :rows="2"
                    />
                </div>

                <!-- Health Score Section -->
                <div class="form-section">
                    <h3 class="form-section-title">Health Score</h3>
                    <div class="health-slider">
                        <label class="form-label">Current Health: {{ clientForm.health_score }}/10</label>
                        <input
                            type="range"
                            v-model.number="clientForm.health_score"
                            min="1"
                            max="10"
                            step="1"
                            class="range-slider"
                        />
                        <div class="range-labels">
                            <span>Poor</span>
                            <span>Good</span>
                            <span>Excellent</span>
                        </div>
                    </div>
                </div>

                <!-- Contacts Section -->
                <div class="form-section">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="form-section-title" style="margin-bottom: 0">Contacts</h3>
                        <button type="button" class="btn btn-sm btn-secondary" @click="addContact">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Add Contact
                        </button>
                    </div>

                    <div v-if="clientForm.contacts.length === 0" class="empty-contacts">
                        <p class="text-caption">No contacts added yet</p>
                    </div>

                    <div v-for="(contact, index) in clientForm.contacts" :key="index" class="contact-card">
                        <div class="contact-header">
                            <label class="contact-primary-toggle">
                                <input
                                    type="radio"
                                    :checked="contact.is_primary"
                                    @change="setPrimaryContact(index)"
                                    name="primary_contact"
                                />
                                <span>Primary Contact</span>
                            </label>
                            <button
                                v-if="clientForm.contacts.length > 1"
                                type="button"
                                class="action-btn action-btn-danger"
                                @click="removeContact(index)"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="form-grid">
                            <FormInput
                                v-model="contact.name"
                                label="Name"
                                placeholder="John Doe"
                                required
                            />
                            <FormInput
                                v-model="contact.role"
                                label="Role"
                                placeholder="Project Manager"
                            />
                        </div>
                        <div class="form-grid">
                            <FormInput
                                v-model="contact.email"
                                label="Email"
                                placeholder="john@example.com"
                                type="email"
                                required
                            />
                            <FormInput
                                v-model="contact.phone"
                                label="Phone"
                                placeholder="+1 (555) 123-4567"
                            />
                        </div>
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeClientModal">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSaving || !clientForm.name"
                        @click="saveClient"
                    >
                        {{ isSaving ? 'Saving...' : (editingClient ? 'Update Client' : 'Create Client') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" size="sm" @close="showDeleteModal = false">
            <template #header>
                <h2 class="modal-title">Delete Client</h2>
            </template>

            <div class="delete-warning">
                <div class="delete-icon">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p>Are you sure you want to delete <strong>{{ clientToDelete?.name }}</strong>?</p>
                <p class="text-caption mt-2">
                    This will remove all associated data including {{ clientToDelete?.projects_count || 0 }} projects and {{ clientToDelete?.contacts?.length || 0 }} contacts. This action cannot be undone.
                </p>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showDeleteModal = false">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isDeleting"
                        @click="confirmDelete"
                    >
                        {{ isDeleting ? 'Deleting...' : 'Delete Client' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.card-actions {
    display: flex;
    gap: 4px;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.card:hover .card-actions {
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

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.5rem;
}

.health-slider {
    max-width: 400px;
}

.range-slider {
    width: 100%;
    height: 6px;
    -webkit-appearance: none;
    background: var(--color-bg-tertiary);
    border-radius: 3px;
    outline: none;
    margin: 0.75rem 0;
}

.range-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 20px;
    height: 20px;
    background: var(--color-accent);
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
    transition: transform 0.15s ease;
}

.range-slider::-webkit-slider-thumb:hover {
    transform: scale(1.1);
}

.range-slider::-moz-range-thumb {
    width: 20px;
    height: 20px;
    background: var(--color-accent);
    border-radius: 50%;
    cursor: pointer;
    border: none;
}

.range-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.contact-card {
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}

.contact-card:last-child {
    margin-bottom: 0;
}

.contact-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.contact-primary-toggle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    cursor: pointer;
}

.contact-primary-toggle input {
    accent-color: var(--color-accent);
}

.contact-primary-toggle input:checked + span {
    color: var(--color-accent);
    font-weight: 500;
}

.empty-contacts {
    text-align: center;
    padding: 2rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    border: 1px dashed var(--color-border-default);
}

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

.delete-warning strong {
    color: var(--color-text-primary);
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
}

.btn-danger {
    background: var(--color-status-red);
    color: white;
    border: none;
}

.btn-danger:hover:not(:disabled) {
    background: #dc2626;
}

/* Search input */
.search-input {
    width: 100%;
    padding: 0.625rem 2.5rem 0.625rem 2.5rem;
    font-size: 0.875rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    color: var(--color-text-primary);
    transition: all 0.15s ease;
}

.search-input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.search-input::placeholder {
    color: var(--color-text-tertiary);
}

/* Bulk actions */
.bulk-actions-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
}

.bulk-select-all {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    cursor: pointer;
}

.bulk-select-all input {
    accent-color: var(--color-accent);
}

.bulk-checkbox {
    position: absolute;
    top: 0.75rem;
    left: 0.75rem;
    z-index: 10;
}

.bulk-checkbox input {
    width: 18px;
    height: 18px;
    accent-color: var(--color-accent);
    cursor: pointer;
}

.card-selected {
    border-color: var(--color-accent) !important;
    background: rgba(99, 102, 241, 0.05);
}

.btn-icon {
    padding: 0.5rem;
}

.btn-active {
    background: var(--color-accent);
    color: white;
    border-color: var(--color-accent);
}

.btn-xs {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.badge-xs {
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
}
</style>
