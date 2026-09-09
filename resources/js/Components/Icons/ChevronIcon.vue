<script setup lang="ts">
import { ref, computed } from 'vue';

const props = defineProps<{
    size?: number;
    direction?: 'up' | 'down' | 'left' | 'right';
    expanded?: boolean;
    hovered?: boolean;
}>();

const selfHovered = ref(false);
const isHovered = computed(() => props.hovered || selfHovered.value);

const rotation = {
    up: '-90deg',
    down: '90deg',
    left: '180deg',
    right: '0deg'
};
</script>

<template>
    <div
        class="icon-wrapper"
        @mouseenter="selfHovered = true"
        @mouseleave="selfHovered = false"
        :style="{
            transform: `rotate(${props.expanded ? '90deg' : rotation[props.direction ?? 'right']})`,
            transition: 'transform 0.2s ease'
        }"
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
            <path
                d="M8.25 4.5l7.5 7.5-7.5 7.5"
                :style="{
                    transform: isHovered ? 'translateX(2px)' : 'translateX(0)',
                    transition: 'transform 0.2s cubic-bezier(0.4, 0, 0.2, 1)'
                }"
            />
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
