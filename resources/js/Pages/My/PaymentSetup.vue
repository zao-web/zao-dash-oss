<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormToggle from '@/Components/FormToggle.vue';
import { ref, reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';

interface Step {
    id: string;
    title: string;
    description: string;
    complete: boolean;
    current: boolean;
    skip?: boolean;
}

interface Contractor {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    company_name: string | null;
    country_code: string;
    is_us_person: boolean;
    status: string;
    onboarding_status: string;
    has_w9_on_file: boolean;
    w9_received_at: string | null;
    has_bank_details: boolean;
    can_receive_payments: boolean;
}

const props = defineProps<{
    contractor: Contractor;
    steps: Step[];
}>();

const isSaving = ref(false);
const currentStep = computed(() => props.steps.find(s => s.current) || props.steps[0]);

// Personal info form
const personalForm = reactive({
    name: props.contractor.name,
    email: props.contractor.email,
    phone: props.contractor.phone || '',
    company_name: props.contractor.company_name || '',
    country_code: props.contractor.country_code || 'US',
    is_us_person: props.contractor.is_us_person,
});

// W9 form
const w9File = ref<File | null>(null);

// Bank details form
const bankForm = reactive({
    currency: 'USD',
    account_holder_name: props.contractor.company_name || props.contractor.name,
    account_type: 'checking',
    routing_number: '',
    account_number: '',
    iban: '',
    swift_bic: '',
});

const countryOptions = [
    { value: 'US', label: 'United States' },
    { value: 'CA', label: 'Canada' },
    { value: 'GB', label: 'United Kingdom' },
    { value: 'PK', label: 'Pakistan' },
    { value: 'IN', label: 'India' },
    { value: 'PH', label: 'Philippines' },
    { value: 'AU', label: 'Australia' },
    { value: 'DE', label: 'Germany' },
];

const currencyOptions = [
    { value: 'USD', label: 'USD - US Dollar' },
    { value: 'EUR', label: 'EUR - Euro' },
    { value: 'GBP', label: 'GBP - British Pound' },
    { value: 'CAD', label: 'CAD - Canadian Dollar' },
    { value: 'PKR', label: 'PKR - Pakistani Rupee' },
];

const submitPersonalInfo = () => {
    isSaving.value = true;
    router.post('/my/payment-setup/personal-info', personalForm, {
        onFinish: () => {
            isSaving.value = false;
        },
    });
};

const handleW9FileChange = (e: Event) => {
    const target = e.target as HTMLInputElement;
    if (target.files?.length) {
        w9File.value = target.files[0];
    }
};

const submitW9 = () => {
    if (!w9File.value) return;

    isSaving.value = true;
    const formData = new FormData();
    formData.append('w9_file', w9File.value);

    router.post('/my/payment-setup/w9', formData, {
        onFinish: () => {
            isSaving.value = false;
        },
    });
};

const submitBankDetails = () => {
    isSaving.value = true;
    router.post('/my/payment-setup/bank-details', bankForm, {
        onFinish: () => {
            isSaving.value = false;
        },
    });
};

const getStepStatus = (step: Step, index: number) => {
    if (step.complete) return 'complete';
    if (step.current) return 'current';
    if (step.skip) return 'skip';
    return 'pending';
};
</script>

<template>
    <AppLayout title="Payment Setup">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="mb-8">
                <h1 class="text-2xl font-semibold text-[var(--color-text-primary)]">Payment Setup</h1>
                <p class="text-sm text-[var(--color-text-tertiary)]">Complete these steps to receive payments</p>
            </div>

            <!-- Progress Steps -->
            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <template v-for="(step, index) in steps" :key="step.id">
                        <div class="flex items-center">
                            <div
                                :class="[
                                    'w-10 h-10 rounded-full flex items-center justify-center text-sm font-medium',
                                    step.complete ? 'bg-green-600 text-white' :
                                    step.current ? 'bg-indigo-600 text-white' :
                                    step.skip ? 'bg-[var(--color-bg-elevated)] text-[var(--color-text-tertiary)] line-through' :
                                    'bg-[var(--color-bg-elevated)] text-[var(--color-text-tertiary)]'
                                ]"
                            >
                                <svg v-if="step.complete" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                                <span v-else>{{ index + 1 }}</span>
                            </div>
                            <div class="ml-3">
                                <div :class="['text-sm font-medium', step.current ? 'text-indigo-600' : 'text-[var(--color-text-secondary)]']">
                                    {{ step.title }}
                                </div>
                                <div class="text-xs text-[var(--color-text-tertiary)]">{{ step.description }}</div>
                            </div>
                        </div>
                        <div v-if="index < steps.length - 1" class="flex-1 h-0.5 bg-[var(--color-bg-elevated)] mx-4"></div>
                    </template>
                </div>
            </div>

            <!-- Status Banner -->
            <div v-if="contractor.can_receive_payments" class="mb-6 p-4 bg-green-500/10 border border-green-500/30 rounded-lg">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="text-green-500 font-medium">You're all set up to receive payments!</span>
                </div>
            </div>

            <!-- Step Content -->
            <div class="card p-6">
                <!-- Personal Info Step -->
                <div v-if="currentStep?.id === 'personal_info' || !contractor.can_receive_payments && steps[0] && !steps[0].complete">
                    <h3 class="text-lg font-medium text-[var(--color-text-primary)] mb-4">Personal Information</h3>
                    <form @submit.prevent="submitPersonalInfo" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <FormInput v-model="personalForm.name" label="Full Name" required />
                            <FormInput v-model="personalForm.email" label="Email" type="email" required />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <FormInput v-model="personalForm.phone" label="Phone" />
                            <FormInput v-model="personalForm.company_name" label="Company Name (if applicable)" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <FormSelect v-model="personalForm.country_code" label="Country" :options="countryOptions" required />
                            <FormToggle v-model="personalForm.is_us_person" label="I am a US person for tax purposes" />
                        </div>
                        <div class="flex justify-end pt-4">
                            <button type="submit" :disabled="isSaving" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                                {{ isSaving ? 'Saving...' : 'Save & Continue' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- W-9 Step -->
                <div v-else-if="currentStep?.id === 'w9' && contractor.is_us_person && !contractor.has_w9_on_file">
                    <h3 class="text-lg font-medium text-[var(--color-text-primary)] mb-4">W-9 Form</h3>
                    <p class="text-sm text-[var(--color-text-tertiary)] mb-4">
                        As a US person, we need your W-9 on file for tax reporting. Upload a completed W-9 PDF.
                    </p>
                    <form @submit.prevent="submitW9" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-[var(--color-text-secondary)] mb-1">W-9 PDF File</label>
                            <input
                                type="file"
                                accept=".pdf"
                                @change="handleW9FileChange"
                                class="w-full"
                                required
                            />
                            <p class="text-xs text-[var(--color-text-tertiary)] mt-1">PDF only, max 10MB</p>
                        </div>
                        <div class="flex justify-end pt-4">
                            <button type="submit" :disabled="isSaving || !w9File" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                                {{ isSaving ? 'Uploading...' : 'Upload W-9' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Bank Details Step -->
                <div v-else-if="currentStep?.id === 'bank_details' || (!contractor.has_bank_details && (contractor.has_w9_on_file || !contractor.is_us_person))">
                    <h3 class="text-lg font-medium text-[var(--color-text-primary)] mb-4">Bank Details</h3>
                    <p class="text-sm text-[var(--color-text-tertiary)] mb-4">
                        Enter your bank account details for wire transfers via Wise.
                    </p>
                    <form @submit.prevent="submitBankDetails" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <FormSelect v-model="bankForm.currency" label="Currency" :options="currencyOptions" required />
                            <FormInput v-model="bankForm.account_holder_name" label="Account Holder Name" required />
                        </div>

                        <template v-if="bankForm.currency === 'USD'">
                            <div class="grid grid-cols-2 gap-4">
                                <FormInput v-model="bankForm.routing_number" label="Routing Number (ABA)" required maxlength="9" />
                                <FormInput v-model="bankForm.account_number" label="Account Number" required />
                            </div>
                            <FormSelect
                                v-model="bankForm.account_type"
                                label="Account Type"
                                :options="[
                                    { value: 'checking', label: 'Checking' },
                                    { value: 'savings', label: 'Savings' },
                                ]"
                                required
                            />
                        </template>

                        <template v-else>
                            <div class="grid grid-cols-2 gap-4">
                                <FormInput v-model="bankForm.iban" label="IBAN" required />
                                <FormInput v-model="bankForm.swift_bic" label="SWIFT/BIC Code" />
                            </div>
                        </template>

                        <div class="flex justify-end pt-4">
                            <button type="submit" :disabled="isSaving" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                                {{ isSaving ? 'Saving...' : 'Save Bank Details' }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Complete -->
                <div v-else-if="contractor.can_receive_payments">
                    <div class="text-center py-8">
                        <svg class="w-16 h-16 text-green-600 mx-auto mb-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <h3 class="text-xl font-medium text-[var(--color-text-primary)] mb-2">Setup Complete!</h3>
                        <p class="text-[var(--color-text-tertiary)] mb-6">You can now submit invoices and receive payments.</p>
                        <a href="/my/invoices" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                            View My Invoices
                        </a>
                    </div>
                </div>
            </div>

            <!-- Current Status Summary -->
            <div class="mt-6 bg-[var(--color-bg-tertiary)] rounded-lg p-4">
                <h4 class="text-sm font-medium text-[var(--color-text-secondary)] mb-2">Current Status</h4>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-[var(--color-text-tertiary)]">Account Status</dt>
                        <dd class="font-medium text-[var(--color-text-primary)] capitalize">{{ contractor.status }}</dd>
                    </div>
                    <div>
                        <dt class="text-[var(--color-text-tertiary)]">W-9</dt>
                        <dd class="font-medium" :class="contractor.has_w9_on_file ? 'text-green-600' : contractor.is_us_person ? 'text-orange-600' : 'text-[var(--color-text-tertiary)]'">
                            {{ contractor.has_w9_on_file ? `On file (${contractor.w9_received_at})` : contractor.is_us_person ? 'Required' : 'Not required' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[var(--color-text-tertiary)]">Bank Details</dt>
                        <dd class="font-medium" :class="contractor.has_bank_details ? 'text-green-600' : 'text-orange-600'">
                            {{ contractor.has_bank_details ? 'Verified' : 'Not set up' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[var(--color-text-tertiary)]">Can Receive Payments</dt>
                        <dd class="font-medium" :class="contractor.can_receive_payments ? 'text-green-600' : 'text-red-600'">
                            {{ contractor.can_receive_payments ? 'Yes' : 'No' }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </AppLayout>
</template>
