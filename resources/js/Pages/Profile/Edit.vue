<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInput from '@/Components/FormInput.vue';
import Button from '@/Components/Button.vue';
import { useForm, usePage, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    created_at: string;
}

interface Token {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string;
}

interface Props {
    user: User;
    tokens: Token[];
}

const props = defineProps<Props>();
const page = usePage();

const form = useForm({
    name: props.user.name,
    email: props.user.email,
});

const tokenForm = useForm({
    name: 'video-recorder',
});

const showCreateToken = ref(false);
const newlyCreatedToken = ref<string | null>(null);
const tokenCopied = ref(false);

const submit = () => {
    form.put('/profile', {
        preserveScroll: true,
    });
};

const createToken = () => {
    tokenForm.post('/profile/tokens', {
        preserveScroll: true,
        onSuccess: () => {
            showCreateToken.value = false;
            tokenForm.reset();
            const token = page.props.flash?.newToken as string | undefined;
            if (token) {
                newlyCreatedToken.value = token;
            }
        },
    });
};

const revokeToken = (tokenId: number) => {
    if (confirm('Are you sure? This token will stop working immediately.')) {
        router.delete(`/profile/tokens/${tokenId}`, {
            preserveScroll: true,
        });
    }
};

const copyToken = async () => {
    if (newlyCreatedToken.value) {
        await navigator.clipboard.writeText(newlyCreatedToken.value);
        tokenCopied.value = true;
        setTimeout(() => tokenCopied.value = false, 2000);
    }
};

const dismissNewToken = () => {
    newlyCreatedToken.value = null;
    tokenCopied.value = false;
};

const initials = computed(() => {
    const names = props.user.name.split(' ');
    if (names.length >= 2) {
        return (names[0][0] + names[names.length - 1][0]).toUpperCase();
    }
    return props.user.name.substring(0, 2).toUpperCase();
});

const roleDisplay = computed(() => {
    const roleMap: Record<string, string> = {
        'owner': 'Owner',
        'admin': 'Administrator',
        'staff': 'Staff Member',
    };
    return roleMap[props.user.role] || props.user.role;
});

const flashSuccess = computed(() => page.props.flash?.success);
</script>

