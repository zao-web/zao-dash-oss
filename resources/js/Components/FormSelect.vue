<script setup lang="ts">
import { computed, ref, nextTick, watch } from 'vue';

interface Option {
    value: string | number | null;
    label: string;
    description?: string;
    disabled?: boolean;
    color?: string;
    group?: string;
}

const props = withDefaults(defineProps<{
    modelValue: string | number | null;
    options?: Option[];
    label?: string;
    placeholder?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    disabled?: boolean;
    variant?: 'default' | 'status' | 'inline';
    size?: 'sm' | 'md';
    searchable?: boolean;
}>(), {
    options: () => [],
    required: false,
    disabled: false,
    placeholder: 'Select...',
    variant: 'default',
    size: 'md',
    searchable: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string | number | null];
}>();

const isOpen = ref(false);
const selectRef = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const searchQuery = ref('');
const highlightedIndex = ref(-1);

const selectedOption = computed(() => {
    if (!props.options || !Array.isArray(props.options)) return null;
    return props.options.find(o => o.value === props.modelValue) || null;
});

const filteredOptions = computed(() => {
    if (!props.options || !Array.isArray(props.options)) return [];
    if (!props.searchable || !searchQuery.value) {
        return props.options;
    }
    const query = searchQuery.value.toLowerCase();
    return props.options.filter(option =>
        option.label.toLowerCase().includes(query)
        || option.group?.toLowerCase().includes(query)
        || option.description?.toLowerCase().includes(query)
    );
});

/**
 * Check if a group header should be shown before this option.
 * Returns the group name if this is the first option in its group.
 */
const getGroupHeader = (index: number): string | null => {
    const option = filteredOptions.value[index];
    if (!option?.group) return null;
    // Show header if this is the first option with this group
    const prevOption = index > 0 ? filteredOptions.value[index - 1] : null;
    if (!prevOption || prevOption.group !== option.group) {
        return option.group;
    }
    return null;
};

const selectOption = (option: Option) => {
    if (option.disabled) return;
    emit('update:modelValue', option.value);
    isOpen.value = false;
    searchQuery.value = '';
};

const handleClickOutside = (e: MouseEvent) => {
    if (selectRef.value && !selectRef.value.contains(e.target as Node)) {
        isOpen.value = false;
        document.removeEventListener('click', handleClickOutside);
    }
};

const toggleOpen = () => {
    if (props.disabled) return;
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        setTimeout(() => document.addEventListener('click', handleClickOutside), 0);
    }
};

watch(isOpen, (val) => {
    if (val) {
        if (props.searchable) {
            highlightedIndex.value = filteredOptions.value.findIndex(o => o.value === props.modelValue);
            if (highlightedIndex.value === -1 && filteredOptions.value.length > 0) {
                highlightedIndex.value = 0;
            }
            nextTick(() => {
                searchInput.value?.focus();
            });
        }
    } else {
        highlightedIndex.value = -1;
        // Small delay to allow fade out before clearing search
        setTimeout(() => {
            searchQuery.value = '';
        }, 150);
    }
});

const handleKeydown = (e: KeyboardEvent) => {
    if (props.disabled) return;

    if (!isOpen.value) {
        if (['Enter', ' ', 'ArrowDown', 'ArrowUp'].includes(e.key)) {
            e.preventDefault();
            toggleOpen();
        }
        return;
    }

    switch (e.key) {
        case 'ArrowDown':
            e.preventDefault();
            if (filteredOptions.value.length > 0) {
                highlightedIndex.value = (highlightedIndex.value + 1) % filteredOptions.value.length;
                scrollToHighlighted();
            }
            break;
        case 'ArrowUp':
            e.preventDefault();
            if (filteredOptions.value.length > 0) {
                highlightedIndex.value = (highlightedIndex.value - 1 + filteredOptions.value.length) % filteredOptions.value.length;
                scrollToHighlighted();
            }
            break;
        case 'Enter':
            e.preventDefault();
            if (filteredOptions.value.length > 0 && highlightedIndex.value >= 0) {
                selectOption(filteredOptions.value[highlightedIndex.value]);
            }
            break;
        case 'Escape':
            e.preventDefault();
            isOpen.value = false;
            break;
    }
};

