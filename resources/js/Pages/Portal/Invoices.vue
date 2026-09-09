<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

interface Invoice {
    id: number;
    number: string;
    date: string;
    due_date: string | null;
    amount: number;
    balance: number;
    status: string;
    public_url: string;
    is_overdue: boolean;
    days_overdue: number;
}

interface Props {
    invoices: Invoice[];
    summary: {
        total_billed: number;
        total_paid: number;
        outstanding: number;
    };
}

defineProps<Props>();

const statusColor = (status: string) => {
    const colors: Record<string, string> = {
        sent: 'bg-blue-100 text-blue-800',
        viewed: 'bg-purple-100 text-purple-800',
        paid: 'bg-green-100 text-green-800',
        overdue: 'bg-red-100 text-red-800',
        partial: 'bg-orange-100 text-orange-800',
    };
    return colors[status] || 'bg-gray-100 text-gray-800';
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
};
</script>

<template>
    <Head title="Invoices" />

    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow-sm">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <a href="/portal" class="text-gray-500 hover:text-gray-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </a>
                        <h1 class="text-2xl font-bold text-gray-900">Invoices</h1>
                    </div>
                    <a href="/portal/statement" class="text-sm text-blue-600 hover:text-blue-800">
                        View Account Statement
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-8">
            <!-- Summary -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="text-sm text-gray-500">Total Billed</div>
                    <div class="text-2xl font-bold text-gray-900">
                        {{ formatCurrency(summary.total_billed) }}
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="text-sm text-gray-500">Total Paid</div>
                    <div class="text-2xl font-bold text-green-600">
                        {{ formatCurrency(summary.total_paid) }}
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="text-sm text-gray-500">Outstanding</div>
                    <div class="text-2xl font-bold" :class="summary.outstanding > 0 ? 'text-yellow-600' : 'text-gray-900'">
                        {{ formatCurrency(summary.outstanding) }}
                    </div>
                </div>
            </div>

            <!-- Invoice List -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                Invoice
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                Date
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                Due Date
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                                Amount
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                                Balance
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                Status
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="invoice in invoices" :key="invoice.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a :href="invoice.public_url" target="_blank" class="font-medium text-indigo-600 hover:text-indigo-800">
                                    #{{ invoice.number }}
                                </a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-500">
                                {{ invoice.date }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap" :class="invoice.is_overdue ? 'text-red-600' : 'text-gray-500'">
                                {{ invoice.due_date || '-' }}
                                <span v-if="invoice.is_overdue" class="text-xs ml-1">({{ invoice.days_overdue }}d overdue)</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-gray-900">
                                {{ formatCurrency(invoice.amount) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right" :class="invoice.balance > 0 ? 'text-yellow-600' : 'text-gray-500'">
                                {{ formatCurrency(invoice.balance) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <span :class="statusColor(invoice.status)" class="text-xs px-2 py-1 rounded-full capitalize">
                                    {{ invoice.status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <a :href="invoice.public_url" target="_blank" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                                    {{ invoice.balance > 0 ? 'View & Pay' : 'View' }}
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!invoices.length" class="px-6 py-12 text-center text-gray-500">
                    No invoices found
                </div>
            </div>
        </main>
    </div>
</template>
