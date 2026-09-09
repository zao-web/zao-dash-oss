<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface StatementEntry {
    date: string;
    type: 'invoice' | 'payment';
    description: string;
    amount: number;
    balance_change: number;
    running_balance: number;
}

interface Props {
    entries: StatementEntry[];
    summary: {
        total_billed: number;
        total_paid: number;
        current_balance: number;
    };
    client: {
        name: string;
    };
}

defineProps<Props>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
};
</script>

<template>
    <Head title="Account Statement" />

    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow-sm">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <div class="flex items-center gap-4">
                    <a href="/portal" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Account Statement</h1>
                        <p class="text-sm text-gray-500">{{ client.name }}</p>
                    </div>
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
                    <div class="text-sm text-gray-500">Current Balance</div>
                    <div class="text-2xl font-bold" :class="summary.current_balance > 0 ? 'text-yellow-600' : 'text-gray-900'">
                        {{ formatCurrency(summary.current_balance) }}
                    </div>
                </div>
            </div>

            <!-- Statement Entries -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Transaction History</h2>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                Date
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                Description
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                                Charges
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                                Payments
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                                Balance
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="(entry, index) in entries" :key="index" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ entry.date }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="flex items-center gap-2">
                                    <span v-if="entry.type === 'invoice'" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                        Invoice
                                    </span>
                                    <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                        Payment
                                    </span>
                                    {{ entry.description }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <span v-if="entry.type === 'invoice'" class="text-gray-900">
                                    {{ formatCurrency(entry.amount) }}
                                </span>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <span v-if="entry.type === 'payment'" class="text-green-600">
                                    {{ formatCurrency(entry.amount) }}
                                </span>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium" :class="entry.running_balance > 0 ? 'text-yellow-600' : 'text-gray-900'">
                                {{ formatCurrency(entry.running_balance) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!entries.length" class="px-6 py-12 text-center text-gray-500">
                    No transactions found
                </div>
            </div>
        </main>
    </div>
</template>
