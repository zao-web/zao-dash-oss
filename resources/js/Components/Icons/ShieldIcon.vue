<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const shieldRef = ref<SVGElement>()
const checkRef = ref<SVGElement>()

const { apply: applyShield } = useMotion(shieldRef, { initial: {} })
const { apply: applyCheck } = useMotion(checkRef, { initial: {} })

const startAnimation = () => {
  applyShield({ scale: [1, 1.05, 1], transition: { duration: 350, ease: 'easeOut' } })
  applyCheck({ scale: [0.8, 1.1, 1], opacity: [0.5, 1], transition: { duration: 300, ease: 'easeInOut', delay: 100 } })
}

const stopAnimation = () => {
  applyShield({ scale: 1, transition: { duration: 200 } })
  applyCheck({ scale: 1, opacity: 1, transition: { duration: 200 } })
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
    style="overflow: visible"
    @mouseenter="startAnimation"
    @mouseleave="stopAnimation"
  >
    <!-- Shield body -->
    <path
      ref="shieldRef"
      d="M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06"
      style="transform-origin: 50% 50%"
    />
    <!-- Checkmark -->
    <path ref="checkRef" d="M15 19l2 2l4 -4" style="transform-origin: center" />
  </svg>
</template>
