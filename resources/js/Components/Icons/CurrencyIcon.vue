<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const symbolRef = ref<SVGElement>()

const { apply: applySymbol } = useMotion(symbolRef, { initial: {} })

const startAnimation = () => {
  applySymbol({ y: -2, scale: [1, 1.05, 1], transition: { duration: 300, ease: 'easeOut' } })
}

const stopAnimation = () => {
  applySymbol({ y: 0, scale: 1, transition: { duration: 200, ease: 'easeInOut' } })
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
    <g ref="symbolRef" style="transform-origin: center">
      <line x1="12" y1="1" x2="12" y2="23" />
      <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6" />
    </g>
  </svg>
</template>
