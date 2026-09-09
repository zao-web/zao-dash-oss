<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const bodyRef = ref<SVGElement>()
const coinRef = ref<SVGElement>()

const { apply: applyBody } = useMotion(bodyRef, { initial: {} })
const { apply: applyCoin } = useMotion(coinRef, { initial: {} })

const startAnimation = () => {
  applyBody({ rotate: [0, -3, 3, 0], transition: { duration: 400, ease: 'easeOut' } })
  applyCoin({ y: [0, -3, 0], opacity: [1, 0.5, 1], transition: { duration: 400, ease: 'easeOut', delay: 50 } })
}

const stopAnimation = () => {
  applyBody({ rotate: 0, transition: { duration: 200 } })
  applyCoin({ y: 0, opacity: 1, transition: { duration: 200 } })
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
    <!-- Piggy body -->
    <g ref="bodyRef" style="transform-origin: center">
      <path d="M19 5c-1.5 0-2.8 1.4-3 2-3.5-1.5-11-.3-11 5 0 1.8 0 3 2 4.5V20h4v-2h3v2h4v-4c1-.5 1.7-1 2-2h2v-4h-2c0-1-.5-1.5-1-2" />
      <path d="M2 9.5c1 0 2 1 2 2.5" />
      <!-- Eye -->
      <circle cx="15.5" cy="10" r="0.5" fill="currentColor" />
    </g>
    <!-- Coin slot -->
    <line ref="coinRef" x1="11" y1="5" x2="13" y2="5" style="transform-origin: center" />
  </svg>
</template>