const scrollToHighlighted = () => {
    nextTick(() => {
        const list = selectRef.value?.querySelector('.dropdown');
        const activeItem = selectRef.value?.querySelector('.dropdown-option.is-highlighted') as HTMLElement;
        
        if (list && activeItem) {
            if (activeItem.offsetTop + activeItem.offsetHeight > list.scrollTop + list.clientHeight) {
                list.scrollTop = activeItem.offsetTop + activeItem.offsetHeight - list.clientHeight;
            } else if (activeItem.offsetTop < list.scrollTop) {
                list.scrollTop = activeItem.offsetTop;
            }
        }
    });
};

const isInline = computed(() => props.variant === 'inline' || props.variant === 'status');
</script>

<template>
    <div :class="['form-select-wrapper', `variant-${variant}`, `size-${size}`, { 'is-inline': isInline }]">
        <label v-if="label && !isInline" class="form-label">
            {{ label }}
            <span v-if="required" class="required-mark">*</span>
        </label>
        <div
            ref="selectRef"
            :class="['select-container', { 'is-open': isOpen, 'has-error': error, 'is-disabled': disabled }]"
        >
            <component
                :is="(searchable && isOpen) ? 'div' : 'button'"
                :type="(searchable && isOpen) ? undefined : 'button'"
                class="select-trigger"
                :style="variant === 'status' && selectedOption?.color ? { '--status-color': selectedOption.color } : {}"
                :disabled="disabled"
                @click="toggleOpen"
                @keydown="handleKeydown"
            >
                <input
                    v-if="searchable && isOpen"
                    ref="searchInput"
                    v-model="searchQuery"
                    type="text"
                    class="search-input"
                    :placeholder="selectedOption ? selectedOption.label : placeholder"
                    @click.stop
                    @keydown="handleKeydown"
                />
                <template v-else>
                    <span v-if="selectedOption" class="selected-label">
                        <span v-if="variant === 'status'" class="status-dot"></span>
                        {{ selectedOption.label }}
                    </span>
                    <span v-else class="placeholder">{{ placeholder }}</span>
                </template>
                <svg class="chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </component>

            <Transition name="dropdown">
                <div v-if="isOpen" class="dropdown">
                    <div v-if="filteredOptions.length === 0" class="dropdown-empty">
                        No results found
                    </div>
                    <template v-for="(option, index) in filteredOptions" :key="option.value ?? `null-${index}`">
                        <div v-if="getGroupHeader(index)" class="dropdown-group-header">
                            {{ getGroupHeader(index) }}
                        </div>
                        <button
                            type="button"
                            :class="['dropdown-option', {
                                'is-selected': option.value === modelValue,
                                'is-disabled': option.disabled,
                                'is-highlighted': index === highlightedIndex
                            }]"
                            :style="variant === 'status' && option.color ? { '--status-color': option.color } : {}"
                            :disabled="option.disabled"
                            @click="selectOption(option)"
                            @mouseenter="highlightedIndex = index"
                        >
                            <span v-if="variant === 'status'" class="status-dot"></span>
                            <span class="option-content">
                                <span class="option-label">{{ option.label }}</span>
                                <span v-if="option.description" class="option-description">{{ option.description }}</span>
                            </span>
                            <svg v-if="option.value === modelValue" class="check-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </template>
                </div>
            </Transition>
        </div>
        <p v-if="error && !isInline" class="form-error">{{ error }}</p>
        <p v-else-if="hint && !isInline" class="form-hint">{{ hint }}</p>
    </div>
</template>

<style scoped>
.form-select-wrapper {
    margin-bottom: 1rem;
}

.form-select-wrapper.is-inline {
    margin-bottom: 0;
    display: inline-block;
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

.select-container {
    position: relative;
}

/* Default variant - full width form field */
.select-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    padding: 10px 12px;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    color: var(--color-text-primary);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
    text-align: left;
}

