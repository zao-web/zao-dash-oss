<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: boolean;
    label?: string;
    description?: string;
    disabled?: boolean;
    id?: string;
}>(), {
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

const isChecked = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const checkboxId = computed(() => props.id || `checkbox-${Math.random().toString(36).substr(2, 9)}`);
</script>

<template>
    <div class="checkbox-group" :class="{ disabled }">
        <label :for="checkboxId" class="checkbox-wrapper">
            <input
                :id="checkboxId"
                v-model="isChecked"
                type="checkbox"
                class="checkbox-input"
                :disabled="disabled"
                :aria-describedby="description ? `${checkboxId}-description` : undefined"
            >
            <span class="checkbox-box">
                <svg
                    class="checkbox-icon"
                    viewBox="0 0 16 16"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                >
                    <path
                        d="M13.5 4.5L6 12L2.5 8.5"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </span>
            <span v-if="label || description" class="checkbox-content">
                <span v-if="label" class="checkbox-label">{{ label }}</span>
                <span
                    v-if="description"
                    :id="`${checkboxId}-description`"
                    class="checkbox-description"
                >
                    {{ description }}
                </span>
            </span>
        </label>
    </div>
</template>

<style scoped>
.checkbox-group {
    margin-bottom: 1rem;
}

.checkbox-group.disabled {
    opacity: 0.5;
    pointer-events: none;
}

.checkbox-wrapper {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    cursor: pointer;
}

.checkbox-input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.checkbox-box {
    position: relative;
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.checkbox-icon {
    width: 12px;
    height: 12px;
    color: white;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.checkbox-icon path {
    stroke-dasharray: 20;
    stroke-dashoffset: 20;
    transition: stroke-dashoffset 0.25s ease-out;
}

.checkbox-input:checked + .checkbox-box {
    background: var(--color-accent);
    border-color: var(--color-accent);
    animation: check-pop 0.2s ease-out;
}

.checkbox-input:checked + .checkbox-box .checkbox-icon {
    opacity: 1;
}

.checkbox-input:checked + .checkbox-box .checkbox-icon path {
    stroke-dashoffset: 0;
}

@keyframes check-pop {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.checkbox-input:focus-visible + .checkbox-box {
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.25);
}

.checkbox-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-top: 1px;
}

.checkbox-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.checkbox-description {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    line-height: 1.4;
}
</style>
