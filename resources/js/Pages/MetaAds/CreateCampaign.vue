<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import FormToggle from '@/Components/FormToggle.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { SparklesIcon, CheckIcon } from '@/Components/Icons';

interface BrandGuideline {
    id: number;
    name: string;
    brand_voice: string;
}

interface Client {
    id: number;
    name: string;
    slug: string;
}

const props = defineProps<{
    brandGuidelines: BrandGuideline[];
    clients: Client[];
    hasSandboxAccount: boolean;
    hasProductionAccount: boolean;
}>();

// Form state with Inertia form helper for error handling
const form = useForm({
    name: 'AI Workflow Transformation - Yamhill County',
    objective: 'OUTCOME_LEADS',
    daily_budget: 20,
    destination_url: 'https://example.com/ai',

    // Targeting
    location_radius: 25,
    location_city: 'Newberg, OR',
    age_min: 30,
    age_max: 65,
    interests: 'small business, entrepreneurship, business management, workflow automation',

    // Goals & Automation
    target_cpa: 40,
    cpa_max: 75,
    ctr_min: 1.0,
    automation_enabled: true,

    // Creative
    brand_guideline_id: null as number | null,
    generate_creatives: true,
    creative_count: 3,

    // Optional
    client_id: null as number | null,

    // Account Selection
    use_sandbox: true,
});

const currentStep = ref(1);

const objectiveOptions = [
    { value: 'OUTCOME_LEADS', label: 'Lead Generation (recommended)' },
    { value: 'OUTCOME_AWARENESS', label: 'Brand Awareness' },
    { value: 'OUTCOME_TRAFFIC', label: 'Website Traffic' },
    { value: 'OUTCOME_ENGAGEMENT', label: 'Engagement' },
];

const clientOptions = computed(() => [
    { value: null, label: 'None (Personal Campaign)' },
    ...props.clients.map(c => ({ value: c.id, label: c.name })),
]);

const brandGuidelineOptions = computed(() => [
    { value: null, label: 'Use Default Guidelines' },
    ...props.brandGuidelines.map(bg => ({
        value: bg.id,
        label: `${bg.name} (${bg.brand_voice})`
    })),
]);

const canProceed = computed(() => {
    if (currentStep.value === 1) {
        return form.name && form.objective && form.daily_budget > 0;
    }
    if (currentStep.value === 2) {
        return form.destination_url && form.location_city;
    }
    if (currentStep.value === 3) {
        return form.target_cpa > 0;
    }
    return true;
});

const nextStep = () => {
    if (canProceed.value && currentStep.value < 4) {
        currentStep.value++;
    }
};

const prevStep = () => {
    if (currentStep.value > 1) {
        currentStep.value--;
    }
};

const submitCampaign = () => {
    if (!canProceed.value) return;

    form.post('/meta-ads/campaigns', {
        onError: () => {
            // Scroll to first error
            const firstError = document.querySelector('.text-red-600');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        },
    });
};

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
    }).format(value);
};

const estimatedMonthlySpend = computed(() => {
    return form.daily_budget * 30;
});

const estimatedMonthlyLeads = computed(() => {
    return Math.round(estimatedMonthlySpend.value / form.target_cpa);
});
</script>

