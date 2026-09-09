<script setup lang="ts">
import { ref } from 'vue'
import { useMotion } from '@vueuse/motion'

const props = withDefaults(defineProps<{
  size?: number
}>(), {
  size: 20
})

const folderRef = ref<SVGElement>()
const docRef = ref<SVGElement>()

const { apply: applyFolder } = useMotion(folderRef, { initial: {} })
const { apply: applyDoc } = useMotion(docRef, { initial: { opacity: 0, y: 0 } })

const startAnimation = () => {
  applyFolder({ rotateX: -8, transition: { duration: 300, ease: 'easeOut' } })
  applyDoc({ opacity: 0.4, y: -2, transition: { duration: 250, ease: 'easeOut', delay: 100 } })
}

const stopAnimation = () => {
  applyFolder({ rotateX: 0, transition: { duration: 250, ease: 'easeInOut' } })
  applyDoc({ opacity: 0, y: 0, transition: { duration: 200, ease: 'easeInOut' } })
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
    <!-- Inner document peek -->
    <rect
      ref="docRef"
      x="6"
      y="10"
      width="12"
      height="8"
      rx="1"
      fill="none"
      stroke-width="1"
    />
    <!-- Folder body -->
    <path
      ref="folderRef"
      d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"
      style="transform-origin: center bottom"
    />
  </svg>
</template>
