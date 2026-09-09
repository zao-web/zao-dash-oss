<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import FormSelect from '@/Components/FormSelect.vue';

interface Contact {
    name: string;
    email: string;
    role: string;
    phone: string;
}

interface Subtask {
    title: string;
    description: string;
}

interface TaskData {
    title: string;
    description: string;
    priority: string;
    estimated_hours: number | null;
    subtasks: Subtask[];
}

interface MilestoneData {
    name: string;
    description: string;
    tasks: TaskData[];
    collapsed?: boolean;
}

interface ParsedData {
    client: { name: string; website: string; description: string };
    contacts: Contact[];
    project: { name: string; description: string; type: string; budget: number | null };
    milestones: MilestoneData[];
}

interface DocumentEntry {
    id: number;
    type: 'pdf' | 'google_drive' | 'text';
    file: File | null;
    google_drive_url: string;
    content: string;
    label: string;
}

const props = defineProps<{
    show: boolean;
}>();

const emit = defineEmits<{
    close: [];
}>();

const step = ref<1 | 2 | 3>(1);
const isLoading = ref(false);
const error = ref<string | null>(null);
const isDragging = ref(false);
const nextDocId = ref(1);

// Step 1: Documents
const documents = ref<DocumentEntry[]>([]);

// Step 2: Parsed data (editable)
const parsedData = ref<ParsedData | null>(null);

// Step 3: Confirmation state
const isConfirming = ref(false);

const projectTypeOptions = [
    { value: 'project', label: 'Project' },
    { value: 'retainer', label: 'Retainer' },
    { value: 'support', label: 'Support' },
];

const priorityOptions = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'urgent', label: 'Urgent' },
];

const totalCounts = computed(() => {
    if (!parsedData.value) return { milestones: 0, tasks: 0 };
    const milestones = parsedData.value.milestones.length;
    let tasks = 0;
    for (const m of parsedData.value.milestones) {
        tasks += m.tasks.length;
        for (const t of m.tasks) {
            tasks += (t.subtasks?.length ?? 0);
        }
    }
    return { milestones, tasks };
});

// --- Document Management ---
const addPdfFiles = (files: FileList | File[]) => {
    for (const file of files) {
        if (file.type === 'application/pdf') {
            documents.value.push({
                id: nextDocId.value++,
                type: 'pdf',
                file,
                google_drive_url: '',
                content: '',
                label: file.name.replace(/\.pdf$/i, ''),
            });
        }
    }
};

const addGoogleDriveEntry = () => {
    documents.value.push({
        id: nextDocId.value++,
        type: 'google_drive',
        file: null,
        google_drive_url: '',
        content: '',
        label: 'Google Drive Document',
    });
};

const addTextEntry = () => {
    documents.value.push({
        id: nextDocId.value++,
        type: 'text',
        file: null,
        google_drive_url: '',
        content: '',
        label: 'Pasted Content',
    });
};

const removeDocument = (id: number) => {
    documents.value = documents.value.filter(d => d.id !== id);
};

// --- Drag & Drop ---
const handleDrop = (e: DragEvent) => {
    isDragging.value = false;
    if (e.dataTransfer?.files) {
        addPdfFiles(e.dataTransfer.files);
    }
};

const handleFileInput = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files) {
        addPdfFiles(input.files);
        input.value = '';
    }
};

// --- Step 1 → 2: Parse (async with polling) ---
const parseStatusMessage = ref('Uploading documents...');
let pollTimer: ReturnType<typeof setInterval> | null = null;

const parseDocuments = async () => {
    if (documents.value.length === 0) return;

    isLoading.value = true;
    error.value = null;
    parseStatusMessage.value = 'Uploading documents...';

    try {
        const formData = new FormData();

        documents.value.forEach((doc, i) => {
            formData.append(`documents[${i}][type]`, doc.type);
            formData.append(`documents[${i}][label]`, doc.label);

            if (doc.type === 'pdf' && doc.file) {
                formData.append(`documents[${i}][file]`, doc.file);
            } else if (doc.type === 'google_drive') {
                formData.append(`documents[${i}][google_drive_url]`, doc.google_drive_url);
            } else if (doc.type === 'text') {
                formData.append(`documents[${i}][content]`, doc.content);
            }
        });

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch('/api/sow-import/parse', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: formData,
        });

        const data = await response.json();

        if (!response.ok) {
            error.value = data.error || data.message || 'Failed to parse documents.';
            isLoading.value = false;
            return;
        }

        // Start polling for results
        parseStatusMessage.value = 'AI is analyzing your documents...';
        pollForResult(data.parse_id, csrfToken);
    } catch (e) {
        error.value = 'An unexpected error occurred while uploading.';
        isLoading.value = false;
    }
};

