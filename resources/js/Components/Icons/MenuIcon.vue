<script setup lang="ts">
import { ref, computed } from 'vue';

const props = defineProps<{
    size?: number;
    isOpen?: boolean;
    hovered?: boolean;
}>();

const selfHovered = ref(false);
const isHovered = computed(() => props.hovered || selfHovered.value);
</script>

<template>
    <div
        class="icon-wrapper"
        @mouseenter="selfHovered = true"
        @mouseleave="selfHovered = false"
    >
        <svg
            :width="size ?? 20"
            :height="size ?? 20"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <template v-if="!isOpen">
                <path
                    d="M3.75 6.75h16.5"
                    :style="{
                        transform: isHovered ? 'translateX(2px)' : 'translateX(0)',
                        transition: 'transform 0.2s cubic-bezier(0.4, 0, 0.2, 1)'
                    }"
                />
                <path
                    d="M3.75 12h16.5"
                    :style="{
                        transform: isHovered ? 'scaleX(0.85)' : 'scaleX(1)',
                        transformOrigin: 'left',
                        transition: 'transform 0.2s cubic-bezier(0.4, 0, 0.2, 1) 0.05s'
                    }"
                />
                <path
                    d="M3.75 17.25h16.5"
                    :style="{
                        transform: isHovered ? 'translateX(-2px)' : 'translateX(0)',
                        transition: 'transform 0.2s cubic-bezier(0.4, 0, 0.2, 1) 0.1s'
                    }"
                />
            </template>
            <template v-else>
                <path
                    d="M6 18L18 6"
                    :style="{
                        transform: isHovered ? 'rotate(5deg)' : 'rotate(0)',
                        transformOrigin: 'center',
                        transition: 'transform 0.2s ease'
                    }"
                />
                <path
                    d="M6 6l12 12"
                    :style="{
                        transform: isHovered ? 'rotate(-5deg)' : 'rotate(0)',
                        transformOrigin: 'center',
                        transition: 'transform 0.2s ease'
                    }"
                />
            </template>
        </svg>
    </div>
</template>

<style scoped>
.icon-wrapper {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
</style>
