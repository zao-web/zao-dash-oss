<script setup lang="ts">
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormTextarea from '@/Components/FormTextarea.vue'
import FormToggle from '@/Components/FormToggle.vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, ref, reactive, watch } from 'vue'

interface Client {
    id: number
    name: string
    slug?: string
}

interface Project {
    id: number
    name: string
    slug: string
    description?: string
    status: 'active' | 'completed' | 'on_hold' | 'cancelled' | 'archived'
    type: 'project' | 'retainer' | 'time_materials'
    budget: number
    hourly_rate?: number | null
    estimated_hours?: number | null
    start_date?: string | null
    end_date?: string | null
    client?: Client | null
}

const props = defineProps<{
    show: boolean
    clients: Client[]
    project?: Project | null
    clientId?: number | null
}>()

const emit = defineEmits<{
    close: []
    success: []
}>()

const isEditing = computed(() => !!props.project)
const isSubmitting = ref(false)

const projectForm = reactive({
    name: '',
    description: '',
    client_id: null as number | null,
    status: 'active',
    type: 'project',
    budget: '',
    hourly_rate: '',
    estimated_hours: '',
    start_date: '',
    end_date: '',
    notify_client: false,
})

const resetForm = () => {
    projectForm.name = ''
    projectForm.description = ''
    projectForm.client_id = props.clientId || null
    projectForm.status = 'active'
    projectForm.type = 'project'
    projectForm.budget = ''
    projectForm.hourly_rate = ''
    projectForm.estimated_hours = ''
    projectForm.start_date = ''
    projectForm.end_date = ''
    projectForm.notify_client = false
}

const populateForm = (project: Project) => {
    projectForm.name = project.name
    projectForm.description = project.description || ''
    projectForm.client_id = project.client?.id || props.clientId || null
    projectForm.status = project.status
    projectForm.type = project.type
    projectForm.budget = project.budget.toString()
    projectForm.hourly_rate = project.hourly_rate?.toString() || ''
    projectForm.estimated_hours = project.estimated_hours?.toString() || ''
    projectForm.start_date = project.start_date || ''
    projectForm.end_date = project.end_date || ''
}

// Watch for modal open/close and project changes
watch(() => props.show, (show) => {
    if (show) {
        if (props.project) {
            populateForm(props.project)
        } else {
            resetForm()
        }
    }
})

watch(() => props.project, (project) => {
    if (props.show && project) {
        populateForm(project)
    }
})

// Watch for clientId changes (when used from client page)
watch(() => props.clientId, (clientId) => {
    if (clientId && !props.project) {
        projectForm.client_id = clientId
    }
})

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
]

const typeOptions = [
    { value: 'project', label: 'Fixed-price Project' },
    { value: 'time_materials', label: 'Time & Materials' },
    { value: 'retainer', label: 'Retainer' },
]

// Auto-calculate budget for Time & Materials projects
const isTimeMaterials = computed(() => projectForm.type === 'time_materials')

watch(
    [() => projectForm.hourly_rate, () => projectForm.estimated_hours, () => projectForm.type],
    () => {
        if (isTimeMaterials.value) {
            const rate = parseFloat(String(projectForm.hourly_rate)) || 0
            const hours = parseFloat(String(projectForm.estimated_hours)) || 0
            if (rate > 0 && hours > 0) {
                projectForm.budget = (rate * hours).toString()
            }
        }
    },
    { immediate: true }
)

const submitProject = () => {
    isSubmitting.value = true
    const url = isEditing.value ? `/projects/${props.project?.id}` : '/projects'
    const method = isEditing.value ? 'put' : 'post'

    router[method](url, {
        ...projectForm,
        budget: parseFloat(projectForm.budget) || 0,
        hourly_rate: parseFloat(projectForm.hourly_rate) || null,
        estimated_hours: parseInt(projectForm.estimated_hours) || null,
    }, {
        onSuccess: () => {
            emit('close')
            emit('success')
            resetForm()
        },
        onFinish: () => {
            isSubmitting.value = false
        },
    })
}

const handleClose = () => {
    emit('close')
}

// Determine if client selector should be shown
const showClientSelector = computed(() => !props.clientId)

// --- Inline client creation ---
const showNewClientModal = ref(false)
const isCreatingClient = ref(false)
const newClientError = ref('')
const localClients = ref<Client[]>([])

const newClientForm = reactive({
    name: '',
    contact_name: '',
    contact_email: '',
})

const allClients = computed(() => {
    const merged = [...props.clients, ...localClients.value]
    // Deduplicate by id
    const seen = new Set<number>()
    return merged.filter(c => {
        if (seen.has(c.id)) return false
        seen.add(c.id)
        return true
    }).sort((a, b) => a.name.localeCompare(b.name))
})

const allClientOptions = computed(() =>
    allClients.value.map(c => ({ value: c.id, label: c.name }))
)

const resetNewClientForm = () => {
    newClientForm.name = ''
    newClientForm.contact_name = ''
    newClientForm.contact_email = ''
    newClientError.value = ''
}

const openNewClientModal = () => {
    resetNewClientForm()
    showNewClientModal.value = true
}

const closeNewClientModal = () => {
    showNewClientModal.value = false
    resetNewClientForm()
}

