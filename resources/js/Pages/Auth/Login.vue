<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormInput from '@/Components/FormInput.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <div class="min-h-screen flex items-center justify-center" style="background: var(--color-bg-primary)">
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="flex justify-center mb-8">
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl" style="background: var(--color-accent)">
                        <span class="text-xl font-bold text-white">Z</span>
                    </div>
                    <div>
                        <h1 class="text-2xl font-semibold" style="color: var(--color-text-primary)">Zao Dash</h1>
                        <p class="text-sm" style="color: var(--color-text-tertiary)">AI-Powered Agency Command Center</p>
                    </div>
                </div>
            </div>

            <!-- Login Card -->
            <div class="surface-elevated p-8 mb-4">
                <h2 class="text-xl font-semibold mb-6" style="color: var(--color-text-primary)">Sign in to your account</h2>

                <form @submit.prevent="submit">
                    <FormInput
                        v-model="form.email"
                        type="email"
                        label="Email"
                        placeholder="you@company.com"
                        :error="form.errors.email"
                        required
                        :disabled="form.processing"
                    />

                    <FormInput
                        v-model="form.password"
                        type="password"
                        label="Password"
                        placeholder="Enter your password"
                        :error="form.errors.password"
                        required
                        :disabled="form.processing"
                    />

                    <div class="mb-6">
                        <FormCheckbox
                            v-model="form.remember"
                            label="Remember me"
                            :disabled="form.processing"
                        />
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary w-full"
                        :disabled="form.processing"
                    >
                        <span v-if="form.processing">Signing in...</span>
                        <span v-else>Sign in</span>
                    </button>
                </form>
            </div>

            <!-- System Status Indicator -->
            <div class="flex items-center justify-center gap-2" style="color: var(--color-text-quaternary)">
                <div class="status-dot running"></div>
                <span class="text-caption">All systems operational</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.checkbox {
    width: 1rem;
    height: 1rem;
    border-radius: 4px;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-tertiary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.checkbox:checked {
    background: var(--color-accent);
    border-color: var(--color-accent);
}

.checkbox:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
