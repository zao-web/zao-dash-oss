<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const sparkleMainRef = ref<SVGElement>()
const sparkleTopRef = ref<SVGElement>()
const sparkleBottomRef = ref<SVGElement>()

const { apply: applySparkleMain } = useMotion(sparkleMainRef, { initial: {} })
const { apply: applySparkleTop } = useMotion(sparkleTopRef, { initial: {} })
const { apply: applySparkleBottom } = useMotion(sparkleBottomRef, { initial: {} })

const startAnimation = () => {
  applySparkleMain({ rotate: 180, scale: [1, 1.2, 1], transition: { duration: 600, ease: 'easeInOut' } })
  applySparkleTop({ rotate: -90, scale: [1, 0.8, 1.1], opacity: [1, 0.6, 1], transition: { duration: 500, ease: 'easeInOut', delay: 100 } })
  applySparkleBottom({ rotate: 90, scale: [1, 1.15, 0.9], opacity: [1, 0.7, 1], transition: { duration: 500, ease: 'easeInOut', delay: 50 } })
}

const stopAnimation = () => {
  applySparkleMain({ rotate: 0, scale: 1, transition: { duration: 250 } })
  applySparkleTop({ rotate: 0, scale: 1, opacity: 1, transition: { duration: 250 } })
  applySparkleBottom({ rotate: 0, scale: 1, opacity: 1, transition: { duration: 250 } })
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
    <path ref="sparkleBottomRef" d="M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z" style="transform-origin: 18px 18px" />
    <path ref="sparkleTopRef" d="M16 6a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z" style="transform-origin: 18px 6px" />
    <path ref="sparkleMainRef" d="M9 18a6 6 0 0 1 6 -6a6 6 0 0 1 -6 -6a6 6 0 0 1 -6 6a6 6 0 0 1 6 6z" style="transform-origin: 9px 12px" />
  </svg>
</template>
