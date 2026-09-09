<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const leftPageRef = ref<SVGElement>()
const rightPageRef = ref<SVGElement>()
const spineRef = ref<SVGElement>()

const { apply: applyLeftPage } = useMotion(leftPageRef, { initial: {} })
const { apply: applyRightPage } = useMotion(rightPageRef, { initial: {} })
const { apply: applySpine } = useMotion(spineRef, { initial: {} })

const startAnimation = () => {
  applyLeftPage({ x: -2, rotateY: -15, transition: { duration: 300, ease: 'easeOut' } })
  applyRightPage({ x: 2, rotateY: 15, transition: { duration: 300, ease: 'easeOut' } })
  applySpine({ opacity: 0.5, transition: { duration: 200, ease: 'easeOut' } })
}

const stopAnimation = () => {
  applyLeftPage({ x: 0, rotateY: 0, transition: { duration: 250, ease: 'easeInOut' } })
  applyRightPage({ x: 0, rotateY: 0, transition: { duration: 250, ease: 'easeInOut' } })
  applySpine({ opacity: 1, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Left page -->
    <path
      ref="leftPageRef"
      d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292"
      style="transform-origin: right center"
    />
    <!-- Right page -->
    <path
      ref="rightPageRef"
      d="M12 6.042a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292"
      style="transform-origin: left center"
    />
    <!-- Center spine -->
    <path ref="spineRef" d="M12 6.042v14.25" />
  </svg>
</template>
