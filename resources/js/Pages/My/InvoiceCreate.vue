<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import { reactive, ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';

const props = defineProps<{
    contractor: {
        id: number;
        name: string;
        currency: string;
    };
    nextInvoiceNumber: string;
}>();

const isSaving = ref(false);

const form = reactive({
    invoice_number: props.nextInvoiceNumber,
    invoice_date: new Date().toISOString().split('T')[0],
    due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    amount: '',
    currency: props.contractor.currency || 'USD',
    description: '',
    line_items: [] as Array<{ description: string; amount: number }>,
});

const addLineItem = () => {
    form.line_items.push({ description: '', amount: 0 });
};

const removeLineItem = (index: number) => {
    form.line_items.splice(index, 1);
};

const submit = () => {
    isSaving.value = true;
    router.post('/my/invoices', form, {
        onFinish: () => {
            isSaving.value = false;
        },
    });
};

const currencyOptions = [
    { value: 'USD', label: 'USD - US Dollar' },
    { value: 'EUR', label: 'EUR - Euro' },
    { value: 'GBP', label: 'GBP - British Pound' },
    { value: 'CAD', label: 'CAD - Canadian Dollar' },
];
</script>

<template>
    <AppLayout title="Create Invoice">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex items-center gap-3 mb-6">
                <Link href="/my/invoices" class="text-[var(--color-text-tertiary)] hover:text-[var(--color-text-secondary)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <h1 class="text-2xl font-semibold text-[var(--color-text-primary)]">Create Invoice</h1>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <div class="card p-6">
                    <h3 class="text-lg font-medium text-[var(--color-text-primary)] mb-4">Invoice Details</h3>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <FormInput v-model="form.invoice_number" label="Invoice Number" required />
                        <FormSelect v-model="form.currency" label="Currency" :options="currencyOptions" required />
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <FormInput v-model="form.invoice_date" label="Invoice Date" type="date" required />
                        <FormInput v-model="form.due_date" label="Due Date" type="date" />
                    </div>

                    <FormInput v-model="form.amount" label="Total Amount" type="number" step="0.01" min="1" required />

                    <div class="mt-4">
                        <label class="block text-sm font-medium text-[var(--color-text-secondary)] mb-1">Description</label>
                        <textarea
                            v-model="form.description"
                            rows="4"
                            class="w-full rounded-lg border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] text-[var(--color-text-primary)]"
                            placeholder="Describe the work completed..."
                            required
                        ></textarea>
                    </div>
                </div>

                <!-- Line Items (Optional) -->
                <div class="card p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-medium text-[var(--color-text-primary)]">Line Items (Optional)</h3>
                        <button type="button" @click="addLineItem" class="text-sm text-indigo-600 hover:text-indigo-800">
                            + Add Item
                        </button>
                    </div>

                    <div v-if="form.line_items.length === 0" class="text-sm text-[var(--color-text-tertiary)] py-4 text-center">
                        No line items. Click "Add Item" to itemize your invoice.
                    </div>

                    <div v-else class="space-y-3">
                        <div v-for="(item, index) in form.line_items" :key="index" class="flex gap-3 items-start">
                            <div class="flex-1">
                                <input
                                    v-model="item.description"
                                    type="text"
                                    placeholder="Item description"
                                    class="w-full rounded-lg border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] text-[var(--color-text-primary)] text-sm"
                                />
                            </div>
                            <div class="w-32">
                                <input
                                    v-model.number="item.amount"
                                    type="number"
                                    step="0.01"
                                    placeholder="Amount"
                                    class="w-full rounded-lg border-[var(--color-border-default)] bg-[var(--color-bg-secondary)] text-[var(--color-text-primary)] text-sm"
                                />
                            </div>
                            <button type="button" @click="removeLineItem(index)" class="text-red-500 hover:text-red-700 p-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="flex justify-end gap-3">
                    <Link href="/my/invoices" class="px-4 py-2 text-[var(--color-text-secondary)] hover:bg-[var(--color-bg-tertiary)] rounded-lg">
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        :disabled="isSaving"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                    >
                        {{ isSaving ? 'Saving...' : 'Save as Draft' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
