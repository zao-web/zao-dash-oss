<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, computed, watch } from 'vue';

const props = defineProps<{
    defaultAssumptions: {
        avg_deal_size: number;
        win_rate: number;
        sales_cycle_days: number;
    };
    currentYear: number;
}>();

const form = ref({
    fiscal_year: props.currentYear,
    name: '',
    revenue_target: 1500000,
    margin_target_pct: 90,
    assumptions: {
        avg_deal_size: props.defaultAssumptions.avg_deal_size,
        win_rate: props.defaultAssumptions.win_rate,
        sales_cycle_days: props.defaultAssumptions.sales_cycle_days,
    },
});

const isSubmitting = ref(false);
const showAdvanced = ref(false);

// Calculations
const calculations = computed(() => {
    const revenue = form.value.revenue_target;
    const dealSize = form.value.assumptions.avg_deal_size;
    const winRate = form.value.assumptions.win_rate / 100;

    const dealsNeeded = Math.ceil(revenue / dealSize);
    const leadsNeeded = Math.ceil(dealsNeeded / winRate);
    const leadsPerWeek = Math.ceil(leadsNeeded / 52);
    const leadsPerMonth = Math.ceil(leadsNeeded / 12);
    const profit = revenue * (form.value.margin_target_pct / 100);

    return {
        dealsNeeded,
        leadsNeeded,
        leadsPerWeek,
        leadsPerMonth,
        profit,
        quarterlyRevenue: revenue / 4,
        monthlyRevenue: revenue / 12,
        weeklyRevenue: revenue / 52,
    };
});

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
};

const submit = () => {
    isSubmitting.value = true;
    router.post('/goals', form.value, {
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Auto-generate name
watch(() => form.value.fiscal_year, (year) => {
    if (!form.value.name) {
        form.value.name = `FY${year} Revenue Goal`;
    }
});
</script>

<template>
    <AppLayout>
        <Head title="Create Strategic Goal" />

        <div class="p-6 max-w-4xl mx-auto">
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-2xl font-semibold text-white">Create Strategic Goal</h1>
                <p class="text-zinc-400 text-sm mt-1">Set your annual revenue target and let us work backwards to weekly requirements</p>
            </div>

            <form @submit.prevent="submit" class="space-y-8">
                <!-- Basic Info -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                    <h2 class="text-lg font-medium text-white mb-4">Goal Details</h2>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm text-zinc-400 mb-2">Fiscal Year</label>
                            <select
                                v-model="form.fiscal_year"
                                class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2 text-white"
                            >
                                <option v-for="year in [currentYear - 1, currentYear, currentYear + 1, currentYear + 2]" :key="year" :value="year">
                                    {{ year }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm text-zinc-400 mb-2">Goal Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                placeholder="FY2026 Revenue Goal"
                                class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2 text-white placeholder-zinc-500"
                            />
                        </div>
                    </div>
                </div>

                <!-- Revenue Target -->
                <div class="bg-gradient-to-br from-blue-600/10 to-purple-600/10 border border-blue-500/30 rounded-xl p-6">
                    <h2 class="text-lg font-medium text-white mb-4">Revenue Target</h2>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm text-zinc-400 mb-2">Annual Revenue Target</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-zinc-500">$</span>
                                <input
                                    v-model.number="form.revenue_target"
                                    type="number"
                                    min="10000"
                                    step="10000"
                                    class="w-full bg-zinc-800 border border-zinc-700 rounded-lg pl-8 pr-4 py-3 text-2xl text-white"
                                />
                            </div>
                            <p class="text-zinc-500 text-xs mt-2">Your total revenue goal for the year</p>
                        </div>

                        <div>
                            <label class="block text-sm text-zinc-400 mb-2">Target Margin (%)</label>
                            <div class="relative">
                                <input
                                    v-model.number="form.margin_target_pct"
                                    type="number"
                                    min="0"
                                    max="100"
                                    class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-3 text-2xl text-white"
                                />
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-zinc-500">%</span>
                            </div>
                            <p class="text-zinc-500 text-xs mt-2">Profit: {{ formatCurrency(calculations.profit) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Calculated Requirements -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                    <h2 class="text-lg font-medium text-white mb-4">What It Takes</h2>
                    <p class="text-zinc-400 text-sm mb-6">Based on your assumptions, here's what you need to hit your target:</p>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="bg-zinc-800/50 rounded-lg p-4 text-center">
                            <div class="text-3xl font-bold text-white">{{ calculations.dealsNeeded }}</div>
                            <div class="text-zinc-500 text-sm">deals to close</div>
                        </div>
                        <div class="bg-zinc-800/50 rounded-lg p-4 text-center">
                            <div class="text-3xl font-bold text-white">{{ calculations.leadsNeeded }}</div>
                            <div class="text-zinc-500 text-sm">total leads</div>
                        </div>
                        <div class="bg-blue-600/20 border border-blue-500/30 rounded-lg p-4 text-center">
                            <div class="text-3xl font-bold text-blue-400">{{ calculations.leadsPerWeek }}</div>
                            <div class="text-zinc-400 text-sm">leads per week</div>
                        </div>
                        <div class="bg-zinc-800/50 rounded-lg p-4 text-center">
                            <div class="text-3xl font-bold text-white">{{ formatCurrency(calculations.monthlyRevenue) }}</div>
                            <div class="text-zinc-500 text-sm">per month</div>
                        </div>
                    </div>
                </div>

                <!-- Assumptions (Collapsible) -->
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl">
                    <button
                        type="button"
                        @click="showAdvanced = !showAdvanced"
                        class="w-full px-6 py-4 flex items-center justify-between text-left"
                    >
                        <span class="text-lg font-medium text-white">Assumptions</span>
                        <svg
                            :class="['w-5 h-5 text-zinc-400 transition-transform', showAdvanced ? 'rotate-180' : '']"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div v-show="showAdvanced" class="px-6 pb-6 space-y-4 border-t border-zinc-800 pt-4">
                        <p class="text-zinc-400 text-sm mb-4">
                            These defaults come from your historical funnel data. Adjust if needed.
                        </p>

                        <div class="grid grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm text-zinc-400 mb-2">Avg Deal Size</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500">$</span>
                                    <input
                                        v-model.number="form.assumptions.avg_deal_size"
                                        type="number"
                                        min="100"
                                        class="w-full bg-zinc-800 border border-zinc-700 rounded-lg pl-8 pr-4 py-2 text-white"
                                    />
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm text-zinc-400 mb-2">Win Rate (%)</label>
                                <input
                                    v-model.number="form.assumptions.win_rate"
                                    type="number"
                                    min="1"
                                    max="100"
                                    class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2 text-white"
                                />
                            </div>

                            <div>
                                <label class="block text-sm text-zinc-400 mb-2">Sales Cycle (days)</label>
                                <input
                                    v-model.number="form.assumptions.sales_cycle_days"
                                    type="number"
                                    min="1"
                                    max="365"
                                    class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2 text-white"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="flex items-center justify-end gap-4">
                    <a href="/goals" class="px-4 py-2 text-zinc-400 hover:text-white transition-colors">
                        Cancel
                    </a>
                    <button
                        type="submit"
                        :disabled="isSubmitting"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white rounded-lg transition-colors"
                    >
                        {{ isSubmitting ? 'Creating...' : 'Create Goal' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
