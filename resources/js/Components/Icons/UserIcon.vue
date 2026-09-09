<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const headRef = ref<SVGElement>()
const bodyRef = ref<SVGElement>()

const { apply: applyHead } = useMotion(headRef, { initial: {} })
const { apply: applyBody } = useMotion(bodyRef, { initial: {} })

const startAnimation = () => {
  applyHead({ y: -1, scale: 1.05, transition: { duration: 250, ease: 'easeOut' } })
  applyBody({ y: 1, transition: { duration: 250, ease: 'easeOut', delay: 50 } })
}

const stopAnimation = () => {
  applyHead({ y: 0, scale: 1, transition: { duration: 200, ease: 'easeInOut' } })
  applyBody({ y: 0, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Head -->
    <circle ref="headRef" cx="12" cy="7.5" r="3.75" style="transform-origin: center" />
    <!-- Body -->
    <path ref="bodyRef" d="M4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
  </svg>
</template>
