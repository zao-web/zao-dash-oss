<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const userPrimaryRef = ref<SVGElement>()
const userSecondaryRef = ref<SVGElement>()

const { apply: applyUserPrimary } = useMotion(userPrimaryRef, { initial: {} })
const { apply: applyUserSecondary } = useMotion(userSecondaryRef, { initial: {} })

const startAnimation = () => {
  applyUserPrimary({ y: -2, scale: 1.05, transition: { duration: 300, ease: 'easeOut' } })
  applyUserSecondary({ x: 1, opacity: 0.8, transition: { duration: 300, ease: 'easeOut' } })
}

const stopAnimation = () => {
  applyUserPrimary({ y: 0, scale: 1, transition: { duration: 250, ease: 'easeInOut' } })
  applyUserSecondary({ x: 0, opacity: 1, transition: { duration: 250, ease: 'easeInOut' } })
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
    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
    <g ref="userPrimaryRef">
      <path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
      <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
    </g>
    <g ref="userSecondaryRef">
      <path d="M16 3.13a4 4 0 0 1 0 7.75" />
      <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
    </g>
  </svg>
</template>
