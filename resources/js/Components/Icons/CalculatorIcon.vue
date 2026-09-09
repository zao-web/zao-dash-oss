<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const bodyRef = ref<SVGElement>()
const buttonsRef = ref<SVGElement>()

const { apply: applyBody } = useMotion(bodyRef, { initial: {} })
const { apply: applyButtons } = useMotion(buttonsRef, { initial: {} })

const startAnimation = () => {
  applyBody({ scale: 1.04, transition: { duration: 250, ease: 'easeOut' } })
  applyButtons({ opacity: [0.5, 1], transition: { duration: 300, ease: 'easeInOut', delay: 80 } })
}

const stopAnimation = () => {
  applyBody({ scale: 1, transition: { duration: 200 } })
  applyButtons({ opacity: 1, transition: { duration: 200 } })
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
    <!-- Body -->
    <g ref="bodyRef" style="transform-origin: center">
      <rect x="4" y="2" width="16" height="20" rx="2" />
      <rect x="8" y="6" width="8" height="4" rx="1" opacity="0.3" />
    </g>
    <!-- Buttons -->
    <g ref="buttonsRef" style="transform-origin: center">
      <circle cx="8" cy="14" r="0.5" fill="currentColor" />
      <circle cx="12" cy="14" r="0.5" fill="currentColor" />
      <circle cx="16" cy="14" r="0.5" fill="currentColor" />
      <circle cx="8" cy="18" r="0.5" fill="currentColor" />
      <circle cx="12" cy="18" r="0.5" fill="currentColor" />
      <circle cx="16" cy="18" r="0.5" fill="currentColor" />
    </g>
  </svg>
</template>
