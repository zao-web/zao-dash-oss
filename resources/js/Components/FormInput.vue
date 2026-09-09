<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: string | number;
    label?: string;
    type?: string;
    placeholder?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    disabled?: boolean;
    prefix?: string;
    suffix?: string;
}>(), {
    type: 'text',
    required: false,
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string | number];
}>();

const inputValue = computed({
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
        <div class="input-wrapper" :class="{ 'has-prefix': prefix, 'has-suffix': suffix }">
            <span v-if="prefix" class="input-prefix">{{ prefix }}</span>
            <input
                v-model="inputValue"
                :type="type"
                :placeholder="placeholder"
                :disabled="disabled"
                :class="['form-input', { 'has-error': error }]"
            >
            <span v-if="suffix" class="input-suffix">{{ suffix }}</span>
        </div>
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

.input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.input-prefix,
.input-suffix {
    position: absolute;
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
    pointer-events: none;
}

.input-prefix {
    left: 12px;
}

.input-suffix {
    right: 12px;
}

.form-input {
    width: 100%;
    padding: 10px 12px;
    font-size: 0.875rem;
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    outline: none;
    transition: all 0.15s ease;
}

.has-prefix .form-input {
    padding-left: 32px;
}

.has-suffix .form-input {
    padding-right: 32px;
}

.form-input::placeholder {
    color: var(--color-text-quaternary);
}

.form-input:focus {
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
}

.form-input:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.form-input.has-error {
    border-color: var(--color-status-red);
}

.form-input.has-error:focus {
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
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
