<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

interface Video {
    uuid: string;
    title: string;
    share_token: string;
}

const props = defineProps<{
    video: Video;
}>();

const password = ref('');
const error = ref('');
const isSubmitting = ref(false);

const submit = () => {
    if (!password.value) {
        error.value = 'Please enter a password';
        return;
    }

    isSubmitting.value = true;
    error.value = '';

    router.post(`/v/${props.video.share_token}/verify-password`, {
        password: password.value,
    }, {
        onError: (errors) => {
            error.value = errors.password || 'Invalid password';
            isSubmitting.value = false;
        },
        onSuccess: () => {
            // Will redirect to video
        },
    });
};
</script>

<template>
    <div class="password-page">
        <div class="password-card">
            <div class="lock-icon">
                <svg class="h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
            </div>

            <h1 class="password-title">{{ video.title }}</h1>
            <p class="password-subtitle">This video is password protected</p>

            <form @submit.prevent="submit" class="password-form">
                <div class="form-group">
                    <input
                        v-model="password"
                        type="password"
                        placeholder="Enter password"
                        class="password-input"
                        :class="{ 'has-error': error }"
                        autofocus
                    />
                    <p v-if="error" class="error-message">{{ error }}</p>
                </div>

                <button
                    type="submit"
                    class="submit-btn"
                    :disabled="isSubmitting"
                >
                    {{ isSubmitting ? 'Verifying...' : 'Watch Video' }}
                </button>
            </form>
        </div>

        <div class="password-footer">
            <span>Powered by Zao</span>
        </div>
    </div>
</template>

<style scoped>
.password-page {
    min-height: 100vh;
    background: #0a0a0a;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}

.password-card {
    width: 100%;
    max-width: 400px;
    background: #111;
    border: 1px solid #222;
    border-radius: 12px;
    padding: 2.5rem;
    text-align: center;
}

.lock-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    color: #666;
    margin-bottom: 1.5rem;
}

.password-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: white;
    margin-bottom: 0.5rem;
}

.password-subtitle {
    color: #666;
    font-size: 0.875rem;
    margin-bottom: 2rem;
}

.password-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.form-group {
    text-align: left;
}

.password-input {
    width: 100%;
    padding: 0.75rem 1rem;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 8px;
    color: white;
    font-size: 1rem;
    transition: border-color 0.15s;
}

.password-input:focus {
    outline: none;
    border-color: #555;
}

.password-input.has-error {
    border-color: #ef4444;
}

.error-message {
    color: #ef4444;
    font-size: 0.8125rem;
    margin-top: 0.5rem;
}

.submit-btn {
    width: 100%;
    padding: 0.75rem 1rem;
    background: white;
    color: black;
    font-weight: 500;
    border-radius: 8px;
    transition: opacity 0.15s;
}

.submit-btn:hover {
    opacity: 0.9;
}

.submit-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.password-footer {
    margin-top: 2rem;
    color: #444;
    font-size: 0.75rem;
}
</style>
