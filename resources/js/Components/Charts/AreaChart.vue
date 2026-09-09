<script setup lang="ts">
import { computed, ref } from 'vue';
import { VisXYContainer, VisArea, VisLine, VisAxis, VisCrosshair, VisTooltip } from '@unovis/vue';
import { CurveType } from '@unovis/ts';
import ChartTooltip from './ChartTooltip.vue';
import { createApp } from 'vue';

interface DataPoint {
    index: number;
    value: number;
}

const props = withDefaults(defineProps<{
    data: number[];
    categories?: string[];
    color?: string;
    height?: number;
    showGrid?: boolean;
    showAxis?: boolean;
}>(), {
    color: 'hsl(var(--vis-primary-color))',
    height: 64,
    showGrid: false,
    showAxis: false,
});

const chartData = computed<DataPoint[]>(() =>
    props.data.map((value, index) => ({ index, value }))
);

const x = (d: DataPoint) => d.index;
const y = (d: DataPoint) => d.value;

const areaColor = computed(() => props.color);

function formatValue(val: number): string {
    if (val >= 1000000) return `$${(val / 1000000).toFixed(1)}M`;
    if (val >= 1000) return `$${(val / 1000).toFixed(0)}k`;
    return val?.toFixed(0) ?? '0';
}

const wm = new WeakMap();
function tooltipTemplate(d: DataPoint) {
    if (wm.has(d)) return wm.get(d);

    const div = document.createElement('div');
    const data = [{ name: 'Value', color: props.color, value: formatValue(d.value) }];
    const title = props.categories?.[d.index] ?? `Point ${d.index + 1}`;
    createApp(ChartTooltip, { title, data }).mount(div);
    wm.set(d, div.innerHTML);
    return div.innerHTML;
}
</script>

<template>
    <div class="area-chart" :style="{ '--chart-color': areaColor, height: `${height}px` }">
        <VisXYContainer :data="chartData" :height="height" :margin="{ top: 4, right: 4, bottom: showAxis ? 24 : 4, left: showAxis ? 32 : 4 }">
            <VisArea
                :x="x"
                :y="y"
                :color="areaColor"
                :curve-type="CurveType.MonotoneX"
                :opacity="0.15"
            />
            <VisLine
                :x="x"
                :y="y"
                :color="areaColor"
                :curve-type="CurveType.MonotoneX"
                :line-width="2"
            />
            <VisAxis
                v-if="showAxis"
                type="x"
                :tick-format="(i: number) => categories?.[i] ?? ''"
                :grid-line="showGrid"
                :tick-line="false"
                :domain-line="false"
            />
            <VisAxis
                v-if="showAxis"
                type="y"
                :tick-format="(v: number) => formatValue(v)"
                :grid-line="showGrid"
                :tick-line="false"
                :domain-line="false"
            />
            <VisCrosshair :template="tooltipTemplate" />
            <VisTooltip :horizontal-shift="16" :vertical-shift="16" />
        </VisXYContainer>
    </div>
</template>

<style scoped>
.area-chart {
    width: 100%;
    --vis-primary-color: 263 70% 50%;
    --vis-crosshair-line-stroke-color: var(--color-border-default);
    --vis-crosshair-circle-stroke-color: var(--chart-color);
    --vis-axis-tick-label-color: var(--color-text-quaternary);
    --vis-axis-grid-color: var(--color-border-subtle);
    --vis-axis-tick-label-font-size: 10px;
    --vis-tooltip-background-color: transparent;
    --vis-tooltip-border-color: transparent;
    --vis-tooltip-text-color: inherit;
    --vis-tooltip-shadow-color: transparent;
}

.area-chart :deep(.unovis-xy-container) {
    overflow: visible;
}

.area-chart :deep(.unovis-tooltip) {
    pointer-events: none;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}

/* Light mode */
:global(.light) .area-chart {
    --vis-crosshair-line-stroke-color: rgba(0, 0, 0, 0.15);
    --vis-axis-tick-label-color: rgba(0, 0, 0, 0.5);
    --vis-axis-grid-color: rgba(0, 0, 0, 0.08);
}
</style>
