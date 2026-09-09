<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const funnelRef = ref<SVGElement>()
const dripRef = ref<SVGElement>()

const { apply: applyFunnel } = useMotion(funnelRef, { initial: {} })
const { apply: applyDrip } = useMotion(dripRef, { initial: { opacity: 0 } })

const startAnimation = () => {
  applyFunnel({ scaleY: 1.05, transition: { duration: 300, ease: 'easeOut' } })
  applyDrip({ opacity: 0.6, y: [4, 0], transition: { duration: 400, ease: 'easeOut', delay: 100 } })
}

const stopAnimation = () => {
  applyFunnel({ scaleY: 1, transition: { duration: 250, ease: 'easeInOut' } })
  applyDrip({ opacity: 0, y: 0, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Funnel body -->
    <path
      ref="funnelRef"
      d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"
      style="transform-origin: center top"
    />
    <!-- Drip line -->
    <line ref="dripRef" x1="12" y1="19" x2="12" y2="23" />
  </svg>
</template>
