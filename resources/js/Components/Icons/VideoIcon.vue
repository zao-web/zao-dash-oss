<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const bodyRef = ref<SVGElement>()
const lensRef = ref<SVGElement>()
const indicatorRef = ref<SVGElement>()

const { apply: applyBody } = useMotion(bodyRef, { initial: {} })
const { apply: applyLens } = useMotion(lensRef, { initial: {} })
const { apply: applyIndicator } = useMotion(indicatorRef, { initial: { opacity: 0, scale: 0 } })

const startAnimation = () => {
  applyBody({ scale: 1.03, transition: { duration: 250, ease: 'easeOut' } })
  applyLens({ x: 2, transition: { duration: 300, ease: 'easeOut' } })
  applyIndicator({ opacity: 1, scale: 1, transition: { duration: 200, ease: 'easeOut', delay: 150 } })
}

const stopAnimation = () => {
  applyBody({ scale: 1, transition: { duration: 200, ease: 'easeInOut' } })
  applyLens({ x: 0, transition: { duration: 200, ease: 'easeInOut' } })
  applyIndicator({ opacity: 0, scale: 0, transition: { duration: 150, ease: 'easeInOut' } })
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
    <!-- Camera body -->
    <rect ref="bodyRef" x="2" y="5" width="14" height="14" rx="2" style="transform-origin: center" />
    <!-- Lens arrow -->
    <path ref="lensRef" d="M16 9l4.5-2.5a1 1 0 011.5.87v9.26a1 1 0 01-1.5.87L16 15" />
    <!-- Recording indicator -->
    <circle ref="indicatorRef" cx="6" cy="9" r="1.5" fill="currentColor" stroke="none" style="transform-origin: center" />
  </svg>
</template>
