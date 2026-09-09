<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const sunRef = ref<SVGElement>()
const centerRef = ref<SVGElement>()

const { apply: applySun } = useMotion(sunRef, { initial: {} })
const { apply: applyCenter } = useMotion(centerRef, { initial: {} })

const startAnimation = () => {
  applySun({ rotate: 45, transition: { duration: 500, ease: 'easeInOut' } })
  applyCenter({ scale: 1.1, transition: { duration: 300, ease: 'easeOut' } })
}

const stopAnimation = () => {
  applySun({ rotate: 0, transition: { duration: 300, ease: 'easeInOut' } })
  applyCenter({ scale: 1, transition: { duration: 200, ease: 'easeInOut' } })
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
    <g ref="sunRef" style="transform-origin: center">
      <!-- Sun center -->
      <circle ref="centerRef" cx="12" cy="12" r="3.75" style="transform-origin: center" />
      <!-- Sun rays -->
      <path d="M12 3v2.25" />
      <path d="M18.364 5.636l-1.591 1.591" />
      <path d="M21 12h-2.25" />
      <path d="M18.364 18.364l-1.591-1.591" />
      <path d="M12 18.75V21" />
      <path d="M5.636 18.364l1.591-1.591" />
      <path d="M3 12h2.25" />
      <path d="M5.636 5.636l1.591 1.591" />
    </g>
  </svg>
</template>
