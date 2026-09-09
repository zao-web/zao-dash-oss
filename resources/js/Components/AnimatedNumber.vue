<script setup lang="ts">
import { ref, watch } from 'vue'

const props = defineProps<{
    value: number
}>()

const isAnimating = ref(false)
const displayValue = ref(props.value)

watch(() => props.value, (newVal, oldVal) => {
    if (newVal !== oldVal) {
        isAnimating.value = true
        displayValue.value = newVal
        setTimeout(() => {
            isAnimating.value = false
        }, 300)
    }
})
</script>

<template>
    <span :class="{ 'count-pop': isAnimating }">{{ displayValue }}</span>
</template>

<style scoped>
@keyframes count-pop {
    0% { transform: scale(1); }
    50% { transform: scale(1.15); }
    100% { transform: scale(1); }
}

.count-pop {
    display: inline-block;
    animation: count-pop 0.3s ease-out;
    color: var(--color-accent);
}
</style>
