<script setup lang="ts">
type Status = 'running' | 'completed' | 'pending' | 'failed' | 'awaiting_approval';

const props = withDefaults(defineProps<{
    status: Status;
    size?: 'sm' | 'md' | 'lg';
}>(), {
    size: 'md',
});

const sizeClasses = {
    sm: 'w-2 h-2',
    md: 'w-3 h-3',
    lg: 'w-4 h-4',
};

const statusClass = {
    running: 'running',
    completed: 'completed',
    pending: 'pending',
    failed: 'failed',
    awaiting_approval: 'pending',
};
</script>

<template>
    <div
        :class="[
            'status-orb',
            statusClass[status] || 'pending',
            sizeClasses[size],
        ]"
    ></div>
</template>
