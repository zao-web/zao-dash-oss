<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const roofRef = ref<SVGElement>()
const bodyRef = ref<SVGElement>()

const { apply: applyRoof } = useMotion(roofRef, { initial: {} })
const { apply: applyBody } = useMotion(bodyRef, { initial: {} })

const startAnimation = () => {
  applyRoof({ y: -1.5, transition: { duration: 250, ease: 'easeOut' } })
  applyBody({ scaleY: 1.02, transition: { duration: 250, ease: 'easeOut', delay: 50 } })
}

const stopAnimation = () => {
  applyRoof({ y: 0, transition: { duration: 200 } })
  applyBody({ scaleY: 1, transition: { duration: 200 } })
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
    <!-- Roof -->
    <path ref="roofRef" d="M3 12l9-9 9 9" />
    <!-- House body -->
    <g ref="bodyRef" style="transform-origin: bottom center">
      <path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" />
      <rect x="9" y="15" width="6" height="6" rx="1" opacity="0.3" />
    </g>
  </svg>
</template>
