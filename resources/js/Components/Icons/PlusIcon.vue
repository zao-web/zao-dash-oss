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
            :style="{
                transform: isHovered ? 'rotate(90deg)' : 'rotate(0)',
                transition: 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)'
            }"
        >
            <path d="M12 4.5v15m7.5-7.5h-15" />
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