<template>
    <AppLayout title="Create Meta Ad Campaign">
        <div class="max-w-4xl mx-auto">
            <!-- Progress Steps -->
            <div class="mb-8">
                <div class="flex items-start justify-between">
                    <div
                        v-for="(label, index) in ['Campaign Details', 'Targeting', 'Goals & Budget', 'Creative']"
                        :key="index + 1"
                        class="flex flex-col items-center flex-1 relative"
                    >
                        <!-- Circle centered by items-center on parent -->
                        <div
                            :class="[
                                'relative z-10 flex items-center justify-center w-10 h-10 rounded-full text-sm font-semibold transition-colors mb-3',
                                currentStep >= (index + 1)
                                    ? 'bg-[var(--color-accent)] text-white'
                                    : 'bg-[var(--color-bg-tertiary)] text-[var(--color-text-tertiary)]'
                            ]"
                        >
                            <CheckIcon v-if="currentStep > (index + 1)" :size="20" />
                            <span v-else>{{ index + 1 }}</span>
                        </div>

                        <!-- Connecting line positioned absolutely -->
                        <div
                            v-if="index < 3"
                            :class="[
                                'absolute top-5 h-1 transition-colors',
                                'left-[calc(50%+1.25rem)] right-0',
                                currentStep > (index + 1)
                                    ? 'bg-[var(--color-accent)]'
                                    : 'bg-[var(--color-border-subtle)]'
                            ]"
                        ></div>

                        <span
                            :class="[
                                'text-xs text-caption text-center',
                                { 'font-semibold': currentStep === (index + 1) }
                            ]"
                        >
                            {{ label }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <!-- Validation Errors -->
                <div v-if="form.hasErrors" class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                    <h3 class="text-sm font-semibold text-red-800 dark:text-red-200 mb-2">
                        Please correct the following errors:
                    </h3>
                    <ul class="list-disc list-inside text-sm text-red-700 dark:text-red-300 space-y-1">
                        <li v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </li>
                    </ul>
                </div>

                <!-- Step 1: Campaign Details -->
                <div v-if="currentStep === 1" class="space-y-6">
                    <div>
                        <h2 class="text-xl font-semibold mb-2" style="color: var(--color-text-primary)">
                            Campaign Details
                        </h2>
                        <p class="text-caption">Set up the basic information for your campaign</p>
                    </div>

                    <FormInput
                        v-model="form.name"
                        label="Campaign Name"
                        placeholder="e.g., AI Workflow Transformation - Local"
                        required
                        hint="Internal name to identify this campaign"
                    />

                    <FormSelect
                        v-model="form.objective"
                        label="Campaign Objective"
                        :options="objectiveOptions"
                        required
                        hint="What's the primary goal of this campaign?"
                    />

                    <FormInput
                        v-model.number="form.daily_budget"
                        label="Daily Budget"
                        type="number"
                        step="5"
                        min="10"
                        required
                        hint="Recommended: $30-50/day for small markets"
                    >
                        <template #prefix>$</template>
                    </FormInput>

                    <FormSelect
                        v-model="form.client_id"
                        label="Client (Optional)"
                        :options="clientOptions"
                        hint="Associate this campaign with a client, or leave blank for personal use"
                    />

                    <FormToggle
                        v-if="hasSandboxAccount"
                        v-model="form.use_sandbox"
                        label="Use Sandbox Account"
                        description="Test with Meta sandbox (no real money spent). Uncheck to use production account."
                    />

                    <div v-if="!hasSandboxAccount && !hasProductionAccount" class="p-4 bg-yellow-500/10 border border-yellow-500/20 rounded-lg">
                        <p class="text-sm text-yellow-600 dark:text-yellow-400">
                            No Meta ad account connected. Please connect a Meta account before creating campaigns.
                        </p>
                    </div>
                </div>

                <!-- Step 2: Targeting -->
                <div v-if="currentStep === 2" class="space-y-6">
                    <div>
                        <h2 class="text-xl font-semibold mb-2" style="color: var(--color-text-primary)">
                            Audience Targeting
                        </h2>
                        <p class="text-caption">Define who will see your ads</p>
                    </div>

                    <FormInput
                        v-model="form.destination_url"
                        label="Landing Page URL"
                        placeholder="https://example.com/ai"
                        required
                        hint="Where should people go when they click your ad?"
                    />

                    <div class="grid grid-cols-2 gap-4">
                        <FormInput
                            v-model="form.location_city"
                            label="Location"
                            placeholder="Newberg, OR"
                            required
                        />

                        <FormInput
                            v-model.number="form.location_radius"
                            label="Radius (miles)"
                            type="number"
                            min="1"
                            max="50"
                            required
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <FormInput
                            v-model.number="form.age_min"
                            label="Min Age"
                            type="number"
                            min="18"
                            max="65"
                            required
                        />

                        <FormInput
                            v-model.number="form.age_max"
                            label="Max Age"
                            type="number"
                            min="18"
                            max="65"
                            required
                        />
                    </div>

                    <FormTextarea
                        v-model="form.interests"
                        label="Interests & Demographics"
                        placeholder="small business, entrepreneurship, business management"
                        :rows="3"
                        hint="Comma-separated list of interests to target (e.g., small business owners, entrepreneurs)"
                    />
                </div>

                <!-- Step 3: Goals & Budget -->
                <div v-if="currentStep === 3" class="space-y-6">
                    <div>
                        <h2 class="text-xl font-semibold mb-2" style="color: var(--color-text-primary)">
                            Performance Goals
                        </h2>
                        <p class="text-caption">Set targets for AI optimization</p>
                    </div>

                    <FormInput
                        v-model.number="form.target_cpa"
                        label="Target Cost Per Lead"
                        type="number"
                        step="5"
                        min="5"
                        required
                        hint="Goal CPA - AI will optimize towards this"
                    >
                        <template #prefix>$</template>
                    </FormInput>

                    <FormInput
                        v-model.number="form.cpa_max"
                        label="Maximum Cost Per Lead"
                        type="number"
                        step="5"
                        min="5"
                        required
                        hint="Auto-pause threshold - campaign pauses if CPA exceeds this"
                    >
                        <template #prefix>$</template>
                    </FormInput>

                    <FormInput
                        v-model.number="form.ctr_min"
                        label="Minimum CTR"
                        type="number"
                        step="0.1"
                        min="0.1"
                        required
                        hint="Minimum click-through rate (%) - ads pause if CTR drops below this"
                    >
                        <template #suffix>%</template>
                    </FormInput>

                    <FormToggle
                        v-model="form.automation_enabled"
                        label="Enable AI Optimization"
                        description="Automatically pause underperforming ads and scale winners"
                    />

                    <!-- Projections -->
                    <div class="surface-elevated p-4 rounded-lg">
                        <h4 class="text-sm font-semibold mb-3" style="color: var(--color-text-secondary)">
                            Projected Performance
                        </h4>
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div>
                                <p class="text-caption mb-1">Monthly Spend</p>
                                <p class="text-lg font-bold text-mono" style="color: var(--color-text-primary)">
                                    {{ formatCurrency(estimatedMonthlySpend) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-caption mb-1">Est. Leads/Month</p>
                                <p class="text-lg font-bold text-mono" style="color: var(--color-text-primary)">
                                    {{ estimatedMonthlyLeads }}
                                </p>
                            </div>
                            <div>
                                <p class="text-caption mb-1">Target CPA</p>
                                <p class="text-lg font-bold text-mono" style="color: var(--color-text-primary)">
                                    {{ formatCurrency(form.target_cpa) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Creative -->
                <div v-if="currentStep === 4" class="space-y-6">
                    <div>
                        <h2 class="text-xl font-semibold mb-2" style="color: var(--color-text-primary)">
                            Ad Creative Generation
                        </h2>
                        <p class="text-caption">Let AI generate your ad copy and images</p>
                    </div>

                    <FormSelect
                        v-model="form.brand_guideline_id"
                        label="Brand Guidelines"
                        :options="brandGuidelineOptions"
                        hint="AI will follow these brand guidelines when generating creatives"
                    />

                    <FormToggle
                        v-model="form.generate_creatives"
                        label="Generate Creatives with AI"
                        description="Use Claude for copy and Nano Banana Pro for images"
                    />

                    <FormInput
                        v-if="form.generate_creatives"
                        v-model.number="form.creative_count"
                        label="Number of Creative Variations"
                        type="number"
                        min="1"
                        max="5"
                        hint="Generate multiple variations for A/B testing"
                    />

                    <div class="surface-elevated p-4 rounded-lg border-l-4 border-[var(--color-accent)]">
                        <div class="flex items-start gap-3">
                            <SparklesIcon :size="20" style="color: var(--color-accent)" class="flex-shrink-0 mt-0.5" />
                            <div>
                                <h4 class="text-sm font-semibold mb-1" style="color: var(--color-text-primary)">
                                    AI-Powered Creative Generation
                                </h4>
                                <p class="text-caption">
                                    Our AI will generate {{ form.creative_count }} high-converting ad variations with:
                                </p>
                                <ul class="mt-2 space-y-1 text-caption">
                                    <li>• Headlines optimized for your objective</li>
                                    <li>• Primary text that follows your brand voice</li>
                                    <li>• Professional images using Nano Banana Pro (Gemini 3 Pro)</li>
                                    <li>• Multiple angles for A/B testing</li>
                                </ul>
                                <p class="mt-3 text-caption" style="color: var(--color-text-tertiary)">
                                    Generation takes ~2-3 minutes. You'll review and approve before launch.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center justify-between mt-8 pt-6 border-t border-[var(--color-border-subtle)]">
                    <button
                        v-if="currentStep > 1"
                        @click="prevStep"
                        class="btn btn-secondary"
                    >
                        Back
                    </button>
                    <div v-else></div>

                    <button
                        v-if="currentStep < 4"
                        @click="nextStep"
                        :disabled="!canProceed"
                        class="btn btn-primary"
                    >
                        Continue
                    </button>
                    <button
                        v-else
                        @click="submitCampaign"
                        :disabled="!canProceed || form.processing"
                        class="btn btn-primary"
                    >
                        <SparklesIcon :size="16" />
                        {{ form.processing ? 'Creating Campaign...' : 'Create Campaign' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.surface-elevated {
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
}
</style>
