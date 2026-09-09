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
            :class="{ 'bell-ring': isHovered }"
        >
            <!-- Bell body -->
            <path
                d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"
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

.bell-ring {
    animation: bell-ring 0.5s ease;
    transform-origin: top center;
}

@keyframes bell-ring {
    0%, 100% { transform: rotate(0); }
    15% { transform: rotate(8deg); }
    30% { transform: rotate(-8deg); }
    45% { transform: rotate(6deg); }
    60% { transform: rotate(-6deg); }
    75% { transform: rotate(3deg); }
    90% { transform: rotate(-3deg); }
}
</style>
