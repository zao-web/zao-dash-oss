<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const foldRef = ref<SVGElement>()
const linesRef = ref<SVGElement>()

const { apply: applyFold } = useMotion(foldRef, { initial: {} })
const { apply: applyLines } = useMotion(linesRef, { initial: {} })

const startAnimation = () => {
  applyFold({ scale: [1, 1.1, 1], transition: { duration: 300, ease: 'easeOut' } })
  applyLines({ opacity: [0.5, 1], y: [2, 0], transition: { duration: 400, ease: 'easeOut', delay: 100 } })
}

const stopAnimation = () => {
  applyFold({ scale: 1, transition: { duration: 200 } })
  applyLines({ opacity: 0.5, y: 0, transition: { duration: 200 } })
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
    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
    <!-- Corner fold -->
    <path ref="foldRef" d="M14 3v4a1 1 0 0 0 1 1h4" style="transform-origin: 16px 5px" />
    <!-- Document body -->
    <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" />
    <!-- Text lines -->
    <g ref="linesRef">
      <path d="M9 17h6" />
      <path d="M9 13h6" />
    </g>
  </svg>
</template>
