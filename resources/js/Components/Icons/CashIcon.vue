<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const billRef = ref<SVGElement>()
const symbolRef = ref<SVGElement>()

const { apply: applyBill } = useMotion(billRef, { initial: {} })
const { apply: applySymbol } = useMotion(symbolRef, { initial: {} })

const startAnimation = () => {
  applyBill({ scaleX: 1.04, transition: { duration: 250, ease: 'easeOut' } })
  applySymbol({ scale: [1, 1.15, 1], transition: { duration: 350, ease: 'easeOut', delay: 60 } })
}

const stopAnimation = () => {
  applyBill({ scaleX: 1, transition: { duration: 200 } })
  applySymbol({ scale: 1, transition: { duration: 200 } })
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
    <!-- Bill -->
    <g ref="billRef" style="transform-origin: center">
      <rect x="2" y="6" width="20" height="12" rx="2" />
    </g>
    <!-- Dollar symbol -->
    <g ref="symbolRef" style="transform-origin: center">
      <path d="M12 10v4" />
      <path d="M14 10.5c0-.83-.67-1.5-1.5-1.5h-1c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5h1c.83 0 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5h-1c-.83 0-1.5-.67-1.5-1.5" />
    </g>
  </svg>
</template>
