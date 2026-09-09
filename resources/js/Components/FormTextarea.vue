<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: string;
    label?: string;
    placeholder?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    disabled?: boolean;
    rows?: number;
}>(), {
    required: false,
    disabled: false,
    rows: 4,
});

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const textValue = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});
</script>

<template>
    <div class="form-group">
        <label v-if="label" class="form-label">
            {{ label }}
            <span v-if="required" class="required-mark">*</span>
        </label>
        <textarea
            v-model="textValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :rows="rows"
            :class="['form-textarea', { 'has-error': error }]"
        ></textarea>
        <p v-if="error" class="form-error">{{ error }}</p>
        <p v-else-if="hint" class="form-hint">{{ hint }}</p>
    </div>
</template>

<style scoped>
.form-group {
    margin-bottom: 1rem;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.5rem;
}

.required-mark {
    color: var(--color-status-red);
    margin-left: 2px;
}

.form-textarea {
    width: 100%;
    padding: 10px 12px;
    font-size: 0.875rem;
    font-family: inherit;
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    outline: none;
    resize: vertical;
    min-height: 80px;
    transition: all 0.15s ease;
}

.form-textarea::placeholder {
    color: var(--color-text-quaternary);
}

.form-textarea:focus {
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
}

.form-textarea:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    resize: none;
}

.form-textarea.has-error {
    border-color: var(--color-status-red);
}

.form-error {
    font-size: 0.75rem;
    color: var(--color-status-red);
    margin-top: 0.375rem;
}

.form-hint {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.375rem;
}
</style>
