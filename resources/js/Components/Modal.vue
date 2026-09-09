<script setup lang="ts">
import { watch, onMounted, onUnmounted } from 'vue';
import { CloseIcon } from '@/Components/Icons';

const props = withDefaults(defineProps<{
    show: boolean;
    title?: string;
    size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
    closeable?: boolean;
}>(), {
    size: 'md',
    closeable: true,
});

const emit = defineEmits<{
    close: [];
}>();

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const handleEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && props.show) {
        close();
    }
};

onMounted(() => {
    document.addEventListener('keydown', handleEscape);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
});

watch(() => props.show, (show) => {
    document.body.style.overflow = show ? 'hidden' : '';
});

const sizeClasses: Record<string, string> = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
    full: 'max-w-6xl',
};
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="modal-backdrop" @click.self="close">
                <Transition
                    enter-active-class="transition-all duration-200"
                    leave-active-class="transition-all duration-150"
                    enter-from-class="opacity-0 scale-95 translate-y-4"
                    leave-to-class="opacity-0 scale-95 translate-y-4"
                >
                    <div v-if="show" :class="['modal-content', sizeClasses[size]]">
                        <!-- Header -->
                        <div v-if="title || $slots.header" class="modal-header">
                            <slot name="header">
                                <h2 class="modal-title">{{ title }}</h2>
                            </slot>
                            <button v-if="closeable" @click="close" class="modal-close">
                                <CloseIcon :size="20" />
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="modal-body">
                            <slot />
                        </div>

                        <!-- Footer -->
                        <div v-if="$slots.footer" class="modal-footer">
                            <slot name="footer" />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 4rem 1rem;
    overflow-y: auto;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
}

.modal-content {
    width: 100%;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.modal-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.modal-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: transparent;
    border: none;
    color: var(--color-text-tertiary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.modal-close:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--color-border-subtle);
    background: var(--color-bg-tertiary);
    border-radius: 0 0 12px 12px;
}
</style>
