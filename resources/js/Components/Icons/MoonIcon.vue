<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const moonRef = ref<SVGElement>()

const { apply: applyMoon } = useMotion(moonRef, { initial: {} })

const startAnimation = () => {
  applyMoon({ rotate: [0, -15, 0], scale: [1, 1.1, 1], transition: { duration: 500, ease: 'easeInOut' } })
}

const stopAnimation = () => {
  applyMoon({ rotate: 0, scale: 1, transition: { duration: 200, ease: 'easeOut' } })
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
    <path
      ref="moonRef"
      d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454z"
      style="transform-origin: center"
    />
  </svg>
</template>
