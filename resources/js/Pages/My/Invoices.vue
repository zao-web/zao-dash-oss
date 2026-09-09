<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, Link } from '@inertiajs/vue3';

interface Invoice {
    id: number;
    invoice_number: string;
    amount: number;
    currency: string;
    description: string;
    status: string;
    invoice_date: string;
    due_date: string | null;
    approved_at: string | null;
    paid_at: string | null;
}

const props = defineProps<{
    invoices: {
        data: Invoice[];
        links: any[];
        meta: any;
    };
    canSubmitInvoices: boolean;
    setupRequired: boolean;
}>();

const formatCurrency = (amount: number, currency = 'USD') => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount);
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, { class: string; label: string }> = {
        draft: { class: 'bg-zinc-500/20 text-zinc-400', label: 'Draft' },
        submitted: { class: 'bg-blue-500/20 text-blue-400', label: 'Submitted' },
        approved: { class: 'bg-green-500/20 text-green-400', label: 'Approved' },
        rejected: { class: 'bg-red-500/20 text-red-400', label: 'Rejected' },
        paid: { class: 'bg-purple-500/20 text-purple-400', label: 'Paid' },
    };
    return badges[status] || badges.draft;
};

const submitInvoice = (invoice: Invoice) => {
    router.post(`/my/invoices/${invoice.id}/submit`);
};
</script>

<template>
    <AppLayout title="My Invoices">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-semibold text-[var(--color-text-primary)]">My Invoices</h1>
                    <p class="text-sm text-[var(--color-text-tertiary)]">Submit invoices for payment</p>
                </div>
                <Link
                    v-if="canSubmitInvoices"
                    href="/my/invoices/create"
                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                >
                    New Invoice
                </Link>
            </div>

            <!-- Setup Required Banner -->
            <div v-if="setupRequired" class="mb-6 p-4 bg-yellow-500/10 border border-yellow-500/30 rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-yellow-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <span class="text-yellow-500">Complete payment setup to submit invoices</span>
                    </div>
                    <Link href="/my/payment-setup" class="text-sm text-yellow-400 hover:underline">
                        Complete Setup
                    </Link>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="card overflow-hidden">
                <table class="min-w-full divide-y divide-[var(--color-border-default)]">
                    <thead class="bg-[var(--color-bg-tertiary)]">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Invoice</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-default)]">
                        <tr v-for="invoice in invoices.data" :key="invoice.id" class="hover:bg-[var(--color-bg-tertiary)]">
                            <td class="px-6 py-4">
                                <div class="font-medium text-[var(--color-text-primary)]">{{ invoice.invoice_number || `INV-${invoice.id}` }}</div>
                                <div class="text-sm text-[var(--color-text-tertiary)] truncate max-w-xs">{{ invoice.description }}</div>
                            </td>
                            <td class="px-6 py-4 font-medium text-[var(--color-text-primary)]">
                                {{ formatCurrency(invoice.amount, invoice.currency) }}
                            </td>
                            <td class="px-6 py-4 text-sm text-[var(--color-text-tertiary)]">
                                <div>{{ invoice.invoice_date }}</div>
                                <div v-if="invoice.due_date" class="text-xs">Due: {{ invoice.due_date }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span :class="['px-2 py-1 text-xs font-medium rounded-full', getStatusBadge(invoice.status).class]">
                                    {{ getStatusBadge(invoice.status).label }}
                                </span>
                                <div v-if="invoice.paid_at" class="text-xs text-[var(--color-text-tertiary)] mt-1">Paid {{ invoice.paid_at }}</div>
                                <div v-else-if="invoice.approved_at" class="text-xs text-[var(--color-text-tertiary)] mt-1">Approved {{ invoice.approved_at }}</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button
                                    v-if="invoice.status === 'draft'"
                                    @click="submitInvoice(invoice)"
                                    class="px-3 py-1 text-sm bg-indigo-600 text-white rounded hover:bg-indigo-700"
                                >
                                    Submit
                                </button>
                                <span v-else-if="invoice.status === 'submitted'" class="text-sm text-[var(--color-text-tertiary)]">Awaiting approval</span>
                                <span v-else-if="invoice.status === 'approved'" class="text-sm text-[var(--color-text-tertiary)]">Payment pending</span>
                            </td>
                        </tr>
                        <tr v-if="invoices.data.length === 0">
                            <td colspan="5" class="px-6 py-12 text-center text-[var(--color-text-tertiary)]">
                                <div class="text-lg mb-2">No invoices yet</div>
                                <div class="text-sm">Create your first invoice to get paid</div>
                                <Link
                                    v-if="canSubmitInvoices"
                                    href="/my/invoices/create"
                                    class="inline-block mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                                >
                                    Create Invoice
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
