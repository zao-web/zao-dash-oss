<script setup lang="ts">
import { computed } from 'vue';
import { VisXYContainer, VisStackedBar, VisAxis, VisTooltip } from '@unovis/vue';
import { StackedBar } from '@unovis/ts';
import ChartTooltip from './ChartTooltip.vue';
import { createApp } from 'vue';

interface DataPoint {
    name: string;
    value: number;
    color?: string;
}

const props = withDefaults(defineProps<{
    data: DataPoint[];
    horizontal?: boolean;
    height?: number;
    showLabels?: boolean;
    colors?: string[];
    barRadius?: number;
}>(), {
    horizontal: true,
    height: 120,
    showLabels: true,
    colors: () => ['#8b5cf6', '#6366f1', '#a78bfa', '#c4b5fd'],
    barRadius: 4,
});

const x = (_: DataPoint, i: number) => i;
const y = (d: DataPoint) => d.value;

const barColor = (d: DataPoint, i: number) => d.color || props.colors[i % props.colors.length];

const wm = new WeakMap();
function tooltipTriggers() {
    return {
        [StackedBar.selectors.bar]: (d: DataPoint) => {
            if (wm.has(d)) return wm.get(d);
            const div = document.createElement('div');
            const color = d.color || props.colors[0];
            createApp(ChartTooltip, {
                title: d.name,
                data: [{ name: 'Value', color, value: d.value.toLocaleString() }]
            }).mount(div);
            wm.set(d, div.innerHTML);
            return div.innerHTML;
        }
    };
}
</script>

<template>
    <div class="bar-chart" :style="{ height: `${height}px` }">
        <VisXYContainer
            :data="data"
            :height="height"
            :margin="{ top: 8, right: 8, bottom: horizontal ? 8 : 24, left: horizontal && showLabels ? 80 : 8 }"
        >
            <VisStackedBar
                :x="x"
                :y="y"
                :color="barColor"
                :bar-padding="0.3"
                :rounded-corners="barRadius"
                :orientation="horizontal ? 'horizontal' : 'vertical'"
            />
            <VisAxis
                v-if="showLabels && horizontal"
                type="y"
                :tick-format="(i: number) => data[i]?.name ?? ''"
                :tick-line="false"
                :domain-line="false"
                :grid-line="false"
                :num-ticks="data.length"
            />
            <VisAxis
                v-if="showLabels && !horizontal"
                type="x"
                :tick-format="(i: number) => data[i]?.name ?? ''"
                :tick-line="false"
                :domain-line="false"
                :grid-line="false"
                :num-ticks="data.length"
            />
            <VisTooltip :triggers="tooltipTriggers()" :horizontal-shift="16" :vertical-shift="16" />
        </VisXYContainer>
    </div>
</template>

<style scoped>
.bar-chart {
    width: 100%;
    --vis-axis-tick-label-color: var(--color-text-tertiary);
    --vis-axis-tick-label-font-size: 11px;
    --vis-tooltip-background-color: transparent;
    --vis-tooltip-border-color: transparent;
    --vis-tooltip-shadow-color: transparent;
}

.bar-chart :deep(.unovis-xy-container) {
    overflow: visible;
}

.bar-chart :deep(.unovis-tooltip) {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}

.bar-chart :deep(rect) {
    transition: opacity 0.15s ease;
}

.bar-chart :deep(rect:hover) {
    opacity: 0.85;
}

/* Light mode */
:global(.light) .bar-chart {
    --vis-axis-tick-label-color: rgba(0, 0, 0, 0.6);
}
</style>
