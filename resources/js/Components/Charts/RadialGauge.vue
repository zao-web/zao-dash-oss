<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    value: number;
    max?: number;
    color?: string;
    height?: number;
    showValue?: boolean;
    label?: string;
}>(), {
    max: 100,
    height: 140,
    showValue: true,
});

const percentage = computed(() => {
    if (props.max === 0) return 0;
    return Math.min(Math.round((props.value / props.max) * 100), 100);
});

const dynamicColor = computed(() => {
    if (props.color) return props.color;
    if (percentage.value >= 70) return '#22c55e';
    if (percentage.value >= 40) return '#f59e0b';
    return '#ef4444';
});

// SVG arc calculations for semi-circle
const radius = 56;
const strokeWidth = 10;
const circumference = Math.PI * radius; // Half circle
const dashOffset = computed(() => circumference * (1 - percentage.value / 100));

const viewBoxSize = (radius + strokeWidth) * 2;
const center = radius + strokeWidth;
</script>

<template>
    <div class="radial-gauge" :style="{ height: `${height}px` }">
        <svg :viewBox="`0 0 ${viewBoxSize} ${center + 8}`" class="gauge-svg">
            <!-- Track (background arc) -->
            <path
                :d="`M ${strokeWidth} ${center} A ${radius} ${radius} 0 0 1 ${viewBoxSize - strokeWidth} ${center}`"
                fill="none"
                class="gauge-track"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
            />
            <!-- Progress arc -->
            <path
                :d="`M ${strokeWidth} ${center} A ${radius} ${radius} 0 0 1 ${viewBoxSize - strokeWidth} ${center}`"
                fill="none"
                :stroke="dynamicColor"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="dashOffset"
                class="gauge-progress"
            />
        </svg>
        <div class="gauge-label">
            <span class="gauge-pct">{{ percentage }}%</span>
            <span v-if="label" class="gauge-text">{{ label }}</span>
        </div>
    </div>
</template>

<style scoped>
.radial-gauge {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
}

.gauge-svg {
    width: 100%;
    max-width: 140px;
    overflow: visible;
}

.gauge-track {
    stroke: rgba(255, 255, 255, 0.06);
}

.gauge-progress {
    transition: stroke-dashoffset 0.6s cubic-bezier(0.4, 0, 0.2, 1), stroke 0.3s ease;
}

.gauge-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-top: -12px;
}

.gauge-pct {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
    line-height: 1;
}

.gauge-text {
    font-size: 0.6875rem;
    font-weight: 500;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 4px;
}

/* Light mode */
:global(.light) .gauge-track {
    stroke: rgba(0, 0, 0, 0.08);
}
</style>
