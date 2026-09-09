<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    message: string;
}>();
</script>

<template>
    <Head title="Invitation Expired" />

    <div class="min-h-screen bg-gray-50 flex items-center justify-center py-12 px-4">
        <div class="max-w-md w-full text-center">
            <div class="mb-6">
                <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>

            <h1 class="text-2xl font-bold text-gray-900 mb-2">
                Invitation Not Available
            </h1>

            <p class="text-gray-600 mb-8">
                {{ message }}
            </p>

            <a
                href="/login"
                class="inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition"
            >
                Go to Login
            </a>
        </div>
    </div>
</template>
