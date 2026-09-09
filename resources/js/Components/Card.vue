<script setup lang="ts">
withDefaults(defineProps<{
    glow?: boolean;
    padding?: boolean;
}>(), {
    glow: true,
    padding: false,
});
</script>

<template>
    <div :class="['card', glow && 'card-glow', padding && 'p-6']">
        <slot />
    </div>
</template>
