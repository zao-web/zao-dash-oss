<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const doorRef = ref<SVGElement>()
const arrowRef = ref<SVGElement>()

const { apply: applyDoor } = useMotion(doorRef, { initial: {} })
const { apply: applyArrow } = useMotion(arrowRef, { initial: {} })

const startAnimation = () => {
  applyArrow({ x: [0, 6, 0], transition: { duration: 300, ease: 'easeInOut' } })
  applyDoor({ x: [0, -2, 0], transition: { duration: 250, ease: 'easeOut' } })
}

const stopAnimation = () => {
  applyArrow({ x: 0, transition: { duration: 200, ease: 'easeInOut' } })
  applyDoor({ x: 0, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Door frame -->
    <path
      ref="doorRef"
      d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"
      style="transform-origin: 50% 50%"
    />
    <!-- Arrow -->
    <g ref="arrowRef">
      <path d="M9 12h12" />
      <path d="M18 15l3 -3l-3 -3" />
    </g>
  </svg>
</template>
