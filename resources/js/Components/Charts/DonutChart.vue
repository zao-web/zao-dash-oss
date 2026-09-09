<script setup lang="ts">
import { computed } from 'vue';
import { VisSingleContainer, VisDonut, VisTooltip } from '@unovis/vue';
import { Donut } from '@unovis/ts';
import ChartTooltip from './ChartTooltip.vue';
import { createApp } from 'vue';

interface DataPoint {
    label: string;
    value: number;
    color: string;
}

const props = withDefaults(defineProps<{
    data: number[];
    labels: string[];
    colors?: string[];
    height?: number;
    showLegend?: boolean;
    innerLabel?: string;
    innerValue?: string | number;
}>(), {
    colors: () => ['#22c55e', '#f59e0b', '#ef4444', '#6366f1', '#8b5cf6'],
    height: 140,
    showLegend: false,
});

const chartData = computed<DataPoint[]>(() =>
    props.data.map((value, i) => ({
        label: props.labels[i] || `Item ${i + 1}`,
        value,
        color: props.colors[i % props.colors.length],
    }))
);

const total = computed(() => props.data.reduce((a, b) => a + b, 0));

const value = (d: DataPoint) => d.value;
const color = (d: DataPoint) => d.color;

const wm = new WeakMap();
function tooltipTriggers() {
    return {
        [Donut.selectors.segment]: (d: DataPoint) => {
            if (wm.has(d)) return wm.get(d);
            const div = document.createElement('div');
            const pct = total.value > 0 ? Math.round((d.value / total.value) * 100) : 0;
            createApp(ChartTooltip, {
                data: [{ name: d.label, color: d.color, value: `${d.value} (${pct}%)` }]
            }).mount(div);
            wm.set(d, div.innerHTML);
            return div.innerHTML;
        }
    };
}
</script>

<template>
    <div class="donut-chart">
        <VisSingleContainer :data="chartData" :height="height">
            <VisDonut
                :value="value"
                :color="color"
                :arc-width="20"
                :corner-radius="3"
                :pad-angle="0.02"
                :central-label="innerValue?.toString()"
                :central-sub-label="innerLabel"
            />
            <VisTooltip :triggers="tooltipTriggers()" :horizontal-shift="16" :vertical-shift="16" />
        </VisSingleContainer>
        <div v-if="showLegend" class="donut-legend">
            <div v-for="(item, i) in chartData" :key="i" class="legend-item">
                <span class="legend-dot" :style="{ background: item.color }"></span>
                <span class="legend-label">{{ item.label }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.donut-chart {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    --vis-donut-central-label-font-size: 20px;
    --vis-donut-central-label-font-weight: 700;
    --vis-donut-central-label-text-color: var(--color-text-primary);
    --vis-donut-central-sub-label-font-size: 11px;
    --vis-donut-central-sub-label-font-weight: 500;
    --vis-donut-central-sub-label-text-color: var(--color-text-tertiary);
    --vis-tooltip-background-color: transparent;
    --vis-tooltip-border-color: transparent;
    --vis-tooltip-shadow-color: transparent;
}

.donut-chart :deep(.unovis-single-container) {
    overflow: visible;
}

.donut-chart :deep(.unovis-tooltip) {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}

.donut-chart :deep(path) {
    transition: opacity 0.15s ease;
}

.donut-chart :deep(path:hover) {
    opacity: 0.85;
}

.donut-legend {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 12px;
    margin-top: 12px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 2px;
}

.legend-label {
    font-size: 11px;
    color: var(--color-text-secondary);
}

/* Light mode */
:global(.light) .donut-chart {
    --vis-donut-central-label-text-color: rgba(0, 0, 0, 0.9);
    --vis-donut-central-sub-label-text-color: rgba(0, 0, 0, 0.5);
}
</style>