const pollForResult = (parseId: string, csrfToken: string) => {
    pollTimer = setInterval(async () => {
        try {
            const response = await fetch(`/api/sow-import/parse/${parseId}/status`, {
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            });

            const result = await response.json();

            if (result.status === 'completed') {
                stopPolling();
                const data = result.data;

                // Initialize collapsed state on milestones
                data.milestones = (data.milestones || []).map((m: MilestoneData) => ({
                    ...m,
                    collapsed: false,
                    tasks: (m.tasks || []).map((t: TaskData) => ({
                        ...t,
                        subtasks: t.subtasks || [],
                    })),
                }));

                parsedData.value = data;
                step.value = 2;
                isLoading.value = false;
            } else if (result.status === 'failed') {
                stopPolling();
                error.value = result.error || 'Failed to parse documents.';
                isLoading.value = false;
            }
            // 'queued' and 'processing' continue polling
        } catch (e) {
            stopPolling();
            error.value = 'Lost connection while waiting for results.';
            isLoading.value = false;
        }
    }, 3000);
};

const stopPolling = () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

// --- Step 2 editing helpers ---
const addContact = () => {
    parsedData.value?.contacts.push({ name: '', email: '', role: '', phone: '' });
};

const removeContact = (index: number) => {
    parsedData.value?.contacts.splice(index, 1);
};

const addMilestone = () => {
    parsedData.value?.milestones.push({
        name: '',
        description: '',
        tasks: [{ title: '', description: '', priority: 'medium', estimated_hours: null, subtasks: [] }],
        collapsed: false,
    });
};

const removeMilestone = (index: number) => {
    parsedData.value?.milestones.splice(index, 1);
};

const addTask = (milestoneIndex: number) => {
    parsedData.value?.milestones[milestoneIndex].tasks.push({
        title: '',
        description: '',
        priority: 'medium',
        estimated_hours: null,
        subtasks: [],
    });
};

const removeTask = (milestoneIndex: number, taskIndex: number) => {
    parsedData.value?.milestones[milestoneIndex].tasks.splice(taskIndex, 1);
};

const toggleMilestone = (index: number) => {
    if (parsedData.value) {
        parsedData.value.milestones[index].collapsed = !parsedData.value.milestones[index].collapsed;
    }
};

// --- Step 2 → 3: Review ---
const proceedToConfirm = () => {
    step.value = 3;
};

// --- Step 3: Confirm ---
const confirmImport = async () => {
    if (!parsedData.value) return;

    isConfirming.value = true;
    error.value = null;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch('/api/sow-import/confirm', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(parsedData.value),
        });

        const data = await response.json();

        if (!response.ok) {
            error.value = data.error || data.message || 'Failed to create project.';
            return;
        }

        // Redirect to new project
        emit('close');
        resetState();
        router.visit(`/projects/${data.project_id}`);
    } catch (e) {
        error.value = 'An unexpected error occurred.';
    } finally {
        isConfirming.value = false;
    }
};

// --- Navigation & Reset ---
const goBack = () => {
    if (step.value === 3) step.value = 2;
    else if (step.value === 2) step.value = 1;
};

const resetState = () => {
    stopPolling();
    step.value = 1;
    documents.value = [];
    parsedData.value = null;
    error.value = null;
    isLoading.value = false;
    isConfirming.value = false;
    isDragging.value = false;
    parseStatusMessage.value = 'Uploading documents...';
};

const handleClose = () => {
    resetState();
    emit('close');
};
</script>

