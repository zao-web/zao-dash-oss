<script setup lang="ts">
const props = defineProps<{
    label: string;
    value: string | number;
    suffix?: string;
    change?: string;
    changeType?: 'positive' | 'negative' | 'neutral';
    accent?: string;
}>();

const accentColor = props.accent || 'var(--color-pulse-violet)';
</script>

<template>
    <div
        class="kpi-card group"
        :style="{ '--kpi-accent': accentColor }"
    >
        <div class="relative z-10">
            <p class="kpi-label">{{ label }}</p>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="kpi-value">{{ value }}</span>
                <span v-if="suffix" class="text-lg font-medium text-void-400">{{ suffix }}</span>
                <span
                    v-if="change"
                    :class="[
                        'ml-2 text-sm font-medium',
                        changeType === 'positive' ? 'text-pulse-emerald' :
                        changeType === 'negative' ? 'text-pulse-rose' :
                        'text-void-400'
                    ]"
                >
                    {{ change }}
                </span>
            </div>
        </div>

        <!-- Animated accent line -->
        <div
            class="absolute bottom-0 left-0 right-0 h-0.5 opacity-50"
            :style="{ background: `linear-gradient(90deg, transparent, ${accentColor}, transparent)` }"
        ></div>

        <slot name="icon" />
    </div>
</template>
