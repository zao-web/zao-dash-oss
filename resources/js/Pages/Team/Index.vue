<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormToggle from '@/Components/FormToggle.vue';
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

interface User {
    id: number;
    name: string;
    email: string;
    role: 'owner' | 'admin' | 'staff';
    tasks_count: number;
    completed_tasks_count: number;
    created_at: string;
    phone?: string;
    title?: string;
    department?: string;
    permissions?: {
        manage_clients: boolean;
        manage_projects: boolean;
        manage_team: boolean;
        manage_billing: boolean;
        view_vault: boolean;
        approve_work: boolean;
    };
}

interface Stats {
    total: number;
    owners: number;
    staff: number;
}

const props = defineProps<{
    users: User[];
    stats: Stats;
}>();

// Modal states
const showInviteModal = ref(false);
const showEditModal = ref(false);
const showDetailModal = ref(false);
const showRemoveModal = ref(false);
const selectedUser = ref<User | null>(null);
const userToRemove = ref<User | null>(null);
const isSaving = ref(false);
const isRemoving = ref(false);

// Invite form
const inviteForm = reactive({
    email: '',
    name: '',
    role: 'staff' as User['role'],
    title: '',
    department: '',
});

// Edit form
const editForm = reactive({
    name: '',
    email: '',
    phone: '',
    role: 'staff' as User['role'],
    title: '',
    department: '',
    permissions: {
        manage_clients: false,
        manage_projects: false,
        manage_team: false,
        manage_billing: false,
        view_vault: false,
        approve_work: false,
    },
});

const roleOptions = [
    { value: 'owner', label: 'Owner' },
    { value: 'admin', label: 'Admin' },
    { value: 'staff', label: 'Staff' },
];

const departmentOptions = [
    { value: '', label: 'Select department...' },
    { value: 'engineering', label: 'Engineering' },
    { value: 'design', label: 'Design' },
    { value: 'product', label: 'Product' },
    { value: 'marketing', label: 'Marketing' },
    { value: 'sales', label: 'Sales' },
    { value: 'operations', label: 'Operations' },
    { value: 'support', label: 'Support' },
];

const resetInviteForm = () => {
    inviteForm.email = '';
    inviteForm.name = '';
    inviteForm.role = 'staff';
    inviteForm.title = '';
    inviteForm.department = '';
};

const openInviteModal = () => {
    resetInviteForm();
    showInviteModal.value = true;
};

const closeInviteModal = () => {
    showInviteModal.value = false;
    resetInviteForm();
};

const sendInvite = () => {
    isSaving.value = true;
    router.post('/team/invite', inviteForm, {
        onSuccess: () => closeInviteModal(),
        onFinish: () => isSaving.value = false,
    });
};

const openEditModal = (user: User, e?: Event) => {
    e?.stopPropagation();
    selectedUser.value = user;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.phone = user.phone || '';
    editForm.role = user.role;
    editForm.title = user.title || '';
    editForm.department = user.department || '';
    editForm.permissions = {
        manage_clients: user.permissions?.manage_clients ?? false,
        manage_projects: user.permissions?.manage_projects ?? false,
        manage_team: user.permissions?.manage_team ?? false,
        manage_billing: user.permissions?.manage_billing ?? false,
        view_vault: user.permissions?.view_vault ?? false,
        approve_work: user.permissions?.approve_work ?? false,
    };
    showEditModal.value = true;
};

const closeEditModal = () => {
    showEditModal.value = false;
    selectedUser.value = null;
};

const saveUser = () => {
    if (!selectedUser.value) return;
    isSaving.value = true;
    router.put(`/team/${selectedUser.value.id}`, editForm, {
        onSuccess: () => closeEditModal(),
        onFinish: () => isSaving.value = false,
    });
};

const openDetailModal = (user: User, pushState = true) => {
    selectedUser.value = user;
    showDetailModal.value = true;
    if (pushState) {
        history.pushState({ modal: 'user', userId: user.id }, '', `#user-${user.id}`);
    }
};

const closeDetailModal = (goBack = false) => {
    showDetailModal.value = false;
    selectedUser.value = null;
    if (goBack && window.location.hash) {
        history.back();
    } else if (window.location.hash) {
        history.replaceState({}, '', window.location.pathname);
    }
};

