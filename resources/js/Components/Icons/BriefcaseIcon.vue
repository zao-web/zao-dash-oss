<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const handleRef = ref<SVGElement>()
const bodyRef = ref<SVGElement>()

const { apply: applyHandle } = useMotion(handleRef, { initial: {} })
const { apply: applyBody } = useMotion(bodyRef, { initial: {} })

const startAnimation = () => {
  applyHandle({ y: -2, transition: { duration: 250, ease: 'easeOut' } })
  applyBody({ scaleY: 1.05, y: 1, transition: { duration: 300, ease: 'easeOut' } })
}

const stopAnimation = () => {
  applyHandle({ y: 0, transition: { duration: 200, ease: 'easeInOut' } })
  applyBody({ scaleY: 1, y: 0, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Handle -->
    <path ref="handleRef" d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2" />
    <!-- Main body -->
    <g ref="bodyRef" style="transform-origin: center bottom">
      <rect x="2" y="6" width="20" height="14" rx="2" />
      <line x1="2" y1="12" x2="22" y2="12" opacity="0.3" />
    </g>
  </svg>
</template>
