<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const starRef = ref<SVGElement>()

const { apply } = useMotion(starRef, { initial: {} })

const startAnimation = () => {
  apply({ scale: [1, 1.2, 1], rotate: [0, 15, -10, 0], transition: { duration: 500, ease: 'easeOut' } })
}

const stopAnimation = () => {
  apply({ scale: 1, rotate: 0, transition: { duration: 250 } })
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
    <path
      ref="starRef"
      d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"
      style="transform-origin: center"
    />
  </svg>
</template>
