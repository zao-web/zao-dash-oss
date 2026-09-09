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
            <!-- Back rectangle -->
            <path
                d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125H4.125A1.125 1.125 0 013 20.625V10.125C3 9.504 3.504 9 4.125 9h3.375"
                :style="{
                    transform: isHovered ? 'translate(-1px, 1px)' : 'translate(0, 0)',
                    transition: 'transform 0.2s cubic-bezier(0.4, 0, 0.2, 1)'
                }"
            />
            <!-- Front rectangle -->
            <path
                d="M8.25 15h7.5c.621 0 1.125-.504 1.125-1.125V3.375c0-.621-.504-1.125-1.125-1.125h-7.5C7.629 2.25 7.125 2.754 7.125 3.375v10.5c0 .621.504 1.125 1.125 1.125z"
                :style="{
                    transform: isHovered ? 'translate(1px, -1px)' : 'translate(0, 0)',
                    transition: 'transform 0.2s cubic-bezier(0.4, 0, 0.2, 1) 0.05s'
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
