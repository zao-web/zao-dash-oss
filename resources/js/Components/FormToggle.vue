<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: boolean;
    label?: string;
    description?: string;
    disabled?: boolean;
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
</script>

<template>
    <div class="toggle-group" :class="{ disabled }">
        <label class="toggle-wrapper">
            <input
                v-model="isChecked"
                type="checkbox"
                class="toggle-input"
                :disabled="disabled"
            >
            <span class="toggle-track">
                <span class="toggle-thumb"></span>
            </span>
            <span v-if="label || description" class="toggle-content">
                <span v-if="label" class="toggle-label">{{ label }}</span>
                <span v-if="description" class="toggle-description">{{ description }}</span>
            </span>
        </label>
    </div>
</template>

<style scoped>
.toggle-group {
    margin-bottom: 1rem;
}

.toggle-group.disabled {
    opacity: 0.5;
    pointer-events: none;
}

.toggle-wrapper {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    cursor: pointer;
}

.toggle-input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-track {
    position: relative;
    flex-shrink: 0;
    width: 44px;
    height: 24px;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    transition: all 0.2s ease;
}

.toggle-thumb {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    background: var(--color-text-tertiary);
    border-radius: 50%;
    transition: all 0.2s ease;
}

.toggle-input:checked + .toggle-track {
    background: var(--color-accent);
    border-color: var(--color-accent);
}

.toggle-input:checked + .toggle-track .toggle-thumb {
    transform: translateX(20px);
    background: white;
}

.toggle-input:focus-visible + .toggle-track {
    box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.25);
}

.toggle-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding-top: 2px;
}

.toggle-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.toggle-description {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    line-height: 1.4;
}
</style>