<template>
    <Modal :show="show" title="Import Statement of Work" size="xl" @close="handleClose">
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div :class="['step', { active: step === 1, done: step > 1 }]">
                <div class="step-number">1</div>
                <span>Upload</span>
            </div>
            <div class="step-line" :class="{ done: step > 1 }"></div>
            <div :class="['step', { active: step === 2, done: step > 2 }]">
                <div class="step-number">2</div>
                <span>Review</span>
            </div>
            <div class="step-line" :class="{ done: step > 2 }"></div>
            <div :class="['step', { active: step === 3 }]">
                <div class="step-number">3</div>
                <span>Confirm</span>
            </div>
        </div>

        <!-- Error Banner -->
        <div v-if="error" class="error-banner">
            {{ error }}
            <button @click="error = null" class="error-dismiss">&times;</button>
        </div>

        <!-- STEP 1: Upload Documents -->
        <div v-if="step === 1" class="step-content">
            <!-- Drop Zone -->
            <div
                :class="['drop-zone', { 'is-dragging': isDragging, 'has-files': documents.length > 0 }]"
                @dragenter.prevent="isDragging = true"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop"
            >
                <div class="drop-zone-content">
                    <svg class="drop-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                    <p class="drop-text">Drag & drop PDF files here</p>
                    <p class="drop-hint">or click to browse</p>
                    <input type="file" accept=".pdf" multiple class="drop-input" @change="handleFileInput" />
                </div>
            </div>

            <!-- Document List -->
            <div v-if="documents.length > 0" class="document-list">
                <div v-for="doc in documents" :key="doc.id" class="document-item">
                    <div class="document-icon">
                        <svg v-if="doc.type === 'pdf'" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <svg v-else-if="doc.type === 'google_drive'" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        <svg v-else fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h12" />
                        </svg>
                    </div>
                    <div class="document-info">
                        <input v-model="doc.label" type="text" class="document-label-input" placeholder="Document label" />
                        <span class="document-type-badge">{{ doc.type === 'pdf' ? 'PDF' : doc.type === 'google_drive' ? 'Google Drive' : 'Text' }}</span>
                        <div v-if="doc.type === 'google_drive'" class="mt-2">
                            <input v-model="doc.google_drive_url" type="url" class="form-input-sm" placeholder="https://docs.google.com/document/d/..." />
                        </div>
                        <div v-if="doc.type === 'text'" class="mt-2">
                            <textarea v-model="doc.content" class="form-input-sm" rows="3" placeholder="Paste document content here..."></textarea>
                        </div>
                    </div>
                    <button @click="removeDocument(doc.id)" class="document-remove">&times;</button>
                </div>
            </div>

            <!-- Add More Buttons -->
            <div class="add-document-actions">
                <button @click="addGoogleDriveEntry" class="btn-add-doc">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101" /></svg>
                    Google Drive URL
                </button>
                <button @click="addTextEntry" class="btn-add-doc">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h12" /></svg>
                    Paste Text
                </button>
            </div>
        </div>

        <!-- STEP 2: Review & Edit -->
        <div v-if="step === 2 && parsedData" class="step-content review-step">
            <!-- Client Info -->
            <section class="review-section">
                <h3 class="review-section-title">Client</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Name <span class="required">*</span></label>
                        <input v-model="parsedData.client.name" type="text" class="form-input" />
                    </div>
                    <div class="form-group">
                        <label class="form-label">Website</label>
                        <input v-model="parsedData.client.website" type="text" class="form-input" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea v-model="parsedData.client.description" class="form-input" rows="2"></textarea>
                </div>
            </section>

            <!-- Contacts -->
            <section class="review-section">
                <div class="section-header">
                    <h3 class="review-section-title">Contacts</h3>
                    <button @click="addContact" class="btn-inline">+ Add</button>
                </div>
                <div v-for="(contact, ci) in parsedData.contacts" :key="ci" class="contact-row">
                    <input v-model="contact.name" type="text" class="form-input-sm" placeholder="Name" />
                    <input v-model="contact.email" type="email" class="form-input-sm" placeholder="Email" />
                    <input v-model="contact.role" type="text" class="form-input-sm" placeholder="Role" />
                    <button @click="removeContact(ci)" class="btn-remove-sm">&times;</button>
                </div>
                <p v-if="parsedData.contacts.length === 0" class="empty-hint">No contacts found in documents.</p>
            </section>

            <!-- Project Info -->
            <section class="review-section">
                <h3 class="review-section-title">Project</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Name <span class="required">*</span></label>
                        <input v-model="parsedData.project.name" type="text" class="form-input" />
                    </div>
                    <div class="form-group">
                        <FormSelect
                            v-model="parsedData.project.type"
                            :options="projectTypeOptions"
                            label="Type"
                            required
                        />
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Budget</label>
                        <input v-model.number="parsedData.project.budget" type="number" class="form-input" placeholder="0.00" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea v-model="parsedData.project.description" class="form-input" rows="2"></textarea>
                </div>
            </section>

            <!-- Milestones & Tasks -->
            <section class="review-section">
                <div class="section-header">
                    <h3 class="review-section-title">Milestones & Tasks</h3>
                    <button @click="addMilestone" class="btn-inline">+ Add Milestone</button>
                </div>

                <div v-for="(milestone, mi) in parsedData.milestones" :key="mi" class="milestone-card">
                    <div class="milestone-header" @click="toggleMilestone(mi)">
                        <svg :class="['collapse-chevron', { expanded: !milestone.collapsed }]" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                        <input v-model="milestone.name" type="text" class="milestone-name-input" placeholder="Milestone name" @click.stop />
                        <span class="task-count">{{ milestone.tasks.length }} task{{ milestone.tasks.length !== 1 ? 's' : '' }}</span>
                        <button @click.stop="removeMilestone(mi)" class="btn-remove-sm">&times;</button>
                    </div>

                    <div v-show="!milestone.collapsed" class="milestone-body">
                        <div class="form-group">
                            <textarea v-model="milestone.description" class="form-input-sm" rows="1" placeholder="Milestone description"></textarea>
                        </div>

                        <div v-for="(task, ti) in milestone.tasks" :key="ti" class="task-row">
                            <div class="task-main">
                                <input v-model="task.title" type="text" class="form-input-sm task-title-input" placeholder="Task title" />
                                <div class="task-fields">
                                    <FormSelect
                                        v-model="task.priority"
                                        :options="priorityOptions"
                                        variant="inline"
                                        size="sm"
                                    />
                                    <input v-model.number="task.estimated_hours" type="number" class="form-input-xs" placeholder="hrs" step="0.5" />
                                    <button @click="removeTask(mi, ti)" class="btn-remove-sm">&times;</button>
                                </div>
                            </div>
                            <textarea v-model="task.description" class="form-input-sm task-desc" rows="1" placeholder="Description"></textarea>

                            <!-- Subtasks preview -->
                            <div v-if="task.subtasks && task.subtasks.length > 0" class="subtask-list">
                                <div v-for="(sub, si) in task.subtasks" :key="si" class="subtask-item">
                                    <span class="subtask-bullet">&#8226;</span>
                                    <input v-model="sub.title" type="text" class="form-input-xs subtask-input" />
                                </div>
                            </div>
                        </div>

                        <button @click="addTask(mi)" class="btn-add-task">+ Add Task</button>
                    </div>
                </div>
            </section>
        </div>

        <!-- STEP 3: Confirm -->
        <div v-if="step === 3 && parsedData" class="step-content confirm-step">
            <div class="confirm-summary">
                <h3 class="confirm-title">Ready to Create</h3>
                <p class="confirm-description">The following will be created in your workspace:</p>

                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="summary-count">1</span>
                        <span class="summary-label">Client</span>
                        <span class="summary-detail">{{ parsedData.client.name }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-count">{{ parsedData.contacts.length }}</span>
                        <span class="summary-label">Contact{{ parsedData.contacts.length !== 1 ? 's' : '' }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-count">1</span>
                        <span class="summary-label">Project</span>
                        <span class="summary-detail">{{ parsedData.project.name }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-count">{{ totalCounts.milestones }}</span>
                        <span class="summary-label">Milestone{{ totalCounts.milestones !== 1 ? 's' : '' }}</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-count">{{ totalCounts.tasks }}</span>
                        <span class="summary-label">Task{{ totalCounts.tasks !== 1 ? 's' : '' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <template #footer>
            <button v-if="step > 1" @click="goBack" class="btn-secondary" :disabled="isLoading || isConfirming">
                Back
            </button>
            <div class="flex-1"></div>
            <button v-if="step === 1" @click="parseDocuments" class="btn-primary" :disabled="documents.length === 0 || isLoading">
                <template v-if="isLoading">
                    <span class="spinner"></span>
                    {{ parseStatusMessage }}
                </template>
                <template v-else>
                    Parse Documents
                </template>
            </button>
            <button v-if="step === 2" @click="proceedToConfirm" class="btn-primary">
                Continue
            </button>
            <button v-if="step === 3" @click="confirmImport" class="btn-primary" :disabled="isConfirming">
                <template v-if="isConfirming">
                    <span class="spinner"></span>
                    Creating...
                </template>
                <template v-else>
                    Create Project
                </template>
            </button>
        </template>
    </Modal>
</template>

<style scoped>
.step-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 1.5rem;
    padding: 0 2rem;
}

.step {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--color-text-quaternary);
    font-size: 0.8125rem;
    font-weight: 500;
}

.step.active {
    color: var(--color-accent);
}

.step.done {
    color: var(--color-status-green);
}

.step-number {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    border: 2px solid currentColor;
}

.step.active .step-number {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.step.done .step-number {
    background: var(--color-status-green);
    border-color: var(--color-status-green);
    color: white;
}

.step-line {
    width: 60px;
    height: 2px;
    background: var(--color-border-subtle);
    margin: 0 0.5rem;
}

.step-line.done {
    background: var(--color-status-green);
}

.step-content {
    min-height: 200px;
}

/* Error Banner */
.error-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 8px;
    color: var(--color-status-red);
    font-size: 0.875rem;
}

.error-dismiss {
    background: none;
    border: none;
    color: inherit;
    font-size: 1.25rem;
    cursor: pointer;
    padding: 0 0.25rem;
    opacity: 0.7;
}

.error-dismiss:hover {
    opacity: 1;
}

/* Drop Zone */
.drop-zone {
    position: relative;
    border: 2px dashed var(--color-border-default);
    border-radius: 12px;
    padding: 3rem 2rem;
    text-align: center;
    transition: all 0.2s ease;
    cursor: pointer;
}

.drop-zone.is-dragging {
    border-color: var(--color-accent);
    background: rgba(139, 92, 246, 0.05);
}

.drop-zone.has-files {
    padding: 1.5rem;
}

.drop-zone-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.drop-icon {
    width: 48px;
    height: 48px;
    color: var(--color-text-quaternary);
}

.has-files .drop-icon {
    width: 32px;
    height: 32px;
}

.drop-text {
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--color-text-secondary);
}

.drop-hint {
    font-size: 0.8125rem;
    color: var(--color-text-quaternary);
}

.drop-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
}

/* Document List */
.document-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-top: 1rem;
}