<template>
    <AppLayout title="Profile">
        <div class="max-w-4xl">
            <!-- Success Message -->
            <div
                v-if="flashSuccess"
                class="mb-6 p-4 rounded-lg flex items-center gap-3"
                style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.2);"
            >
                <svg class="h-5 w-5" style="color: var(--color-status-green)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span style="color: var(--color-status-green); font-size: 0.875rem; font-weight: 500">
                    {{ flashSuccess }}
                </span>
            </div>

            <!-- Profile Header Card -->
            <div class="surface-elevated p-6 mb-6">
                <div class="flex items-start gap-6">
                    <div class="avatar avatar-xl avatar-gradient flex-shrink-0">
                        <span>{{ initials }}</span>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-xl font-semibold mb-1" style="color: var(--color-text-primary)">
                            {{ user.name }}
                        </h2>
                        <p class="text-sm mb-4" style="color: var(--color-text-tertiary)">
                            {{ user.email }}
                        </p>
                        <div class="flex gap-6">
                            <div>
                                <div class="text-caption mb-1">Role</div>
                                <div class="badge badge-info">{{ roleDisplay }}</div>
                            </div>
                            <div>
                                <div class="text-caption mb-1">Member Since</div>
                                <div class="text-sm font-medium" style="color: var(--color-text-secondary)">
                                    {{ user.created_at }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="surface-elevated p-6">
                <h3 class="text-lg font-semibold mb-6" style="color: var(--color-text-primary)">
                    Edit Profile
                </h3>

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <FormInput
                            v-model="form.name"
                            label="Full Name"
                            placeholder="Enter your name"
                            required
                            :error="form.errors.name"
                        />

                        <FormInput
                            v-model="form.email"
                            label="Email Address"
                            type="email"
                            placeholder="your@email.com"
                            required
                            :error="form.errors.email"
                        />
                    </div>

                    <div class="flex items-center gap-3 pt-4" style="border-top: 1px solid var(--color-border-subtle)">
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="form.processing || !form.isDirty"
                            :loading="form.processing"
                        >
                            Save Changes
                        </Button>
                        <Button
                            v-if="form.isDirty"
                            type="button"
                            variant="ghost"
                            @click="form.reset()"
                            :disabled="form.processing"
                        >
                            Cancel
                        </Button>
                        <span
                            v-if="!form.isDirty && !flashSuccess"
                            class="text-sm"
                            style="color: var(--color-text-tertiary)"
                        >
                            No changes to save
                        </span>
                    </div>
                </form>
            </div>

            <!-- Account Info (Read-only) -->
            <div class="surface-elevated p-6 mt-6">
                <h3 class="text-lg font-semibold mb-4" style="color: var(--color-text-primary)">
                    Account Information
                </h3>
                <div class="space-y-4">
                    <div class="flex items-center justify-between py-3" style="border-bottom: 1px solid var(--color-border-subtle)">
                        <div>
                            <div class="text-sm font-medium mb-1" style="color: var(--color-text-secondary)">
                                Account ID
                            </div>
                            <div class="text-mono text-sm" style="color: var(--color-text-tertiary)">
                                #{{ user.id }}
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between py-3" style="border-bottom: 1px solid var(--color-border-subtle)">
                        <div>
                            <div class="text-sm font-medium mb-1" style="color: var(--color-text-secondary)">
                                Account Role
                            </div>
                            <div class="text-sm" style="color: var(--color-text-tertiary)">
                                {{ roleDisplay }}
                            </div>
                        </div>
                        <div class="text-caption" style="color: var(--color-text-quaternary)">
                            Contact admin to change
                        </div>
                    </div>
                </div>
            </div>

            <!-- API Tokens -->
            <div class="surface-elevated p-6 mt-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-semibold" style="color: var(--color-text-primary)">
                            API Tokens
                        </h3>
                        <p class="text-sm mt-1" style="color: var(--color-text-tertiary)">
                            Used by the Chrome extension for video recording
                        </p>
                    </div>
                    <Button
                        v-if="!showCreateToken"
                        variant="secondary"
                        size="sm"
                        @click="showCreateToken = true"
                    >
                        Generate Token
                    </Button>
                </div>

                <!-- New Token Created Alert -->
                <div
                    v-if="newlyCreatedToken"
                    class="mb-4 p-4 rounded-lg"
                    style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3);"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-2">
                                <svg class="h-5 w-5" style="color: var(--color-status-green)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <span class="text-sm font-medium" style="color: var(--color-status-green)">Token created!</span>
                            </div>
                            <p class="text-xs mb-2" style="color: var(--color-text-tertiary)">
                                Copy this token now. You won't be able to see it again.
                            </p>
                            <div class="flex items-center gap-2">
                                <code class="text-xs px-2 py-1 rounded flex-1 break-all" style="background: var(--color-bg-tertiary); color: var(--color-text-secondary)">
                                    {{ newlyCreatedToken }}
                                </code>
                                <Button variant="ghost" size="sm" @click="copyToken">
                                    {{ tokenCopied ? 'Copied!' : 'Copy' }}
                                </Button>
                            </div>
                        </div>
                        <button
                            @click="dismissNewToken"
                            class="p-1 rounded hover:bg-white/10"
                            style="color: var(--color-text-tertiary)"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Create Token Form -->
                <div
                    v-if="showCreateToken"
                    class="mb-4 p-4 rounded-lg"
                    style="background: var(--color-bg-tertiary); border: 1px solid var(--color-border-subtle);"
                >
                    <form @submit.prevent="createToken" class="space-y-4">
                        <FormInput
                            v-model="tokenForm.name"
                            label="Token Name"
                            placeholder="e.g., video-recorder"
                            :error="tokenForm.errors.name"
                        />
                        <div class="flex items-center gap-2">
                            <Button
                                type="submit"
                                variant="primary"
                                size="sm"
                                :disabled="tokenForm.processing"
                                :loading="tokenForm.processing"
                            >
                                Create Token
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="showCreateToken = false; tokenForm.reset()"
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                </div>

                <!-- Token List -->
                <div v-if="tokens.length > 0" class="space-y-2">
                    <div
                        v-for="token in tokens"
                        :key="token.id"
                        class="flex items-center justify-between py-3 px-4 rounded-lg"
                        style="background: var(--color-bg-tertiary);"
                    >
                        <div>
                            <div class="text-sm font-medium" style="color: var(--color-text-secondary)">
                                {{ token.name }}
                            </div>
                            <div class="text-xs" style="color: var(--color-text-quaternary)">
                                Created {{ token.created_at }}
                                <span v-if="token.last_used_at"> · Last used {{ token.last_used_at }}</span>
                                <span v-else> · Never used</span>
                            </div>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="revokeToken(token.id)"
                            style="color: var(--color-status-red)"
                        >
                            Revoke
                        </Button>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-else-if="!showCreateToken"
                    class="text-center py-8"
                    style="color: var(--color-text-tertiary)"
                >
                    <svg class="h-12 w-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <p class="text-sm">No API tokens yet</p>
                    <p class="text-xs mt-1">Generate a token to use with the Chrome extension</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 6px;
    text-transform: capitalize;
}

.badge-info {
    background: rgba(139, 92, 246, 0.15);
    color: var(--color-accent);
    border: 1px solid rgba(139, 92, 246, 0.2);
}
</style>
