<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormSelect from '@/Components/FormSelect.vue';
import { ref, computed } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';

interface Client {
    id: number;
    name: string;
    billing_email: string | null;
}

interface InvoiceLine {
    id: number;
    type: 'time' | 'fixed' | 'expense' | 'discount';
    description: string;
    details: string | null;
    quantity: string;
    unit: string | null;
    unit_price: string;
    amount: string;
}

interface Payment {
    id: number;
    amount: string;
    method: string;
    transaction_id: string | null;
    reference: string | null;
    payment_date: string;
    notes: string | null;
    status: string;
}

interface Reminder {
    id: number;
    type: string;
    days_offset: number;
    scheduled_at: string;
    sent_at: string | null;
    status: 'pending' | 'sent' | 'cancelled' | 'failed';
}

interface Contact {
    id: number | null;
    name: string;
    email: string;
    role: string | null;
    is_primary: boolean;
}

interface Activity {
    id: number;
    type: string;
    description: string;
    metadata: Record<string, any> | null;
    user: { id: number; name: string } | null;
    created_at: string;
}

interface Invoice {
    id: number;
    number: string;
    subject: string | null;
    notes: string | null;
    internal_notes: string | null;
    status: string;
    subtotal: string;
    tax_rate: string;
    tax_amount: string;
    total: string;
    amount_paid: string;
    amount_due: string;
    issue_date: string;
    due_date: string;
    sent_at: string | null;
    viewed_at: string | null;
    paid_at: string | null;
    payment_terms: string | null;
    currency: string;
    po_number: string | null;
    paypal_invoice_id: string | null;
    days_to_pay: number | null;
    days_overdue: number;
    public_url: string;
    client: Client;
    project: { id: number; name: string } | null;
    lines: InvoiceLine[];
    payments: Payment[];
    reminders: Reminder[];
    activities: Activity[];
    reminders_disabled: boolean;
    pending_review: boolean;
    pending_review_reason: string | null;
    retainer_period_id: number | null;
    retainer_report_url?: string | null;
}

const props = defineProps<{
    invoice: Invoice;
    contacts: Contact[];
}>();

const showSendModal = ref(false);
const showPaymentModal = ref(false);
const showCancelModal = ref(false);
const isSending = ref(false);
const isSendingTest = ref(false);
const isCancelling = ref(false);
const cancelReason = ref('');

// Recipient selection — pre-select primary contact or billing email
const selectedRecipients = ref<string[]>(
    props.contacts
        .filter(c => c.is_primary || c.role === 'billing')
        .map(c => c.email)
        .slice(0, 1) // Default to first match
    || (props.contacts.length > 0 ? [props.contacts[0].email] : [])
);

const toggleRecipient = (email: string) => {
    const idx = selectedRecipients.value.indexOf(email);
    if (idx === -1) {
        selectedRecipients.value.push(email);
    } else {
        selectedRecipients.value.splice(idx, 1);
    }
};

const paymentForm = useForm({
    amount: parseFloat(props.invoice.amount_due) || 0,
    method: 'ach',
    payment_date: new Date().toISOString().split('T')[0],
    transaction_id: '',
    reference: '',
    notes: '',
});

const paymentMethodOptions = [
    { value: 'ach', label: 'ACH/Bank Transfer' },
    { value: 'check', label: 'Check' },
    { value: 'wire', label: 'Wire Transfer' },
    { value: 'paypal', label: 'PayPal' },
    { value: 'other', label: 'Other' },
];

const canEdit = computed(() => !['paid', 'cancelled'].includes(props.invoice.status));
const canSend = computed(() => !['paid', 'cancelled'].includes(props.invoice.status));
const canRecordPayment = computed(() =>
    ['sent', 'viewed', 'partial', 'overdue'].includes(props.invoice.status) &&
    parseFloat(props.invoice.amount_due) > 0
);
const canCancel = computed(() => !['paid', 'cancelled'].includes(props.invoice.status));
const canDuplicate = computed(() => true);

// --- Reminder controls ---
const showAddReminder = ref(false);
const newReminderAt = ref('');
const editingReminderId = ref<number | null>(null);
const editingReminderAt = ref('');

const addReminder = () => {
    if (!newReminderAt.value) return;
    router.post(`/invoices/${props.invoice.id}/reminders`, { scheduled_at: newReminderAt.value }, {
        preserveScroll: true,
        onSuccess: () => {
            showAddReminder.value = false;
            newReminderAt.value = '';
        },
    });
};