const saveNewClient = async () => {
    if (!newClientForm.name.trim()) return

    isCreatingClient.value = true
    newClientError.value = ''

    try {
        const payload: Record<string, unknown> = {
            name: newClientForm.name.trim(),
            status: 'active',
        }

        // Include contacts if contact info provided
        if (newClientForm.contact_name || newClientForm.contact_email) {
            payload.contacts = [{
                name: newClientForm.contact_name,
                email: newClientForm.contact_email,
                role: '',
                is_primary: true,
            }]
        }

        const { data: client } = await axios.post('/clients', payload)

        localClients.value.push(client)
        projectForm.client_id = client.id
        closeNewClientModal()
    } catch (err: unknown) {
        if (axios.isAxiosError(err) && err.response?.status === 422) {
            const errors = err.response.data.errors
            newClientError.value = Object.values(errors).flat().join(', ')
        } else {
            newClientError.value = 'Failed to create client. Please try again.'
        }
    } finally {
        isCreatingClient.value = false
    }
}
</script>

<template>
    <Modal
        :show="show"
        :title="isEditing ? 'Edit Project' : 'New Project'"
        size="lg"
        @close="handleClose"
    >
        <form @submit.prevent="submitProject" class="space-y-6">
            <!-- Basic Info -->
            <div class="form-section">
                <h3 class="form-section-title">Basic Information</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <FormInput
                            v-model="projectForm.name"
                            label="Project Name"
                            placeholder="Enter project name"
                            required
                        />
                    </div>
                    <div v-if="showClientSelector">
                        <FormSelect
                            v-model="projectForm.client_id"
                            label="Client"
                            :options="allClientOptions"
                            placeholder="Select a client"
                            searchable
                            required
                        />
                        <button
                            type="button"
                            class="new-client-link"
                            @click="openNewClientModal"
                        >
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Create new client
                        </button>
                    </div>
                    <FormSelect
                        v-model="projectForm.status"
                        label="Status"
                        :options="statusOptions"
                        required
                    />
                </div>
                <FormTextarea
                    v-model="projectForm.description"
                    label="Description"
                    placeholder="Brief description of the project scope..."
                    :rows="3"
                />
            </div>

            <!-- Billing -->
            <div class="form-section">
                <h3 class="form-section-title">Billing & Budget</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormSelect
                        v-model="projectForm.type"
                        label="Project Type"
                        :options="typeOptions"
                        required
                    />
                    <FormInput
                        v-model="projectForm.hourly_rate"
                        label="Hourly Rate"
                        type="number"
                        prefix="$"
                        placeholder="0"
                        :hint="isTimeMaterials ? 'Used to calculate budget' : 'For time-based billing'"
                        :required="isTimeMaterials"
                    />
                    <FormInput
                        v-model="projectForm.estimated_hours"
                        label="Estimated Hours"
                        type="number"
                        placeholder="0"
                        suffix="hrs"
                        :hint="isTimeMaterials ? 'Used to calculate budget' : ''"
                        :required="isTimeMaterials"
                    />
                    <FormInput
                        v-model="projectForm.budget"
                        label="Budget"
                        type="number"
                        prefix="$"
                        placeholder="0"
                        :hint="isTimeMaterials ? 'Auto-calculated from rate x hours' : 'Total project budget'"
                        :disabled="isTimeMaterials"
                        :required="!isTimeMaterials"
                    />
                </div>
            </div>

            <!-- Timeline -->
            <div class="form-section">
                <h3 class="form-section-title">Timeline</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormInput
                        v-model="projectForm.start_date"
                        label="Start Date"
                        type="date"
                    />
                    <FormInput
                        v-model="projectForm.end_date"
                        label="End Date"
                        type="date"
                    />
                </div>
            </div>

            <!-- Options -->
            <div v-if="!isEditing" class="form-section">
                <h3 class="form-section-title">Options</h3>
                <FormToggle
                    v-model="projectForm.notify_client"
                    label="Notify client"
                    description="Send an email to the client about this new project"
                />
            </div>
        </form>

        <template #footer>
            <button class="btn btn-secondary" @click="handleClose">
                Cancel
            </button>
            <button
                class="btn btn-primary"
                :disabled="isSubmitting || !projectForm.name || (!clientId && !projectForm.client_id)"
                @click="submitProject"
            >
                {{ isSubmitting ? 'Saving...' : (isEditing ? 'Save Changes' : 'Create Project') }}
            </button>
        </template>
    </Modal>

    <!-- Inline New Client Modal -->
    <Modal
        :show="showNewClientModal"
        title="New Client"
        size="sm"
        @close="closeNewClientModal"
    >
        <form @submit.prevent="saveNewClient" class="space-y-4">
            <FormInput
                v-model="newClientForm.name"
                label="Client Name"
                placeholder="Acme Corp"
                required
            />
            <div class="form-section">
                <h3 class="form-section-title">Primary Contact (optional)</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <FormInput
                        v-model="newClientForm.contact_name"
                        label="Name"
                        placeholder="Jane Smith"
                    />
                    <FormInput
                        v-model="newClientForm.contact_email"
                        label="Email"
                        placeholder="jane@acme.com"
                        type="email"
                    />
                </div>
            </div>

            <p v-if="newClientError" class="form-error-message">{{ newClientError }}</p>
        </form>

        <template #footer>
            <button class="btn btn-secondary" @click="closeNewClientModal">
                Cancel
            </button>
            <button
                class="btn btn-primary"
                :disabled="isCreatingClient || !newClientForm.name.trim()"
                @click="saveNewClient"
            >
                {{ isCreatingClient ? 'Creating...' : 'Create Client' }}
            </button>
        </template>
    </Modal>
</template>

<style scoped>
.form-section {
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.form-section:last-child {
    padding-bottom: 0;
    border-bottom: none;
}

.form-section-title {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-secondary);
    margin-bottom: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.new-client-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: -0.5rem;
    padding: 0;
    background: none;
    border: none;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-accent);
    cursor: pointer;
    transition: opacity 0.15s ease;
}

.new-client-link:hover {
    opacity: 0.8;
}

.form-error-message {
    font-size: 0.8125rem;
    color: var(--color-status-red);
}
</style>
