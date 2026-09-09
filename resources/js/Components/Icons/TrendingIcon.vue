<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const lineRef = ref<SVGElement>()
const arrowRef = ref<SVGElement>()

const { apply: applyLine } = useMotion(lineRef, { initial: {} })
const { apply: applyArrow } = useMotion(arrowRef, { initial: {} })

const startAnimation = () => {
  applyLine({ y: -1, transition: { duration: 300, ease: 'easeOut' } })
  applyArrow({ y: -2, transition: { duration: 350, ease: 'easeOut', delay: 50 } })
}

const stopAnimation = () => {
  applyLine({ y: 0, transition: { duration: 200 } })
  applyArrow({ y: 0, transition: { duration: 200 } })
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
    <!-- Trending line -->
    <polyline ref="lineRef" points="22 7 13.5 15.5 8.5 10.5 2 17" />
    <!-- Arrow head -->
    <polyline ref="arrowRef" points="16 7 22 7 22 13" />
  </svg>
</template>
