<script setup lang="ts">
type Variant = 'primary' | 'success' | 'danger' | 'ghost';
type Size = 'sm' | 'md' | 'lg';

const props = withDefaults(defineProps<{
    variant?: Variant;
    size?: Size;
    disabled?: boolean;
    loading?: boolean;
}>(), {
    variant: 'primary',
    size: 'md',
    disabled: false,
    loading: false,
});

const emit = defineEmits<{
    click: [event: MouseEvent];
}>();

const variantClasses = {
    primary: 'btn-primary',
    success: 'btn-success',
    danger: 'bg-status-failed hover:bg-status-failed/80 text-white',
    ghost: 'btn-ghost',
};

const sizeClasses = {
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-4 py-2 text-sm',
    lg: 'px-6 py-3 text-base',
};
</script>

<template>
    <button
        :class="[
            'inline-flex items-center justify-center gap-2 font-medium rounded-lg transition-all duration-200',
            variantClasses[variant],
            sizeClasses[size],
            disabled && 'opacity-50 cursor-not-allowed',
            loading && 'pointer-events-none',
        ]"
        :disabled="disabled || loading"
        @click="emit('click', $event)"
    >
        <svg
            v-if="loading"
            class="animate-spin h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>
        <slot />
    </button>
</template>