.document-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.75rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    border: 1px solid var(--color-border-subtle);
}

.document-icon {
    color: var(--color-text-tertiary);
    flex-shrink: 0;
    margin-top: 2px;
}

.document-info {
    flex: 1;
    min-width: 0;
}

.document-label-input {
    width: 100%;
    background: transparent;
    border: none;
    color: var(--color-text-primary);
    font-size: 0.875rem;
    font-weight: 500;
    padding: 0;
    outline: none;
}

.document-label-input:focus {
    color: var(--color-accent);
}

.document-type-badge {
    display: inline-block;
    font-size: 0.6875rem;
    font-weight: 500;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
    background: var(--color-bg-secondary);
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.document-remove {
    background: none;
    border: none;
    color: var(--color-text-quaternary);
    font-size: 1.25rem;
    cursor: pointer;
    padding: 0 0.25rem;
    line-height: 1;
    flex-shrink: 0;
}

.document-remove:hover {
    color: var(--color-status-red);
}

/* Add Document Actions */
.add-document-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
}

.btn-add-doc {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 0.75rem;
    background: var(--color-bg-tertiary);
    border: 1px dashed var(--color-border-default);
    border-radius: 6px;
    color: var(--color-text-secondary);
    font-size: 0.8125rem;
    cursor: pointer;
    transition: all 0.15s;
}

