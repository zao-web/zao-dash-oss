<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const circleRef = ref<SVGElement>()
const checkRef = ref<SVGElement>()

const { apply: applyCircle } = useMotion(circleRef, { initial: {} })
const { apply: applyCheck } = useMotion(checkRef, { initial: {} })

const startAnimation = () => {
  applyCircle({ scale: [1, 1.05, 1], transition: { duration: 300, ease: 'easeOut' } })
  applyCheck({ scale: [0.8, 1.15, 1], transition: { duration: 350, ease: 'easeInOut', delay: 50 } })
}

const stopAnimation = () => {
  applyCircle({ scale: 1, transition: { duration: 200 } })
  applyCheck({ scale: 1, transition: { duration: 200 } })
}
</script>

<template>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    :width="props.size"
    :height="props.size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.5"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="cursor-pointer"
    @mouseenter="startAnimation"
    @mouseleave="stopAnimation"
  >
    <!-- Circle -->
    <circle ref="circleRef" cx="12" cy="12" r="9" style="transform-origin: center" />
    <!-- Checkmark -->
    <path ref="checkRef" d="M9 12l2 2 4-4" style="transform-origin: center" />
  </svg>
</template>
