<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const bar1Ref = ref<SVGElement>()
const bar2Ref = ref<SVGElement>()
const bar3Ref = ref<SVGElement>()
const bar4Ref = ref<SVGElement>()

const { apply: applyBar1 } = useMotion(bar1Ref, { initial: {} })
const { apply: applyBar2 } = useMotion(bar2Ref, { initial: {} })
const { apply: applyBar3 } = useMotion(bar3Ref, { initial: {} })
const { apply: applyBar4 } = useMotion(bar4Ref, { initial: {} })

const startAnimation = () => {
  applyBar1({ scaleY: [0, 1], transition: { duration: 300, ease: 'easeOut' } })
  applyBar2({ scaleY: [0, 1], transition: { duration: 300, ease: 'easeOut', delay: 50 } })
  applyBar3({ scaleY: [0, 1], transition: { duration: 300, ease: 'easeOut', delay: 100 } })
  applyBar4({ scaleY: [0, 1], transition: { duration: 300, ease: 'easeOut', delay: 150 } })
}

const stopAnimation = () => {
  applyBar1({ scaleY: 1, transition: { duration: 200, ease: 'easeInOut' } })
  applyBar2({ scaleY: 1, transition: { duration: 200, ease: 'easeInOut' } })
  applyBar3({ scaleY: 1, transition: { duration: 200, ease: 'easeInOut' } })
  applyBar4({ scaleY: 1, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Baseline -->
    <path d="M3 3v18h18" />
    <!-- Bar 1 -->
    <path ref="bar1Ref" d="M7 12v5" style="transform-origin: 7px 17px" />
    <!-- Bar 2 -->
    <path ref="bar2Ref" d="M11 8v9" style="transform-origin: 11px 17px" />
    <!-- Bar 3 -->
    <path ref="bar3Ref" d="M15 5v12" style="transform-origin: 15px 17px" />
    <!-- Bar 4 -->
    <path ref="bar4Ref" d="M19 9v8" style="transform-origin: 19px 17px" />
  </svg>
</template>