const startEditingReminder = (reminder: Reminder) => {
    editingReminderId.value = reminder.id;
    // datetime-local needs YYYY-MM-DDTHH:MM
    const d = new Date(reminder.scheduled_at);
    const pad = (n: number) => String(n).padStart(2, '0');
    editingReminderAt.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const saveReminderEdit = (reminder: Reminder) => {
    router.put(`/invoices/${props.invoice.id}/reminders/${reminder.id}`, {
        scheduled_at: editingReminderAt.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { editingReminderId.value = null; },
    });
};

const cancelReminder = (reminder: Reminder) => {
    if (!confirm('Cancel this reminder? It will not be sent.')) return;
    router.delete(`/invoices/${props.invoice.id}/reminders/${reminder.id}`, { preserveScroll: true });
};

const toggleRemindersDisabled = (value: boolean) => {
    const msg = value
        ? 'Disable all reminders for this invoice? Existing pending reminders will be cancelled.'
        : 'Re-enable reminders for this invoice? The schedule will be rebuilt from the resolved schedule.';
    if (!confirm(msg)) return;
    router.put(`/invoices/${props.invoice.id}/reminders-disabled`, { reminders_disabled: value }, { preserveScroll: true });
};

const formatCurrency = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount;
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: props.invoice.currency || 'USD' }).format(num);
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const formatDateTime = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit'
    });
};

const statusClass = (status: string) => {
    const classes: Record<string, string> = {
        draft: 'badge-draft',
        sent: 'badge-sent',
        viewed: 'badge-viewed',
        partial: 'badge-partial',
        paid: 'badge-paid',
        overdue: 'badge-overdue',
        cancelled: 'badge-cancelled',
    };
    return classes[status] || 'badge-draft';
};

const sendInvoice = () => {
    if (selectedRecipients.value.length === 0) return;
    isSending.value = true;
    router.post(`/invoices/${props.invoice.id}/send`, {
        recipients: selectedRecipients.value,
    }, {
        onSuccess: () => {
            showSendModal.value = false;
        },
        onFinish: () => {
            isSending.value = false;
        },
    });
};

const sendTestEmail = () => {
    isSendingTest.value = true;
    router.post(`/invoices/${props.invoice.id}/send`, {
        is_test: true,
    }, {
        onFinish: () => {
            isSendingTest.value = false;
        },
    });
};

const regeneratePdf = () => {
    router.post(`/invoices/${props.invoice.id}/regenerate-pdf`);
};

const copyPublicUrl = () => {
    navigator.clipboard.writeText(props.invoice.public_url);
};

const recordPayment = () => {
    paymentForm.post(`/invoices/${props.invoice.id}/payments`, {
        onSuccess: () => {
            showPaymentModal.value = false;
            paymentForm.reset();
        },
    });
};

const deletePayment = (paymentId: number) => {
    if (confirm('Are you sure you want to delete this payment?')) {
        router.delete(`/invoices/${props.invoice.id}/payments/${paymentId}`);
    }
};

const cancelInvoice = () => {
    isCancelling.value = true;
    router.post(`/invoices/${props.invoice.id}/cancel`, { reason: cancelReason.value }, {
        onSuccess: () => {
            showCancelModal.value = false;
            cancelReason.value = '';
        },
        onFinish: () => {
            isCancelling.value = false;
        },
    });
};

const duplicateInvoice = () => {
    router.post(`/invoices/${props.invoice.id}/duplicate`);
};
</script>

