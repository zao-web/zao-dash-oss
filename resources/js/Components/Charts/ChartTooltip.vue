<script setup lang="ts">
defineProps<{
    title?: string;
    data: {
        name: string;
        color: string;
        value: any;
    }[];
}>();
</script>

<template>
    <div class="chart-tooltip-wrapper">
        <div class="chart-tooltip-border"></div>
        <div class="chart-tooltip-inner">
            <div v-if="title" class="chart-tooltip-title">{{ title }}</div>
            <div class="chart-tooltip-content">
                <div v-for="(item, key) in data" :key="key" class="chart-tooltip-row">
                    <div class="chart-tooltip-label">
                        <span class="chart-tooltip-dot" :style="{ background: item.color }"></span>
                        <span>{{ item.name }}</span>
                    </div>
                    <span class="chart-tooltip-value">{{ item.value }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Unscoped so styles apply when rendered via createApp */
.chart-tooltip-wrapper {
    position: relative;
    padding: 1px;
    border-radius: 10px;
    min-width: 140px;
    font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif;
    box-shadow:
        0 0 0 1px rgba(0, 0, 0, 0.3),
        0 4px 8px rgba(0, 0, 0, 0.4),
        0 12px 24px rgba(0, 0, 0, 0.5),
        0 0 40px rgba(99, 102, 241, 0.15),
        0 0 60px rgba(139, 92, 246, 0.1);
}

.chart-tooltip-border {
    position: absolute;
    inset: -1px;
    border-radius: 10px;
    background: linear-gradient(
        90deg,
        #6366f1,
        #8b5cf6,
        #a855f7,
        #ec4899,
        #f43f5e,
        #6366f1,
        #8b5cf6,
        #a855f7
    );
    background-size: 200% 100%;
    animation: tooltip-flow 4s ease-in-out infinite;
    opacity: 0.8;
}

.chart-tooltip-border::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 10px;
    background: inherit;
    background-size: inherit;
    animation: inherit;
    filter: blur(8px);
    opacity: 0.5;
}

@keyframes tooltip-flow {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}

.chart-tooltip-inner {
    position: relative;
    background: rgba(17, 17, 21, 0.95);
    border-radius: 9px;
    backdrop-filter: blur(8px);
    overflow: hidden;
}

.chart-tooltip-title {
    padding: 10px 12px 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
}

.chart-tooltip-content {
    padding: 8px 12px 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.chart-tooltip-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.chart-tooltip-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.6);
}

.chart-tooltip-dot {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    flex-shrink: 0;
}

.chart-tooltip-value {
    font-size: 12px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: rgba(255, 255, 255, 0.95);
}

/* Override Unovis tooltip container */
.unovis-tooltip {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
}

.unovis-tooltip-container {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
}

/* Light mode styles */
.light .chart-tooltip-wrapper {
    box-shadow:
        0 0 0 1px rgba(0, 0, 0, 0.08),
        0 4px 8px rgba(0, 0, 0, 0.08),
        0 12px 24px rgba(0, 0, 0, 0.12),
        0 0 32px rgba(139, 92, 246, 0.12);
}

.light .chart-tooltip-border {
    background: linear-gradient(
        90deg,
        #818cf8,
        #a78bfa,
        #c084fc,
        #e879f9,
        #f472b6,
        #818cf8,
        #a78bfa,
        #c084fc
    );
    opacity: 0.6;
}

.light .chart-tooltip-border::before {
    filter: blur(6px);
    opacity: 0.3;
}

.light .chart-tooltip-inner {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
}

.light .chart-tooltip-title {
    border-bottom-color: rgba(0, 0, 0, 0.08);
    color: rgba(0, 0, 0, 0.9);
}

.light .chart-tooltip-label {
    color: rgba(0, 0, 0, 0.5);
}

.light .chart-tooltip-value {
    color: rgba(0, 0, 0, 0.9);
}
</style>
