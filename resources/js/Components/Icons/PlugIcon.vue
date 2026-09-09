<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const upperRef = ref<SVGElement>()
const lowerRef = ref<SVGElement>()
const legsRef = ref<SVGElement>()

const { apply: applyUpper } = useMotion(upperRef, { initial: {} })
const { apply: applyLower } = useMotion(lowerRef, { initial: {} })
const { apply: applyLegs } = useMotion(legsRef, { initial: {} })

const startAnimation = () => {
  applyUpper({ y: 2, x: -2, transition: { duration: 350, ease: 'easeOut' } })
  applyLower({ y: -2, x: 2, transition: { duration: 350, ease: 'easeOut' } })
  applyLegs({ opacity: 0, transition: { duration: 350, ease: 'easeOut' } })
}

const stopAnimation = () => {
  applyUpper({ y: 0, x: 0, transition: { duration: 300, ease: 'easeInOut' } })
  applyLower({ y: 0, x: 0, transition: { duration: 300, ease: 'easeInOut' } })
  applyLegs({ opacity: 1, transition: { duration: 300, ease: 'easeInOut' } })
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
    <!-- Lower plug part -->
    <g ref="lowerRef">
      <path d="M7 12l5 5l-1.5 1.5a3.536 3.536 0 1 1 -5 -5l1.5 -1.5z" />
      <path d="M3 21l2.5 -2.5" />
    </g>
    <!-- Upper plug part -->
    <g ref="upperRef">
      <path d="M17 12l-5 -5l1.5 -1.5a3.536 3.536 0 1 1 5 5l-1.5 1.5z" />
      <path d="M18.5 5.5l2.5 -2.5" />
    </g>
    <!-- Connection legs -->
    <g ref="legsRef">
      <path d="M10 11l-2 2" />
      <path d="M13 14l-2 2" />
    </g>
  </svg>
</template>
