<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

interface ActivityItem {
    id: string;
    type: 'agent_run' | 'approval' | 'alert' | 'notification' | 'system';
    title: string;
    description?: string;
    icon: string;
    iconColor: string;
    timestamp: string;
    link?: string;
    metadata?: Record<string, unknown>;
}

const props = withDefaults(defineProps<{
    items: ActivityItem[];
    title?: string;
    maxItems?: number;
    showViewAll?: boolean;
}>(), {
    title: 'Recent Activity',
    maxItems: 10,
    showViewAll: true,
});

const displayItems = computed(() => props.items.slice(0, props.maxItems));

const iconMap: Record<string, string> = {
    agent_run: `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
    </svg>`,
    approval: `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>`,
    alert: `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>`,
    notification: `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
    </svg>`,
    system: `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    </svg>`,
};

const colorClasses: Record<string, string> = {
    blue: 'bg-blue-500/10 text-blue-400 ring-blue-500/20',
    green: 'bg-emerald-500/10 text-emerald-400 ring-emerald-500/20',
    yellow: 'bg-amber-500/10 text-amber-400 ring-amber-500/20',
    red: 'bg-red-500/10 text-red-400 ring-red-500/20',
    purple: 'bg-purple-500/10 text-purple-400 ring-purple-500/20',
    gray: 'bg-zinc-500/10 text-zinc-400 ring-zinc-500/20',
};

function getIconHtml(type: string): string {
    return iconMap[type] || iconMap.system;
}

function getColorClass(color: string): string {
    return colorClasses[color] || colorClasses.gray;
}
</script>

<template>
    <div class="bg-zinc-900/50 rounded-xl border border-zinc-800 overflow-hidden">
        <!-- Header -->
        <div class="px-4 py-3 border-b border-zinc-800 flex items-center justify-between">
            <h3 class="text-sm font-medium text-zinc-100">{{ title }}</h3>
            <Link
                v-if="showViewAll && items.length > maxItems"
                href="/activity"
                class="text-xs text-zinc-500 hover:text-zinc-300 transition-colors"
            >
                View all
            </Link>
        </div>

        <!-- Activity List -->
        <div class="divide-y divide-zinc-800/50">
            <template v-if="displayItems.length > 0">
                <component
                    :is="item.link ? Link : 'div'"
                    v-for="item in displayItems"
                    :key="item.id"
                    :href="item.link"
                    class="flex items-start gap-3 px-4 py-3 hover:bg-zinc-800/30 transition-colors"
                    :class="{ 'cursor-pointer': item.link }"
                >
                    <!-- Icon -->
                    <div
                        class="flex-shrink-0 w-8 h-8 rounded-lg ring-1 flex items-center justify-center"
                        :class="getColorClass(item.iconColor)"
                        v-html="getIconHtml(item.type)"
                    />

                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-zinc-200 truncate">{{ item.title }}</p>
                        <p v-if="item.description" class="text-xs text-zinc-500 truncate mt-0.5">
                            {{ item.description }}
                        </p>
                    </div>

                    <!-- Timestamp -->
                    <span class="flex-shrink-0 text-xs text-zinc-600">
                        {{ item.timestamp }}
                    </span>
                </component>
            </template>

            <!-- Empty State -->
            <div v-else class="px-4 py-8 text-center">
                <div class="w-10 h-10 mx-auto rounded-full bg-zinc-800 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <p class="text-sm text-zinc-500">No recent activity</p>
            </div>
        </div>
    </div>
</template>
