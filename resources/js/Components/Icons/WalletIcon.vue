<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const bodyRef = ref<SVGElement>()
const claspRef = ref<SVGElement>()

const { apply: applyBody } = useMotion(bodyRef, { initial: {} })
const { apply: applyClasp } = useMotion(claspRef, { initial: {} })

const startAnimation = () => {
  applyBody({ scaleX: 1.03, transition: { duration: 250, ease: 'easeOut' } })
  applyClasp({ x: 2, transition: { duration: 300, ease: 'easeOut', delay: 50 } })
}

const stopAnimation = () => {
  applyBody({ scaleX: 1, transition: { duration: 200, ease: 'easeInOut' } })
  applyClasp({ x: 0, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Wallet body -->
    <g ref="bodyRef" style="transform-origin: left center">
      <rect x="2" y="5" width="20" height="14" rx="2" />
      <path d="M2 10h20" opacity="0.3" />
    </g>
    <!-- Clasp -->
    <rect ref="claspRef" x="16" y="11" width="6" height="4" rx="1" />
  </svg>
</template>
