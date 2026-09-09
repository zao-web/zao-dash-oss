<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const shackleRef = ref<SVGElement>()

const { apply: applyShackle } = useMotion(shackleRef, { initial: {} })

const startAnimation = () => {
  applyShackle({ rotate: 40, y: -1.7, x: 3, transition: { duration: 280, ease: 'easeOut' } })
}

const stopAnimation = () => {
  applyShackle({ rotate: 0, x: 0, y: 0, transition: { duration: 220, ease: 'easeInOut' } })
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
    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
    <!-- Lock body -->
    <path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" />
    <!-- Keyhole -->
    <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" />
    <!-- Lock shackle -->
    <path ref="shackleRef" d="M8 11v-4a4 4 0 1 1 8 0v4" style="transform-origin: 50% 100%" />
  </svg>
</template>