.btn-add-doc:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
}

/* Review Step */
.review-step {
    max-height: 60vh;
    overflow-y: auto;
}

.review-section {
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.review-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.review-section-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.75rem;
}

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}

.section-header .review-section-title {
    margin-bottom: 0;
}

/* Form Elements */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.form-group {
    margin-bottom: 0.5rem;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.375rem;
}

.required {
    color: var(--color-status-red);
}

.form-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    color: var(--color-text-primary);
    font-size: 0.875rem;
}

.form-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.form-input-sm {
    width: 100%;
    padding: 0.375rem 0.625rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    color: var(--color-text-primary);
    font-size: 0.8125rem;
}

.form-input-sm:focus {
    outline: none;
    border-color: var(--color-accent);
}

.form-input-xs {
    width: 60px;
    padding: 0.25rem 0.5rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    color: var(--color-text-primary);
    font-size: 0.75rem;
    text-align: center;
}

.form-input-xs:focus {
    outline: none;
    border-color: var(--color-accent);
}

/* Contact Row */
.contact-row {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    margin-bottom: 0.5rem;
}

.contact-row .form-input-sm {
    flex: 1;
}

/* Buttons */
.btn-inline {
    background: none;
    border: none;
    color: var(--color-accent);
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    transition: background 0.15s;
}

