<script setup lang="ts">
import { ref, computed } from 'vue';

const props = defineProps<{
    size?: number;
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
            <!-- Magnifying glass circle -->
            <circle
                cx="11"
                cy="11"
                r="7.5"
                :style="{
                    transform: isHovered ? 'scale(1.08)' : 'scale(1)',
                    transformOrigin: 'center',
                    transition: 'transform 0.25s cubic-bezier(0.4, 0, 0.2, 1)'
                }"
            />
            <!-- Handle -->
            <path
                d="M21 21l-5.197-5.197"
                :style="{
                    transform: isHovered ? 'translate(1px, 1px)' : 'translate(0, 0)',
                    transition: 'transform 0.25s cubic-bezier(0.4, 0, 0.2, 1) 0.05s'
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