// Handle hash navigation and popstate
const handleHashNavigation = () => {
    const hash = window.location.hash;
    if (hash.startsWith('#user-')) {
        const userId = parseInt(hash.replace('#user-', ''), 10);
        const user = props.users.find(u => u.id === userId);
        if (user) {
            openDetailModal(user, false);
        }
    } else {
        // No hash, close any open modals
        if (showDetailModal.value) {
            showDetailModal.value = false;
            selectedUser.value = null;
        }
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

const openRemoveModal = (user: User, e?: Event) => {
    e?.stopPropagation();
    userToRemove.value = user;
    showRemoveModal.value = true;
};

const confirmRemove = () => {
    if (!userToRemove.value) return;
    isRemoving.value = true;
    router.delete(`/team/${userToRemove.value.id}`, {
        onSuccess: () => {
            showRemoveModal.value = false;
            userToRemove.value = null;
        },
        onFinish: () => isRemoving.value = false,
    });
};

const quickChangeRole = (user: User, newRole: string) => {
    router.put(`/team/${user.id}`, { role: newRole }, {
        preserveScroll: true,
    });
};

const getInitials = (name: string) => {
    return name.split(' ').filter(w => /^[a-zA-Z]/.test(w)).map(w => w[0]).join('').slice(0, 2).toUpperCase();
};

const getRoleBadge = (role: string) => {
    const badges: Record<string, string> = {
        owner: 'badge-blue',
        admin: 'badge-yellow',
        staff: 'badge-gray',
    };
    return badges[role] || 'badge-gray';
};

const getAvatarColor = (index: number) => {
    const colors = [
        'linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%)',
        'linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%)',
        'linear-gradient(135deg, #22c55e 0%, #10b981 100%)',
        'linear-gradient(135deg, #f59e0b 0%, #ef4444 100%)',
    ];
    return colors[index % colors.length];
};

const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};
</script>

<template>
    <AppLayout title="Team">
        <!-- Header with Invite button -->
        <div class="flex items-center justify-between mb-6">
            <div></div>
            <button class="btn btn-primary" @click="openInviteModal">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                </svg>
                Invite Member
            </button>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
            <div class="metric-card">
                <div class="metric-label">TEAM SIZE</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">OWNERS</div>
                <div class="metric-value">{{ stats.owners }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">STAFF</div>
                <div class="metric-value">{{ stats.staff }}</div>
            </div>
        </div>

        <!-- Team Grid -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="(user, index) in users"
                :key="user.id"
                class="card user-card"
                @click="openDetailModal(user)"
            >
                <div class="p-5">
                    <div class="flex items-start gap-4">
                        <div
                            class="avatar avatar-md"
                            :style="{ background: getAvatarColor(index), color: 'white' }"
                        >
                            {{ getInitials(user.name) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-heading truncate">{{ user.name }}</span>
                                <span :class="['badge', getRoleBadge(user.role)]">{{ user.role }}</span>
                            </div>
                            <div class="text-caption truncate">{{ user.email }}</div>
                            <div v-if="user.title" class="text-caption truncate" style="color: var(--color-text-tertiary)">{{ user.title }}</div>
                        </div>
                        <div class="card-actions">
                            <button class="action-btn" @click="openEditModal(user, $event)" title="Edit">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <button class="action-btn action-btn-danger" @click="openRemoveModal(user, $event)" title="Remove">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 flex items-center justify-between" style="border-top: 1px solid var(--color-border-subtle)">
                        <div>
                            <span class="text-mono" style="color: var(--color-text-primary)">{{ user.tasks_count }}</span>
                            <span class="text-caption ml-1">assigned tasks</span>
                        </div>
                        <div>
                            <span class="text-mono" style="color: var(--color-status-green)">{{ user.completed_tasks_count }}</span>
                            <span class="text-caption ml-1">completed</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="users.length === 0" class="card p-8 text-center">
            <p class="text-body">No team members yet</p>
            <p class="text-caption mt-1">Invite your team to get started</p>
            <button class="btn btn-primary mt-4" @click="openInviteModal">Invite Team Member</button>
        </div>

        <!-- Invite Member Modal -->
        <Modal :show="showInviteModal" size="md" @close="closeInviteModal">
            <template #header>
                <h2 class="modal-title">Invite Team Member</h2>
            </template>

            <form @submit.prevent="sendInvite">
                <div class="invite-intro">
                    <p class="text-body">Send an invitation email to add a new team member.</p>
                </div>

                <div class="form-section">
                    <div class="form-grid">
                        <FormInput
                            v-model="inviteForm.name"
                            label="Full Name"
                            placeholder="John Doe"
                            required
                        />
                        <FormInput
                            v-model="inviteForm.email"
                            label="Email Address"
                            placeholder="john@company.com"
                            type="email"
                            required
                        />
                    </div>
                    <div class="form-grid">
                        <FormSelect
                            v-model="inviteForm.role"
                            label="Role"
                            :options="roleOptions"
                        />
                        <FormSelect
                            v-model="inviteForm.department"
                            label="Department"
                            :options="departmentOptions"
                        />
                    </div>
                    <FormInput
                        v-model="inviteForm.title"
                        label="Job Title"
                        placeholder="Senior Developer"
                    />
                </div>

                <div class="role-info">
                    <div class="role-info-item">
                        <strong>Owner:</strong> Full access to everything including billing and team management
                    </div>
                    <div class="role-info-item">
                        <strong>Admin:</strong> Can manage projects, clients, and approve work
                    </div>
                    <div class="role-info-item">
                        <strong>Staff:</strong> Can view assigned work and update task status
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeInviteModal">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSaving || !inviteForm.email || !inviteForm.name"
                        @click="sendInvite"
                    >
                        {{ isSaving ? 'Sending...' : 'Send Invite' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Edit Member Modal -->
        <Modal :show="showEditModal" size="lg" @close="closeEditModal">
            <template #header>
                <h2 class="modal-title">Edit Team Member</h2>
            </template>

            <form v-if="selectedUser" @submit.prevent="saveUser">
                <div class="form-section">
                    <h3 class="form-section-title">Basic Information</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="editForm.name"
                            label="Full Name"
                            placeholder="John Doe"
                            required
                        />
                        <FormInput
                            v-model="editForm.email"
                            label="Email"
                            placeholder="john@company.com"
                            type="email"
                            required
                        />
                    </div>
                    <div class="form-grid">
                        <FormInput
                            v-model="editForm.phone"
                            label="Phone"
                            placeholder="+1 (555) 123-4567"
                        />
                        <FormInput
                            v-model="editForm.title"
                            label="Job Title"
                            placeholder="Senior Developer"
                        />
                    </div>
                    <div class="form-grid">
                        <FormSelect
                            v-model="editForm.role"
                            label="Role"
                            :options="roleOptions"
                        />
                        <FormSelect
                            v-model="editForm.department"
                            label="Department"
                            :options="departmentOptions"
                        />
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">Permissions</h3>
                    <div class="permissions-grid">
                        <FormToggle
                            v-model="editForm.permissions.manage_clients"
                            label="Manage Clients"
                            description="Create, edit, and delete client records"
                        />
                        <FormToggle
                            v-model="editForm.permissions.manage_projects"
                            label="Manage Projects"
                            description="Create and manage project settings"
                        />
                        <FormToggle
                            v-model="editForm.permissions.manage_team"
                            label="Manage Team"
                            description="Invite members and change roles"
                        />
                        <FormToggle
                            v-model="editForm.permissions.manage_billing"
                            label="Manage Billing"
                            description="Access billing and invoices"
                        />
                        <FormToggle
                            v-model="editForm.permissions.view_vault"
                            label="View Vault"
                            description="Access secrets and credentials"
                        />
                        <FormToggle
                            v-model="editForm.permissions.approve_work"
                            label="Approve Work"
                            description="Approve pending agent actions"
                        />
                    </div>
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeEditModal">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSaving"
                        @click="saveUser"
                    >
                        {{ isSaving ? 'Saving...' : 'Save Changes' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Member Detail Modal -->
        <Modal :show="showDetailModal" size="md" @close="closeDetailModal(true)">
            <template #header>
                <h2 class="modal-title">Team Member</h2>
            </template>

            <div v-if="selectedUser" class="member-detail">
                <div class="member-header">
                    <div class="avatar avatar-lg" :style="{ background: getAvatarColor(users.indexOf(selectedUser)), color: 'white' }">
                        {{ getInitials(selectedUser.name) }}
                    </div>
                    <div class="member-info">
                        <h3>{{ selectedUser.name }}</h3>
                        <span :class="['badge', getRoleBadge(selectedUser.role)]">{{ selectedUser.role }}</span>
                    </div>
                </div>

                <div class="detail-section">
                    <div class="detail-row">
                        <div class="detail-label">Email</div>
                        <div class="detail-value">
                            <a :href="`mailto:${selectedUser.email}`">{{ selectedUser.email }}</a>
                        </div>
                    </div>
                    <div v-if="selectedUser.phone" class="detail-row">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value">{{ selectedUser.phone }}</div>
                    </div>
                    <div v-if="selectedUser.title" class="detail-row">
                        <div class="detail-label">Title</div>
                        <div class="detail-value">{{ selectedUser.title }}</div>
                    </div>
                    <div v-if="selectedUser.department" class="detail-row">
                        <div class="detail-label">Department</div>
                        <div class="detail-value" style="text-transform: capitalize">{{ selectedUser.department }}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Member Since</div>
                        <div class="detail-value">{{ formatDate(selectedUser.created_at) }}</div>
                    </div>
                </div>

                <div class="detail-section">
                    <h4>Activity</h4>
                    <div class="activity-stats">
                        <div class="activity-stat">
                            <span class="stat-value">{{ selectedUser.tasks_count }}</span>
                            <span class="stat-label">Assigned Tasks</span>
                        </div>
                        <div class="activity-stat">
                            <span class="stat-value" style="color: var(--color-status-green)">{{ selectedUser.completed_tasks_count }}</span>
                            <span class="stat-label">Completed</span>
                        </div>
                        <div class="activity-stat">
                            <span class="stat-value">{{ selectedUser.tasks_count - selectedUser.completed_tasks_count }}</span>
                            <span class="stat-label">In Progress</span>
                        </div>
                    </div>
                </div>

                <div class="detail-actions">
                    <button class="btn btn-secondary" @click="openEditModal(selectedUser); closeDetailModal()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        Edit Profile
                    </button>
                    <button class="btn btn-danger-outline" @click="openRemoveModal(selectedUser); closeDetailModal()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6" />
                        </svg>
                        Remove
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Remove Confirmation Modal -->
        <Modal :show="showRemoveModal" size="sm" @close="showRemoveModal = false">
            <template #header>
                <h2 class="modal-title">Remove Team Member</h2>
            </template>

            <div class="delete-warning">
                <div class="delete-icon">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p>Remove <strong>{{ userToRemove?.name }}</strong> from the team?</p>
                <p class="text-caption mt-2">
                    Their assigned tasks will be unassigned and they will lose access immediately.
                </p>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showRemoveModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isRemoving"
                        @click="confirmRemove"
                    >
                        {{ isRemoving ? 'Removing...' : 'Remove Member' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.user-card {
    cursor: pointer;
    transition: all 0.15s ease;
}

.user-card:hover {
    border-color: var(--color-border-strong);
}

.card-actions {
    display: flex;
    gap: 4px;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.user-card:hover .card-actions {
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

.invite-intro {
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.form-section {
    padding: 1rem 0;
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

.role-info {
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    padding: 1rem;
    margin-top: 1rem;
}

.role-info-item {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    padding: 0.5rem 0;
    line-height: 1.4;
}

.role-info-item strong {
    color: var(--color-text-primary);
}

.role-info-item:first-child {
    padding-top: 0;
}

.role-info-item:last-child {
    padding-bottom: 0;
}

.permissions-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}

/* Member detail */
.member-detail {
    padding: 0.5rem 0;
}

.member-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.avatar-lg {
    width: 64px;
    height: 64px;
    font-size: 1.25rem;
}

.member-info h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
}

.detail-section {
    margin-top: 1.5rem;
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

.activity-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-top: 0.5rem;
}

.activity-stat {
    text-align: center;
    padding: 1rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
}

.stat-value {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
}

.stat-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.detail-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border-subtle);
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

/* Button variants */
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
</style>
