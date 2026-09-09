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
            <path
                d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"
                :style="{
                    transform: isHovered ? 'translate(-1px, -1px)' : 'translate(0, 0)',
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
