<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface ScheduleEntry {
    offset_days: number;
    enabled: boolean;
}

interface ClientOverride {
    client_id: number;
    client_name: string;
    enabled: boolean;
    entry_count: number;
}

const props = defineProps<{
    schedule: { enabled: boolean; entries: ScheduleEntry[] };
    clientOverrides: ClientOverride[];
    limits: { min_offset: number; max_offset: number };
}>();

const form = useForm({
    enabled: props.schedule.enabled,
    entries: [...props.schedule.entries],
});

const addRow = () => {
    // Suggest the next sensible offset: max + 7, clamped
    const max = form.entries.reduce((m, e) => Math.max(m, e.offset_days), 0);
    const next = Math.min(props.limits.max_offset, max + 7);
    form.entries.push({ offset_days: next, enabled: true });
};

const removeRow = (idx: number) => {
    form.entries.splice(idx, 1);
};

const submit = () => {
    form.put('/invoices/settings/reminders', { preserveScroll: true });
};

const labelFor = (offset: number): string => {
    if (offset < 0) return `${Math.abs(offset)} day${offset === -1 ? '' : 's'} before due`;
    if (offset === 0) return 'On due date';
    return `${offset} day${offset === 1 ? '' : 's'} after due`;
};

const totalEnabled = computed(() => form.entries.filter(e => e.enabled).length);
</script>

<template>
    <AppLayout title="Invoice Reminder Settings">
        <div class="settings-page">
            <div class="page-header">
                <div>
                    <div class="header-row">
                        <Link href="/invoices" class="back-link">
                            <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </Link>
                        <h1 class="page-title">Invoice Reminder Settings</h1>
                    </div>
                    <p class="page-subtitle">
                        Control when automated payment reminders go out. Global defaults apply to every client unless that client has its own override.
                    </p>
                </div>
            </div>

            <form @submit.prevent="submit" class="card">
                <div class="card-header">
                    <h3>Global Schedule</h3>
                    <span class="muted">{{ totalEnabled }} of {{ form.entries.length }} reminders enabled</span>
                </div>

                <div class="settings-form">
                    <FormCheckbox
                        v-model="form.enabled"
                        label="Send automated reminders (master switch)"
                    />
                    <p class="form-hint">Uncheck to disable all reminders globally. Existing pending reminders will be cancelled.</p>

                    <div class="schedule-table" :class="{ disabled: !form.enabled }">
                        <div class="schedule-head">
                            <span>Send</span>
                            <span>Offset (days from due date)</span>
                            <span>Label</span>
                            <span></span>
                        </div>
                        <div v-for="(entry, idx) in form.entries" :key="idx" class="schedule-row">
                            <FormCheckbox v-model="entry.enabled" />
                            <input
                                v-model.number="entry.offset_days"
                                type="number"
                                :min="limits.min_offset"
                                :max="limits.max_offset"
                                step="1"
                                class="form-input"
                            />
                            <span class="muted">{{ labelFor(entry.offset_days) }}</span>
                            <button type="button" class="btn-icon" @click="removeRow(idx)" title="Remove">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div v-if="form.entries.length === 0" class="empty-state">
                            No reminders configured. Click "Add reminder" to create one.
                        </div>
                    </div>

                    <button type="button" class="btn btn-secondary" @click="addRow">+ Add reminder</button>

                    <div v-if="form.errors.entries" class="form-error">{{ form.errors.entries }}</div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            {{ form.processing ? 'Saving…' : 'Save schedule' }}
                        </button>
                        <span class="form-hint">
                            Saving will reschedule pending reminders for all unpaid invoices that use the global schedule.
                        </span>
                    </div>
                </div>
            </form>

            <div class="card">
                <div class="card-header">
                    <h3>Per-Client Overrides</h3>
                </div>
                <div v-if="clientOverrides.length === 0" class="empty-state">
                    No client overrides yet. Open a client's page to set a custom schedule for them.
                </div>
                <table v-else class="data-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Status</th>
                            <th>Reminders</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in clientOverrides" :key="o.client_id">
                            <td>
                                <Link :href="`/clients/${o.client_id}`">{{ o.client_name }}</Link>
                            </td>
                            <td>
                                <span :class="['badge', o.enabled ? 'badge-success' : 'badge-secondary']">
                                    {{ o.enabled ? 'Active' : 'Disabled' }}
                                </span>
                            </td>
                            <td>{{ o.entry_count }} configured</td>
                            <td>
                                <Link :href="`/clients/${o.client_id}#billing`" class="link-action">Manage →</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.settings-page {
    max-width: 960px;
    margin: 0 auto;
    padding: 1.5rem;
}
.page-header { margin-bottom: 1.5rem; }
.header-row { display: flex; align-items: center; gap: 0.75rem; }
.back-link { color: var(--text-secondary, #6b7280); }
.back-icon { width: 20px; height: 20px; }
.page-title { font-size: 1.5rem; font-weight: 600; margin: 0; }
.page-subtitle { color: var(--text-secondary, #6b7280); margin-top: 0.5rem; max-width: 64ch; }
.card {
    background: var(--surface, white);
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
    overflow: hidden;
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border, #e5e7eb);
}
.card-header h3 { margin: 0; font-size: 1rem; font-weight: 600; }
.settings-form { padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
.schedule-table { border: 1px solid var(--border, #e5e7eb); border-radius: 0.375rem; }
.schedule-table.disabled { opacity: 0.5; pointer-events: none; }
.schedule-head, .schedule-row {
    display: grid;
    grid-template-columns: 60px 140px 1fr 40px;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    align-items: center;
}
.schedule-head {
    background: var(--surface-muted, #f9fafb);
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text-secondary, #6b7280);
    border-bottom: 1px solid var(--border, #e5e7eb);
}
.schedule-row + .schedule-row { border-top: 1px solid var(--border, #e5e7eb); }
.form-input {
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 0.375rem;
    padding: 0.375rem 0.625rem;
    font-size: 0.875rem;
    width: 100%;
}
.btn-icon {
    background: none;
    border: none;
    color: var(--text-secondary, #6b7280);
    cursor: pointer;
    padding: 0.25rem;
}
.btn-icon:hover { color: var(--danger, #dc2626); }
.muted { color: var(--text-secondary, #6b7280); font-size: 0.875rem; }
.empty-state { padding: 1.5rem; text-align: center; color: var(--text-secondary, #6b7280); }
.form-actions { display: flex; align-items: center; gap: 1rem; padding-top: 0.5rem; }
.form-hint { color: var(--text-secondary, #6b7280); font-size: 0.8125rem; }
.form-error { color: var(--danger, #dc2626); font-size: 0.875rem; }
.settings-link { margin-left: 0.5rem; }
.link-action { color: var(--primary, #2563eb); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 0.625rem 1rem; text-align: left; border-bottom: 1px solid var(--border, #e5e7eb); }
.badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; }
.badge-success { background: var(--success-bg, #d1fae5); color: var(--success, #065f46); }
.badge-secondary { background: var(--surface-muted, #f3f4f6); color: var(--text-secondary, #6b7280); }
</style>
