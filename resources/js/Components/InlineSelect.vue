<script setup lang="ts">
/**
 * InlineSelect - Alias for FormSelect with inline/status variants
 *
 * This is a convenience wrapper. You can also use FormSelect directly with:
 * - variant="inline" for compact inline dropdowns
 * - variant="status" for status dropdowns with colored dots
 */
import FormSelect from './FormSelect.vue';

interface Option {
    value: string | number | null;
    label: string;
    color?: string;
    group?: string;
}

const props = withDefaults(defineProps<{
    modelValue: string | number | null;
    options: Option[];
    placeholder?: string;
    size?: 'sm' | 'md';
    variant?: 'default' | 'status' | 'inline';
    searchable?: boolean;
}>(), {
    placeholder: 'Select...',
    size: 'md',
    variant: 'inline',
    searchable: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string | number | null];
}>();
</script>

<template>
    <FormSelect
        :model-value="modelValue"
        :options="options"
        :placeholder="placeholder"
        :size="size"
        :variant="variant"
        :searchable="searchable"
        @update:model-value="emit('update:modelValue', $event)"
    />
</template>