.btn-inline:hover {
    background: rgba(139, 92, 246, 0.1);
}

.btn-remove-sm {
    background: none;
    border: none;
    color: var(--color-text-quaternary);
    font-size: 1.125rem;
    cursor: pointer;
    padding: 0 0.375rem;
    line-height: 1;
    flex-shrink: 0;
}

.btn-remove-sm:hover {
    color: var(--color-status-red);
}

.btn-primary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1.25rem;
    background: var(--color-accent);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: opacity 0.15s;
}

.btn-primary:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-secondary {
    padding: 0.5rem 1.25rem;
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
}

.btn-secondary:hover:not(:disabled) {
    background: var(--color-bg-secondary);
    border-color: var(--color-border-hover);
}

.btn-secondary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.flex-1 {
    flex: 1;
}

/* Milestone Cards */
.milestone-card {
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    margin-bottom: 0.75rem;
    overflow: hidden;
}

.milestone-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    cursor: pointer;
    transition: background 0.15s;
}

.milestone-header:hover {
    background: var(--color-bg-secondary);
}

.collapse-chevron {
    color: var(--color-text-quaternary);
    transition: transform 0.2s;
    flex-shrink: 0;
}

.collapse-chevron.expanded {
    transform: rotate(90deg);
}

.milestone-name-input {
    flex: 1;
    background: transparent;
    border: none;
    color: var(--color-text-primary);
    font-size: 0.875rem;
    font-weight: 600;
    padding: 0;
    outline: none;
}

.milestone-name-input:focus {
    color: var(--color-accent);
}

.task-count {
    font-size: 0.75rem;
    color: var(--color-text-quaternary);
    flex-shrink: 0;
}

.milestone-body {
    padding: 0 0.75rem 0.75rem;
}

/* Task Rows */
.task-row {
    padding: 0.5rem;
    margin-bottom: 0.5rem;
    background: var(--color-bg-secondary);
    border-radius: 6px;
    border: 1px solid var(--color-border-subtle);
}

.task-main {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.375rem;
}

.task-title-input {
    flex: 1;
}

.task-fields {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

.task-desc {
    resize: vertical;
}

.btn-add-task {
    background: none;
    border: 1px dashed var(--color-border-default);
    color: var(--color-text-tertiary);
    font-size: 0.8125rem;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    cursor: pointer;
    width: 100%;
    text-align: center;
    transition: all 0.15s;
    margin-top: 0.25rem;
}

.btn-add-task:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
}

/* Subtasks */
.subtask-list {
    margin-top: 0.375rem;
    padding-left: 1rem;
}

.subtask-item {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    margin-bottom: 0.25rem;
}

.subtask-bullet {
    color: var(--color-text-quaternary);
    font-size: 0.75rem;
}

.subtask-input {
    flex: 1;
    width: auto;
}

/* Empty Hint */
.empty-hint {
    font-size: 0.8125rem;
    color: var(--color-text-quaternary);
    font-style: italic;
}

/* Confirm Step */
.confirm-summary {
    text-align: center;
    padding: 1rem 0;
}

.confirm-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
}

.confirm-description {
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
    margin-bottom: 1.5rem;
}

.summary-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: center;
}

.summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    padding: 1rem 1.5rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 10px;
    min-width: 100px;
}

.summary-count {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-accent);
}

.summary-label {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-secondary);
}

.summary-detail {
    font-size: 0.75rem;
    color: var(--color-text-quaternary);
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Spinner */
.spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
