<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const centerRef = ref<SVGElement>()
const leftRef = ref<SVGElement>()
const rightRef = ref<SVGElement>()

const { apply: applyCenter } = useMotion(centerRef, { initial: {} })
const { apply: applyLeft } = useMotion(leftRef, { initial: {} })
const { apply: applyRight } = useMotion(rightRef, { initial: {} })

const startAnimation = () => {
  applyCenter({ y: -3, scale: 1.08, transition: { duration: 250, ease: 'easeOut' } })
  applyLeft({ x: -3, opacity: 1, transition: { duration: 250, ease: 'easeOut', delay: 50 } })
  applyRight({ x: 3, opacity: 1, transition: { duration: 250, ease: 'easeOut', delay: 50 } })
}

const stopAnimation = () => {
  applyCenter({ y: 0, scale: 1, transition: { duration: 200, ease: 'easeInOut' } })
  applyLeft({ x: 0, opacity: 0.7, transition: { duration: 200, ease: 'easeInOut' } })
  applyRight({ x: 0, opacity: 0.7, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Center person -->
    <g ref="centerRef" style="transform-origin: center">
      <circle cx="12" cy="5" r="3" />
      <path d="M12 8a5 5 0 015 5v2H7v-2a5 5 0 015-5z" />
    </g>
    <!-- Left person -->
    <g ref="leftRef" opacity="0.7">
      <circle cx="5" cy="9" r="2.5" />
      <path d="M5 11.5a3.5 3.5 0 00-3.5 3.5v1h7v-1a3.5 3.5 0 00-3.5-3.5z" />
    </g>
    <!-- Right person -->
    <g ref="rightRef" opacity="0.7">
      <circle cx="19" cy="9" r="2.5" />
      <path d="M19 11.5a3.5 3.5 0 013.5 3.5v1h-7v-1a3.5 3.5 0 013.5-3.5z" />
    </g>
  </svg>
</template>