<template>
    <AppLayout :title="`Invoice #${invoice.number}`">
        <div class="invoice-page">
            <!-- Pending review banner -->
            <div v-if="invoice.pending_review" class="review-banner">
                <div class="review-banner-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3l-7.07-12a2 2 0 00-3.48 0L3.19 16a2 2 0 001.74 3z" />
                    </svg>
                </div>
                <div class="review-banner-body">
                    <div class="review-banner-title">Held for review — auto-send was skipped</div>
                    <div class="review-banner-text">{{ invoice.pending_review_reason || 'Manual review required before sending.' }}</div>
                    <a v-if="invoice.retainer_report_url" :href="invoice.retainer_report_url" target="_blank" class="review-banner-link">
                        View the retainer report →
                    </a>
                </div>
            </div>

            <!-- Header -->
            <div class="page-header">
                <div>
                    <div class="header-row">
                        <Link href="/invoices" class="back-link">
                            <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </Link>
                        <h1 class="page-title">Invoice #{{ invoice.number }}</h1>
                        <span :class="['badge badge-lg', statusClass(invoice.status)]">
                            {{ invoice.status }}
                        </span>
                    </div>
                    <p v-if="invoice.subject" class="invoice-subject">{{ invoice.subject }}</p>
                </div>
                <div class="header-actions">
                    <Link
                        v-if="canEdit"
                        :href="`/invoices/${invoice.id}/edit`"
                        class="btn btn-secondary"
                    >
                        Edit
                    </Link>
                    <a :href="`/invoices/${invoice.id}/pdf`" target="_blank" class="btn btn-secondary">
                        Preview PDF
                    </a>
                    <a :href="`/invoices/${invoice.id}/pdf/download`" class="btn btn-secondary">
                        Download
                    </a>
                    <button
                        @click="regeneratePdf"
                        class="btn btn-secondary"
                        title="Regenerate PDF with latest template"
                    >
                        ↻ Regen PDF
                    </button>
                    <button
                        v-if="canRecordPayment"
                        @click="showPaymentModal = true"
                        class="btn btn-secondary"
                    >
                        Record Payment
                    </button>
                    <button
                        v-if="canDuplicate"
                        @click="duplicateInvoice"
                        class="btn btn-secondary"
                    >
                        Duplicate
                    </button>
                    <button
                        v-if="canSend"
                        @click="sendTestEmail"
                        :disabled="isSendingTest"
                        class="btn btn-secondary"
                        title="Send a test email to yourself"
                    >
                        {{ isSendingTest ? 'Sending...' : 'Test Email' }}
                    </button>
                    <button
                        v-if="canCancel"
                        @click="showCancelModal = true"
                        class="btn btn-danger-outline"
                    >
                        Cancel
                    </button>
                    <button
                        v-if="canSend"
                        @click="showSendModal = true"
                        class="btn btn-primary"
                    >
                        {{ invoice.status === 'draft' ? 'Send Invoice' : 'Resend Invoice' }}
                    </button>
                </div>
            </div>

            <div class="content-grid">
                <!-- Info Panel -->
                <div class="info-panel">
                    <!-- Client Info -->
                    <div class="card">
                        <h3 class="card-title">Client</h3>
                        <dl class="detail-list">
                            <div class="detail-item">
                                <dt>Name</dt>
                                <dd>
                                    <Link :href="`/clients/${invoice.client.id}`" class="client-link">
                                        {{ invoice.client.name }}
                                    </Link>
                                </dd>
                            </div>
                            <div v-if="invoice.project" class="detail-item">
                                <dt>Project</dt>
                                <dd>{{ invoice.project.name }}</dd>
                            </div>
                            <div v-if="invoice.client.billing_email" class="detail-item">
                                <dt>Billing Email</dt>
                                <dd>{{ invoice.client.billing_email }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Dates -->
                    <div class="card">
                        <h3 class="card-title">Dates</h3>
                        <dl class="detail-list">
                            <div class="detail-item">
                                <dt>Issue Date</dt>
                                <dd>{{ formatDate(invoice.issue_date) }}</dd>
                            </div>
                            <div class="detail-item">
                                <dt>Due Date</dt>
                                <dd :class="{ 'text-danger': invoice.days_overdue > 0 }">
                                    {{ formatDate(invoice.due_date) }}
                                    <span v-if="invoice.days_overdue > 0" class="overdue-badge">
                                        {{ invoice.days_overdue }}d overdue
                                    </span>
                                </dd>
                            </div>
                            <div v-if="invoice.sent_at" class="detail-item">
                                <dt>Sent</dt>
                                <dd>{{ formatDateTime(invoice.sent_at) }}</dd>
                            </div>
                            <div v-if="invoice.viewed_at" class="detail-item">
                                <dt>Viewed</dt>
                                <dd>{{ formatDateTime(invoice.viewed_at) }}</dd>
                            </div>
                            <div v-if="invoice.paid_at" class="detail-item">
                                <dt>Paid</dt>
                                <dd class="text-success">
                                    {{ formatDateTime(invoice.paid_at) }}
                                    <span v-if="invoice.days_to_pay !== null" class="days-badge">
                                        ({{ invoice.days_to_pay }}d)
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Totals -->
                    <div class="card">
                        <h3 class="card-title">Totals</h3>
                        <dl class="detail-list">
                            <div class="detail-item">
                                <dt>Subtotal</dt>
                                <dd>{{ formatCurrency(invoice.subtotal) }}</dd>
                            </div>
                            <div v-if="parseFloat(invoice.tax_rate) > 0" class="detail-item">
                                <dt>Tax ({{ invoice.tax_rate }}%)</dt>
                                <dd>{{ formatCurrency(invoice.tax_amount) }}</dd>
                            </div>
                            <div class="detail-item total-row">
                                <dt>Total</dt>
                                <dd class="total-value">{{ formatCurrency(invoice.total) }}</dd>
                            </div>
                            <div v-if="parseFloat(invoice.amount_paid) > 0" class="detail-item">
                                <dt>Paid</dt>
                                <dd class="text-success">-{{ formatCurrency(invoice.amount_paid) }}</dd>
                            </div>
                            <div class="detail-item">
                                <dt>Amount Due</dt>
                                <dd :class="['amount-due', { 'text-success': parseFloat(invoice.amount_due) === 0 }]">
                                    {{ formatCurrency(invoice.amount_due) }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Public Link -->
                    <div class="card">
                        <h3 class="card-title">Public Link</h3>
                        <div class="public-url-container">
                            <input type="text" :value="invoice.public_url" readonly class="url-input" />
                            <button @click="copyPublicUrl" class="copy-btn">Copy</button>
                        </div>
                        <a :href="invoice.public_url" target="_blank" class="view-public-link">
                            Open public view
                        </a>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="main-content">
                    <!-- Line Items -->
                    <div class="card">
                        <div class="table-header">
                            <h3 class="card-title">Line Items</h3>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>Type</th>
                                        <th class="text-right">Qty</th>
                                        <th class="text-right">Rate</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="line in invoice.lines" :key="line.id">
                                        <td>
                                            <div class="line-description">{{ line.description }}</div>
                                            <div v-if="line.details" class="line-details">{{ line.details }}</div>
                                        </td>
                                        <td>
                                            <span :class="['type-badge', `type-${line.type}`]">{{ line.type }}</span>
                                        </td>
                                        <td class="text-right">
                                            {{ parseFloat(line.quantity).toFixed(2) }}
                                            <span v-if="line.unit" class="unit">{{ line.unit }}</span>
                                        </td>
                                        <td class="text-right">{{ formatCurrency(line.unit_price) }}</td>
                                        <td class="text-right" :class="{ 'text-danger': parseFloat(line.amount) < 0 }">
                                            {{ formatCurrency(line.amount) }}
                                        </td>
                                    </tr>
                                    <tr v-if="invoice.lines.length === 0">
                                        <td colspan="5" class="empty-state">No line items</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payments -->
                    <div class="card">
                        <div class="table-header">
                            <h3 class="card-title">Payments</h3>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th class="text-right">Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="payment in invoice.payments" :key="payment.id">
                                        <td>{{ formatDate(payment.payment_date) }}</td>
                                        <td class="capitalize">{{ payment.method }}</td>
                                        <td class="text-secondary">{{ payment.transaction_id || payment.reference || '-' }}</td>
                                        <td class="text-right text-success">{{ formatCurrency(payment.amount) }}</td>
                                        <td>
                                            <span :class="['badge', payment.status === 'completed' ? 'badge-paid' : 'badge-sent']">
                                                {{ payment.status }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <button
                                                @click="deletePayment(payment.id)"
                                                class="delete-payment-btn"
                                                title="Delete payment"
                                            >
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" class="delete-icon">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="invoice.payments.length === 0">
                                        <td colspan="5" class="empty-state">No payments recorded</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Reminders -->
                    <div class="card">
                        <div class="table-header">
                            <h3 class="card-title">Reminders</h3>
                            <div class="reminders-header-actions">
                                <label class="toggle-disable">
                                    <input
                                        type="checkbox"
                                        :checked="invoice.reminders_disabled"
                                        @change="toggleRemindersDisabled(!invoice.reminders_disabled)"
                                    />
                                    Reminders disabled for this invoice
                                </label>
                                <button
                                    v-if="!invoice.reminders_disabled && canSend"
                                    class="btn btn-secondary btn-sm"
                                    @click="showAddReminder = !showAddReminder"
                                >
                                    + Add reminder
                                </button>
                            </div>
                        </div>

                        <div v-if="showAddReminder" class="add-reminder-row">
                            <input
                                v-model="newReminderAt"
                                type="datetime-local"
                                class="form-input"
                            />
                            <button class="btn btn-primary btn-sm" @click="addReminder">Schedule</button>
                            <button class="btn btn-tertiary btn-sm" @click="showAddReminder = false">Cancel</button>
                        </div>

                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Scheduled</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="reminder in invoice.reminders" :key="reminder.id">
                                        <td class="capitalize">{{ reminder.type.replace('_', ' ') }}</td>
                                        <td>
                                            <template v-if="editingReminderId === reminder.id">
                                                <input
                                                    v-model="editingReminderAt"
                                                    type="datetime-local"
                                                    class="form-input form-input-sm"
                                                />
                                            </template>
                                            <template v-else>
                                                {{ formatDateTime(reminder.scheduled_at) }}
                                            </template>
                                        </td>
                                        <td>
                                            <span v-if="reminder.sent_at" class="text-success">Sent {{ formatDateTime(reminder.sent_at) }}</span>
                                            <span v-else-if="reminder.status === 'cancelled'" class="text-secondary">Cancelled</span>
                                            <span v-else-if="reminder.status === 'failed'" class="text-danger">Failed</span>
                                            <span v-else class="text-secondary">Pending</span>
                                        </td>
                                        <td class="reminder-row-actions">
                                            <template v-if="reminder.status === 'pending'">
                                                <template v-if="editingReminderId === reminder.id">
                                                    <button class="btn btn-primary btn-sm" @click="saveReminderEdit(reminder)">Save</button>
                                                    <button class="btn btn-tertiary btn-sm" @click="editingReminderId = null">Cancel</button>
                                                </template>
                                                <template v-else>
                                                    <button class="link-action" @click="startEditingReminder(reminder)">Edit</button>
                                                    <button class="link-action text-danger" @click="cancelReminder(reminder)">Cancel</button>
                                                </template>
                                            </template>
                                        </td>
                                    </tr>
                                    <tr v-if="invoice.reminders.length === 0">
                                        <td colspan="4" class="empty-state">
                                            <span v-if="invoice.reminders_disabled">Reminders are disabled for this invoice.</span>
                                            <span v-else>No reminders scheduled.</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div v-if="invoice.notes || invoice.internal_notes" class="notes-grid">
                        <div v-if="invoice.notes" class="card">
                            <h3 class="card-title">Client Notes</h3>
                            <p class="notes-text">{{ invoice.notes }}</p>
                        </div>
                        <div v-if="invoice.internal_notes" class="card">
                            <h3 class="card-title">Internal Notes</h3>
                            <p class="notes-text">{{ invoice.internal_notes }}</p>
                        </div>
                    </div>

                    <!-- Activity Timeline -->
                    <div v-if="invoice.activities && invoice.activities.length > 0" class="card">
                        <div class="table-header">
                            <h3 class="card-title">Activity History</h3>
                        </div>
                        <div class="activity-timeline">
                            <div
                                v-for="activity in invoice.activities"
                                :key="activity.id"
                                class="activity-item"
                            >
                                <div class="activity-icon" :class="`activity-icon-${activity.type}`">
                                    <svg v-if="activity.type === 'created'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                                    <svg v-else-if="activity.type === 'sent' || activity.type === 'test_sent'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                                    <svg v-else-if="activity.type === 'viewed'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                    <svg v-else-if="activity.type === 'payment_recorded'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    <svg v-else-if="activity.type === 'cancelled'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    <svg v-else-if="activity.type === 'auto_send_failed'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3l-7.07-12a2 2 0 00-3.48 0L3.19 16a2 2 0 001.74 3z" /></svg>
                                    <svg v-else fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </div>
                                <div class="activity-content">
                                    <p class="activity-description">{{ activity.description }}</p>
                                    <div class="activity-meta">
                                        <span v-if="activity.user" class="activity-user">{{ activity.user.name }}</span>
                                        <span class="activity-time">{{ formatDateTime(activity.created_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Send Invoice Modal with Recipient Selection -->
        <Modal :show="showSendModal" @close="showSendModal = false" max-width="lg">
            <div class="modal-content">
                <h3 class="modal-title">{{ invoice.status === 'draft' ? 'Send Invoice' : 'Resend Invoice' }}</h3>
                <p class="modal-text">
                    Select recipients for invoice #{{ invoice.number }}.
                    A PayPal payment link will be included if available.
                </p>

                <div v-if="contacts.length > 0" class="recipient-list">
                    <label class="form-label">Recipients</label>
                    <div
                        v-for="contact in contacts"
                        :key="contact.email"
                        class="recipient-item"
                        @click="toggleRecipient(contact.email)"
                    >
                        <input
                            type="checkbox"
                            :checked="selectedRecipients.includes(contact.email)"
                            class="recipient-checkbox"
                            @click.stop="toggleRecipient(contact.email)"
                        />
                        <div class="recipient-info">
                            <span class="recipient-name">{{ contact.name }}</span>
                            <span class="recipient-email">{{ contact.email }}</span>
                        </div>
                        <span v-if="contact.role" class="recipient-role">{{ contact.role }}</span>
                    </div>
                </div>
                <div v-else class="modal-text">
                    No contacts found. The invoice will be sent to the client's billing email if available.
                </div>

                <div class="modal-actions">
                    <button type="button" @click="showSendModal = false" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="sendInvoice"
                        :disabled="isSending || selectedRecipients.length === 0"
                        class="btn btn-primary"
                    >
                        {{ isSending ? 'Sending...' : `Send to ${selectedRecipients.length} recipient${selectedRecipients.length !== 1 ? 's' : ''}` }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Record Payment Modal -->
        <Modal :show="showPaymentModal" @close="showPaymentModal = false" max-width="lg">
            <form @submit.prevent="recordPayment" class="modal-content">
                <h3 class="modal-title">Record Payment</h3>
                <p class="modal-subtitle">
                    Recording payment for invoice #{{ invoice.number }}
                </p>

                <div class="modal-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <div class="input-with-prefix">
                                <span class="input-prefix">$</span>
                                <input
                                    type="number"
                                    v-model.number="paymentForm.amount"
                                    class="form-input with-prefix"
                                    step="0.01"
                                    min="0"
                                    :max="parseFloat(invoice.amount_due)"
                                    required
                                />
                            </div>
                            <p class="form-hint">Amount due: {{ formatCurrency(invoice.amount_due) }}</p>
                            <p v-if="paymentForm.errors.amount" class="form-error">{{ paymentForm.errors.amount }}</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Date</label>
                            <input
                                type="date"
                                v-model="paymentForm.payment_date"
                                class="form-input"
                                required
                            />
                            <p v-if="paymentForm.errors.payment_date" class="form-error">{{ paymentForm.errors.payment_date }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <FormSelect
                            v-model="paymentForm.method"
                            :options="paymentMethodOptions"
                        />
                        <p v-if="paymentForm.errors.method" class="form-error">{{ paymentForm.errors.method }}</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Transaction ID</label>
                            <input
                                type="text"
                                v-model="paymentForm.transaction_id"
                                class="form-input"
                                placeholder="e.g., PayPal transaction ID"
                            />
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reference</label>
                            <input
                                type="text"
                                v-model="paymentForm.reference"
                                class="form-input"
                                placeholder="e.g., Check #1234"
                            />
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea
                            v-model="paymentForm.notes"
                            class="form-textarea"
                            rows="2"
                            placeholder="Optional notes about this payment..."
                        ></textarea>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" @click="showPaymentModal = false" class="btn btn-secondary">Cancel</button>
                    <button type="submit" :disabled="paymentForm.processing" class="btn btn-primary">
                        {{ paymentForm.processing ? 'Recording...' : 'Record Payment' }}
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Cancel Invoice Modal -->
        <Modal :show="showCancelModal" @close="showCancelModal = false">
            <div class="modal-content">
                <h3 class="modal-title">Cancel Invoice</h3>
                <p class="modal-text">
                    Are you sure you want to cancel invoice #{{ invoice.number }}?
                    This will unmark any associated time entries as billed.
                </p>

                <div class="form-group">
                    <label class="form-label">Reason (optional)</label>
                    <textarea
                        v-model="cancelReason"
                        class="form-textarea"
                        rows="2"
                        placeholder="Reason for cancellation..."
                    ></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" @click="showCancelModal = false" class="btn btn-secondary">Keep Invoice</button>
                    <button @click="cancelInvoice" :disabled="isCancelling" class="btn btn-danger">
                        {{ isCancelling ? 'Cancelling...' : 'Cancel Invoice' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.invoice-page {
    max-width: 80rem;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
}

.header-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.back-link {
    color: var(--color-text-secondary);
}

.back-link:hover {
    color: var(--color-text-primary);
}

.back-icon {
    width: 1.25rem;
    height: 1.25rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.invoice-subject {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-top: 0.25rem;
}

.header-actions {
    display: flex;
    gap: 0.5rem;
}

.btn {
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-weight: 500;
    transition: all 0.15s;
}

.btn-primary {
    background: var(--color-accent);
    color: white;
}

.btn-primary:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-secondary {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border-default);
}

.btn-secondary:hover {
    background: var(--color-bg-elevated);
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-danger {
    background: var(--color-status-red);
    color: white;
}

.btn-danger:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-danger-outline {
    background: transparent;
    color: var(--color-status-red);
    border: 1px solid var(--color-status-red);
}

.btn-danger-outline:hover {
    background: rgba(239, 68, 68, 0.1);
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 1024px) {
    .content-grid {
        grid-template-columns: 300px 1fr;
    }
}

.info-panel {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.main-content {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 0.5rem;
    padding: 1.5rem;
}

.card-title {
    font-size: 1rem;
    font-weight: 500;
    color: var(--color-text-primary);
    margin-bottom: 1rem;
}

.detail-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detail-item dt {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
}

.detail-item dd {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.total-row dt, .total-row dd {
    font-size: 1rem;
}

.total-value {
    font-size: 1.25rem !important;
    font-weight: 700 !important;
}

.amount-due {
    font-size: 1.25rem !important;
    font-weight: 700 !important;
    color: var(--color-accent) !important;
}

.text-success { color: var(--color-status-green); }
.text-danger { color: var(--color-status-red); }
.text-secondary { color: var(--color-text-secondary); }

.overdue-badge, .days-badge {
    font-size: 0.75rem;
    font-weight: 400;
}

.overdue-badge {
    color: var(--color-status-red);
}

.client-link {
    color: var(--color-accent);
}

.client-link:hover {
    text-decoration: underline;
}

.public-url-container {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.url-input {
    flex: 1;
    padding: 0.5rem;
    font-size: 0.75rem;
    border-radius: 0.375rem;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.copy-btn {
    padding: 0.5rem 0.75rem;
    font-size: 0.75rem;
    border-radius: 0.375rem;
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border-default);
}

.copy-btn:hover {
    background: var(--color-bg-elevated);
}

.view-public-link {
    font-size: 0.875rem;
    color: var(--color-accent);
}

.view-public-link:hover {
    text-decoration: underline;
}

.table-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.table-header .card-title {
    margin-bottom: 0;
}

.card:has(.table-header) {
    padding: 0;
}

.table-wrapper {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    padding: 0.75rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
}

.data-table td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
    vertical-align: top;
}

.text-right {
    text-align: right;
}

.line-description {
    font-weight: 500;
    color: var(--color-text-primary);
}

.line-details {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    margin-top: 0.25rem;
}

.type-badge {
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    text-transform: uppercase;
    font-weight: 600;
}

.type-time { background: rgba(99, 102, 241, 0.15); color: var(--color-accent); }
.type-fixed { background: rgba(16, 185, 129, 0.15); color: var(--color-status-green); }
.type-expense { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
.type-discount { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }

.unit {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 9999px;
    text-transform: capitalize;
}

.badge-lg {
    padding: 0.25rem 0.75rem;
    font-size: 0.875rem;
}

.badge-draft { background: var(--color-bg-tertiary); color: var(--color-text-secondary); }
.badge-sent { background: rgba(99, 102, 241, 0.15); color: var(--color-accent); }
.badge-viewed { background: rgba(139, 92, 246, 0.15); color: #a855f7; }
.badge-partial { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
.badge-paid { background: rgba(16, 185, 129, 0.15); color: var(--color-status-green); }
.badge-overdue { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }
.badge-cancelled { background: rgba(107, 114, 128, 0.15); color: #6b7280; }

.capitalize {
    text-transform: capitalize;
}

.empty-state {
    padding: 2rem 1.5rem;
    text-align: center;
    color: var(--color-text-secondary);
}

.notes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.notes-text {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    white-space: pre-wrap;
}

/* Modal */
.modal-content {
    padding: 1.5rem;
}

.modal-title {
    font-size: 1.125rem;
    font-weight: 500;
    color: var(--color-text-primary);
    margin-bottom: 1rem;
}

.modal-text {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 1.5rem;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.modal-subtitle {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 1.5rem;
}

.modal-form {
    margin-bottom: 1.5rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.375rem;
}

.form-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 0.375rem;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
}

.form-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.input-with-prefix {
    display: flex;
    align-items: center;
}

.input-prefix {
    padding: 0.5rem 0.75rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-right: none;
    border-radius: 0.375rem 0 0 0.375rem;
    color: var(--color-text-secondary);
}

.form-input.with-prefix {
    border-radius: 0 0.375rem 0.375rem 0;
}

.form-hint {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.form-error {
    font-size: 0.75rem;
    color: var(--color-status-red);
    margin-top: 0.25rem;
}

.form-textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 0.375rem;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
    resize: vertical;
}

.form-textarea:focus {
    outline: none;
    border-color: var(--color-accent);
}

.delete-payment-btn {
    padding: 0.25rem;
    color: var(--color-text-tertiary);
    border-radius: 0.25rem;
    opacity: 0;
    transition: all 0.15s;
}

tr:hover .delete-payment-btn {
    opacity: 1;
}

.delete-payment-btn:hover {
    color: var(--color-status-red);
    background: rgba(239, 68, 68, 0.1);
}

.delete-icon {
    width: 1rem;
    height: 1rem;
}

/* Recipient selection */
.recipient-list {
    margin-bottom: 1.5rem;
}

.recipient-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    border: 1px solid var(--color-border-default);
    border-radius: 0.5rem;
    margin-top: 0.5rem;
    cursor: pointer;
    transition: all 0.15s;
}

.recipient-item:hover {
    background: var(--color-bg-tertiary);
    border-color: var(--color-accent);
}

.recipient-checkbox {
    width: 1rem;
    height: 1rem;
    border-radius: 0.25rem;
    cursor: pointer;
    accent-color: var(--color-accent);
}

.recipient-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.recipient-name {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.recipient-email {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.recipient-role {
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    text-transform: capitalize;
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
}

/* Activity timeline */
.activity-timeline {
    padding: 1rem 1.5rem;
}

.activity-item {
    display: flex;
    gap: 0.75rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--color-border-subtle);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    flex-shrink: 0;
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.activity-icon svg {
    width: 1rem;
    height: 1rem;
}

.activity-icon-created { background: rgba(16, 185, 129, 0.15); color: var(--color-status-green); }
.activity-icon-sent { background: rgba(99, 102, 241, 0.15); color: var(--color-accent); }
.activity-icon-test_sent { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
.activity-icon-viewed { background: rgba(139, 92, 246, 0.15); color: #a855f7; }
.activity-icon-payment_recorded { background: rgba(16, 185, 129, 0.15); color: var(--color-status-green); }
.activity-icon-cancelled { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }
.activity-icon-updated { background: rgba(99, 102, 241, 0.15); color: var(--color-accent); }
.activity-icon-auto_send_failed { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red, #dc2626); }
.activity-icon-held_for_review { background: rgba(245, 158, 11, 0.15); color: #d97706; }

.review-banner {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    padding: 1rem 1.25rem;
    margin-bottom: 1rem;
    background: rgba(245, 158, 11, 0.08);
    border: 1px solid rgba(245, 158, 11, 0.4);
    border-radius: 0.5rem;
}
.review-banner-icon { color: #d97706; flex-shrink: 0; padding-top: 2px; }
.review-banner-body { flex: 1; }
.review-banner-title {
    font-weight: 600;
    color: #b45309;
    margin-bottom: 0.25rem;
}
.review-banner-text {
    color: var(--color-text-secondary, #6b7280);
    font-size: 0.875rem;
    line-height: 1.5;
}
.review-banner-link {
    display: inline-block;
    margin-top: 0.5rem;
    font-size: 0.8125rem;
    color: var(--color-accent, #2563eb);
    text-decoration: underline;
}

.activity-content {
    flex: 1;
    min-width: 0;
}

.activity-description {
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.activity-meta {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.25rem;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.activity-user {
    font-weight: 500;
    color: var(--color-text-secondary);
}

.reminders-header-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.toggle-disable {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
}
.add-reminder-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: var(--surface-muted, #f9fafb);
    border-bottom: 1px solid var(--border, #e5e7eb);
}
.form-input-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.8125rem;
}
.reminder-row-actions {
    display: flex;
    gap: 0.5rem;
}
.link-action {
    background: none;
    border: none;
    color: var(--primary, #2563eb);
    cursor: pointer;
    padding: 0;
    font-size: 0.875rem;
    text-decoration: underline;
}
.link-action.text-danger { color: var(--danger, #dc2626); }
.text-danger { color: var(--danger, #dc2626); }
.btn-sm {
    padding: 0.25rem 0.625rem;
    font-size: 0.8125rem;
}
</style>