/* Inline variant - compact, inline-block */
.is-inline .select-trigger {
    width: auto;
    padding: 6px 10px;
    font-size: 13px;
    min-width: max-content;
}

/* Inline variant should not show focus ring when open */
.is-inline .select-container.is-open .select-trigger {
    box-shadow: none;
    border-color: var(--color-border-hover);
}

.size-sm .select-trigger {
    padding: 4px 8px;
    font-size: 12px;
}

.select-trigger:hover:not(:disabled) {
    background: var(--color-bg-secondary);
    border-color: var(--color-border-hover);
}

.select-container.is-open .select-trigger {
    border-color: var(--color-accent);
    box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.15);
}

/* Remove focus ring when search input is being used */
.select-container.is-open .select-trigger:has(.search-input) {
    box-shadow: none;
}

.select-container.has-error .select-trigger {
    border-color: var(--color-status-red);
}

.select-container.is-disabled .select-trigger {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Status variant border */
.variant-status .select-trigger {
    border-width: 2px;
    border-color: var(--status-color, var(--color-border-default));
}

.variant-status .select-trigger:hover:not(:disabled) {
    border-color: var(--status-color, var(--color-border-hover));
}

.selected-label {
    display: flex;
    align-items: center;
    gap: 6px;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--status-color, var(--color-text-tertiary));
    flex-shrink: 0;
}

.placeholder {
    color: var(--color-text-quaternary);
}

.chevron {
    width: 16px;
    height: 16px;
    color: var(--color-text-tertiary);
    transition: transform 0.15s ease;
    flex-shrink: 0;
}

.is-inline .chevron {
    width: 14px;
    height: 14px;
}

.is-open .chevron {
    transform: rotate(180deg);
}

.dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    min-width: 100%;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.25), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
    z-index: 100;
    overflow: hidden;
    max-height: 240px;
    overflow-y: auto;
}

.is-inline .dropdown {
    min-width: max-content;
}

.dropdown-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    padding: 10px 12px;
    background: none;
    border: none;
    color: var(--color-text-primary);
    font-size: 0.875rem;
    text-align: left;
    cursor: pointer;
    transition: background 0.1s ease;
}

.is-inline .dropdown-option {
    padding: 8px 12px;
    font-size: 13px;
}

.dropdown-option:hover:not(.is-disabled),
.dropdown-option.is-highlighted:not(.is-disabled) {
    background: var(--color-bg-tertiary);
}

.dropdown-option.is-selected {
    background: rgba(139, 92, 246, 0.1);
    color: var(--color-accent);
}

.dropdown-option.is-disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.option-label {
    display: block;
}

.option-content {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    gap: 2px;
}

.option-description {
    color: var(--color-text-tertiary);
    font-size: 11px;
    line-height: 1.35;
}

.check-icon {
    width: 16px;
    height: 16px;
    color: var(--color-accent);
    flex-shrink: 0;
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

.search-input {
    width: 100%;
    border: none;
    background: transparent;
    padding: 0;
    margin: 0;
    font-size: inherit;
    font-weight: inherit;
    color: inherit;
    outline: none;
    box-shadow: none;
    flex: 1;
    min-width: 0;
    caret-color: var(--color-accent);
    -webkit-appearance: none;
    appearance: none;
}

.search-input:focus,
.search-input:focus-visible {
    outline: none;
    box-shadow: none;
    border: none;
}

.search-input::placeholder {
    color: var(--color-text-quaternary);
}

.dropdown-group-header {
    padding: 6px 12px 4px;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-quaternary);
    border-top: 1px solid var(--color-border-default);
    margin-top: 2px;
}

.dropdown-group-header:first-child {
    border-top: none;
    margin-top: 0;
}

.dropdown-empty {
    padding: 10px 12px;
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
    text-align: center;
}

/* Transition */
.dropdown-enter-active,
.dropdown-leave-active {
    transition: all 0.15s ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>
