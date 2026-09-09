<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';

interface Event {
    id: number;
    title: string;
    description: string | null;
    notes: string | null;
    start_at: string | null;
    end_at: string | null;
    location: string | null;
    meet_link: string | null;
    attendees: Array<{ email?: string; displayName?: string }>;
    is_client_meeting: boolean;
    is_past: boolean;
    client: { id: number; name: string; slug: string } | null;
    parsed_summary: string | null;
    key_decisions: string[];
}

const props = defineProps<{
    event: Event;
}>();

// Date Formatting
const formatDate = (dateString: string | null) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const formatTime = (dateString: string | null) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
    });
};

const duration = computed(() => {
    if (!props.event.start_at || !props.event.end_at) return '';
    const start = new Date(props.event.start_at);
    const end = new Date(props.event.end_at);
    const diffMins = Math.round((end.getTime() - start.getTime()) / 60000);
    const hours = Math.floor(diffMins / 60);
    const mins = diffMins % 60;
    
    if (hours > 0) {
        return `${hours}h ${mins > 0 ? `${mins}m` : ''}`;
    }
    return `${mins}m`;
});
</script>

<template>
    <Head :title="event.title" />

    <AppLayout :title="event.title">
        <div class="max-w-7xl mx-auto py-8">
            <!-- Navigation -->
            <div class="mb-8">
                <Link 
                    href="/calendar" 
                    class="inline-flex items-center gap-2 text-sm text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)] transition-colors duration-200"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    Back to Calendar
                </Link>
            </div>

            <!-- Header Section -->
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6 mb-8">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <Badge v-if="event.is_client_meeting" variant="info">Client Meeting</Badge>
                        <Badge v-if="event.is_past" variant="neutral">Past Event</Badge>
                    </div>
                    <h1 class="text-3xl md:text-4xl font-bold text-[var(--color-text-primary)] tracking-tight">
                        {{ event.title }}
                    </h1>
                </div>

                <div class="flex items-center gap-3 shrink-0">
                    <a v-if="event.meet_link" :href="event.meet_link" target="_blank" rel="noopener noreferrer">
                        <Button variant="ghost">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
                            Join Meeting
                        </Button>
                    </a>
                    
                    <Link :href="`/tasks?from_meeting=${event.id}`">
                        <Button variant="primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            Create Follow-Up Task
                        </Button>
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Main Content Column -->
                <div class="lg:col-span-2 space-y-8">
                    
                    <!-- AI Summary Section -->
                    <Card v-if="event.parsed_summary" padding glow class="border-l-4 border-l-[var(--color-accent)]">
                        <div class="flex items-center gap-2 mb-4">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-[var(--color-accent)]"><path d="M12 2a10 10 0 1 0 10 10H12V2Z"/><path d="M12 2a10 10 0 0 1 10 10h-10V2Z"/><path d="m9 22 3-8 3 8"/><path d="M22 9h-8l3-8"/></svg>
                            <h2 class="text-lg font-semibold text-[var(--color-text-primary)]">AI Summary</h2>
                        </div>
                        <div class="prose prose-invert prose-sm max-w-none text-[var(--color-text-secondary)] leading-relaxed" v-html="event.parsed_summary"></div>
                    </Card>

                    <!-- Key Decisions -->
                    <Card v-if="event.key_decisions && event.key_decisions.length > 0" padding>
                        <div class="flex items-center gap-2 mb-4">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-status-success"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <h2 class="text-lg font-semibold text-[var(--color-text-primary)]">Key Decisions</h2>
                        </div>
                        <ul class="space-y-3">
                            <li v-for="(decision, index) in event.key_decisions" :key="index" class="flex gap-3 text-[var(--color-text-secondary)]">
                                <span class="text-status-success mt-1">•</span>
                                <span>{{ decision }}</span>
                            </li>
                        </ul>
                    </Card>

                    <!-- Notes / Description -->
                    <Card padding>
                        <div class="flex items-center gap-2 mb-4">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-[var(--color-text-tertiary)]"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            <h2 class="text-lg font-semibold text-[var(--color-text-primary)]">Notes</h2>
                        </div>
                        <div v-if="event.notes || event.description" class="whitespace-pre-wrap text-[var(--color-text-secondary)] leading-relaxed font-mono text-sm">
                            {{ event.notes || event.description }}
                        </div>
                        <div v-else class="text-[var(--color-text-tertiary)] italic">
                            No notes available for this event.
                        </div>
                    </Card>
                </div>

                <!-- Sidebar Column -->
                <div class="space-y-6">
                    <!-- Event Details Card -->
                    <Card padding>
                        <h3 class="text-xs font-bold text-[var(--color-text-tertiary)] uppercase tracking-wider mb-4">
                            Event Details
                        </h3>
                        
                        <div class="space-y-5">
                            <!-- Time -->
                            <div class="flex gap-3">
                                <div class="w-8 h-8 rounded-lg bg-[var(--color-bg-tertiary)] flex items-center justify-center shrink-0 text-[var(--color-text-secondary)]">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </div>
                                <div>
                                    <div class="font-medium text-[var(--color-text-primary)]">
                                        {{ formatDate(event.start_at) }}
                                    </div>
                                    <div class="text-sm text-[var(--color-text-tertiary)]">
                                        {{ formatTime(event.start_at) }} - {{ formatTime(event.end_at) }}
                                        <span v-if="duration" class="ml-1 opacity-75">({{ duration }})</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Location -->
                            <div v-if="event.location" class="flex gap-3">
                                <div class="w-8 h-8 rounded-lg bg-[var(--color-bg-tertiary)] flex items-center justify-center shrink-0 text-[var(--color-text-secondary)]">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                </div>
                                <div>
                                    <div class="font-medium text-[var(--color-text-primary)]">Location</div>
                                    <div class="text-sm text-[var(--color-text-tertiary)] break-all">
                                        {{ event.location }}
                                    </div>
                                </div>
                            </div>

                            <!-- Client -->
                            <div v-if="event.client" class="flex gap-3">
                                <div class="w-8 h-8 rounded-lg bg-[var(--color-bg-tertiary)] flex items-center justify-center shrink-0 text-[var(--color-text-secondary)]">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                </div>
                                <div>
                                    <div class="font-medium text-[var(--color-text-primary)]">Client</div>
                                    <Link :href="`/clients/${event.client.slug}`" class="text-sm text-[var(--color-accent)] hover:underline">
                                        {{ event.client.name }}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <!-- Attendees Card -->
                    <Card padding>
                        <h3 class="text-xs font-bold text-[var(--color-text-tertiary)] uppercase tracking-wider mb-4">
                            Attendees ({{ event.attendees.length }})
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <Badge 
                                v-for="(attendee, i) in event.attendees" 
                                :key="i"
                                variant="neutral"
                                class="pl-2 pr-3 py-1"
                            >
                                <div class="flex items-center gap-2">
                                    <div class="w-4 h-4 rounded-full bg-[var(--color-text-tertiary)] opacity-20 flex items-center justify-center text-[8px] font-bold">
                                        {{ (attendee.displayName || attendee.email || '?').charAt(0).toUpperCase() }}
                                    </div>
                                    <span>{{ attendee.displayName || attendee.email }}</span>
                                </div>
                            </Badge>
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
