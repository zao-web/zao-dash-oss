<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const pinsRef = ref<SVGElement>()
const innerRef = ref<SVGElement>()
const outerRef = ref<SVGElement>()

const { apply: applyPins } = useMotion(pinsRef, { initial: {} })
const { apply: applyInner } = useMotion(innerRef, { initial: {} })
const { apply: applyOuter } = useMotion(outerRef, { initial: {} })

const startAnimation = () => {
  applyPins({ scale: [1, 1.15, 1], transition: { duration: 500, ease: 'easeInOut' } })
  applyInner({ scale: [1, 0.9, 1], transition: { duration: 500, ease: 'easeInOut' } })
  applyOuter({ scale: [1, 1.05, 1], transition: { duration: 500, ease: 'easeInOut' } })
}

const stopAnimation = () => {
  applyPins({ scale: 1, transition: { duration: 200, ease: 'easeInOut' } })
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
    <g ref="pinsRef" style="transform-origin: center">
      <path d="M12 20v2" />
      <path d="M12 2v2" />
      <path d="M17 20v2" />
      <path d="M17 2v2" />
      <path d="M2 12h2" />
      <path d="M2 17h2" />
      <path d="M2 7h2" />
      <path d="M20 12h2" />
      <path d="M20 17h2" />
      <path d="M20 7h2" />
      <path d="M7 20v2" />
      <path d="M7 2v2" />
    </g>
    <rect ref="outerRef" style="transform-origin: 12px 12px" x="4" y="4" width="16" height="16" rx="2" />
    <rect ref="innerRef" style="transform-origin: 12px 12px" x="8" y="8" width="8" height="8" rx="1" />
  </svg>
</template>
