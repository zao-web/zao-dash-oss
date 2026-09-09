<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import InteractionModal from '@/Components/InteractionModal.vue';
import SowImportModal from '@/Components/SowImportModal.vue';
import { useGlobalCommandPalette } from '@/composables/useGlobalCommandPalette';
import { useAccountSync } from '@/composables/useAccountSync';
import { useVideoEvents } from '@/composables/useVideoEvents';
import { useInteractionRealtime } from '@/composables/useInteractionRealtime';
import { useToast } from '@/composables/useToast';

// Animated Icons
import {
    CommandIcon,
    BriefcaseIcon,
    CalculatorIcon,
    CashIcon,
    ChartIcon,
    SparklesIcon,
    CogIcon,
    FolderIcon,
    CheckIcon,
    HomeIcon,
    VideoIcon,
    UsersIcon,
    FunnelIcon,
    TeamIcon,
    WalletIcon,
    DocumentIcon,
    CurrencyIcon,
    CpuIcon,
    PiggyBankIcon,
    ShieldIcon,
    StarIcon,
    TrendingIcon,
    LockIcon,
    BookIcon,
    PlugIcon,
    ChevronIcon,
    SearchIcon,
    SunIcon,
    MoonIcon,
    MenuIcon,
    UserIcon,
    LogoutIcon
} from '@/Components/Icons';

interface NavItem {
    name: string;
    href: string;
    icon: string;
}

interface NavGroup {
    name: string;
    icon: string;
    items: NavItem[];
    defaultOpen?: boolean;
}

// Top-level item (always visible)
const primaryNav: NavItem = { name: 'Command', href: '/', icon: 'command' };

// Grouped navigation
const navGroups: NavGroup[] = [
    {
        name: 'Work',
        icon: 'briefcase',
        defaultOpen: true,
        items: [
            { name: 'Projects', href: '/projects', icon: 'folder' },
            { name: 'Tasks', href: '/tasks', icon: 'check' },
            { name: 'Videos', href: '/videos', icon: 'video' },
        ],
    },
    {
        name: 'Business',
        icon: 'chart',
        defaultOpen: true,
        items: [
            { name: 'Clients', href: '/clients', icon: 'users' },
            { name: 'Retainers', href: '/retainers', icon: 'trending' },
            { name: 'Pipeline', href: '/leads', icon: 'funnel' },
            { name: 'RFP Pipeline', href: '/rfp', icon: 'document' },
            { name: 'SEO', href: '/seo/dashboard', icon: 'chart' },
            { name: 'Invoices', href: '/invoices', icon: 'currency' },
            { name: 'Reports', href: '/reports', icon: 'chart' },
        ],
    },
    {
        name: 'AI Tools',
        icon: 'sparkles',
        defaultOpen: true,
        items: [
            { name: 'Website Builder', href: '/website-builder', icon: 'sparkles' },
            { name: 'Agents', href: '/agents', icon: 'cpu' },
            { name: 'Approvals', href: '/approvals', icon: 'shield' },
            { name: 'Bookmarks', href: '/bookmarks', icon: 'book' },
        ],
    },
    {
        name: 'System',
        icon: 'cog',
        defaultOpen: false,
        items: [
            { name: 'Vault', href: '/vault', icon: 'lock' },
            { name: 'Docs', href: '/docs', icon: 'book' },
            { name: 'Integrations', href: '/settings/integrations', icon: 'plug' },
        ],
    },
];

// Track which groups are expanded
const expandedGroups = ref<Record<string, boolean>>({});

// Track hovered nav items for icon animations
const hoveredNavItem = ref<string | null>(null);
const hoveredGroupHeader = ref<string | null>(null);

// Initialize expanded state from localStorage or defaults
const initExpandedGroups = () => {
    const saved = localStorage.getItem('nav-expanded-groups');
    if (saved) {
        expandedGroups.value = JSON.parse(saved);
    } else {
        navGroups.forEach(g => {
            expandedGroups.value[g.name] = g.defaultOpen ?? false;
        });
    }
};

const toggleGroup = (groupName: string) => {
    expandedGroups.value[groupName] = !expandedGroups.value[groupName];
    localStorage.setItem('nav-expanded-groups', JSON.stringify(expandedGroups.value));
};

// Check if any item in a group is active
const isGroupActive = (group: NavGroup) => {
    return group.items.some(item => isActive(item.href));
};

const props = defineProps<{
    title?: string;
    runningAgents?: number;
    totalAgents?: number;
    pendingApprovals?: number;
}>();

// Agent capacity percentage (what % of agents are currently running)
const agentCapacityPercent = computed(() => {
    if (!props.totalAgents || props.totalAgents === 0) return 0;
    return Math.round(((props.runningAgents ?? 0) / props.totalAgents) * 100);
});

const page = usePage();
const currentPath = computed(() => page.url);
const user = computed(() => page.props.auth?.user);

// Demo mode: owner-only, anonymizes all data for safe screen-sharing
const isOwner = computed(() => user.value?.role === 'owner');
const demoMode = computed(() => Boolean((page.props as any).demoMode));
const toggleDemoMode = () => {
    showUserDropdown.value = false;
    router.post('/demo-mode/toggle', {}, { preserveScroll: true });
};

// Bridge Inertia flash messages to toast notifications
const { success: toastSuccess, error: toastError } = useToast();

watch(() => page.props.flash, (flash: any) => {
    if (flash?.success) {
        toastSuccess('Success', flash.success);
    }
    if (flash?.error) {
        toastError('Error', flash.error);
    }
}, { deep: true });

// Listen for real-time events (sync progress, video processing, views)
if (page.props.auth?.user?.id) {
    useAccountSync(page.props.auth.user.id);
    useVideoEvents(page.props.auth.user.id);
}

// Listen for agent interaction requests (interactive Claude sessions)
const interactions = page.props.auth?.user?.id
    ? useInteractionRealtime(page.props.auth.user.id)
    : null;

const isActive = (href: string) => {
    const path = currentPath.value.split('?')[0];
    if (href === '/') return path === '/';
    if (path === href) return true;
    // Prefix match, but only if no sibling nav item is a more specific match
    if (!path.startsWith(href + '/')) return false;
    const allHrefs = navGroups.flatMap(g => g.items.map(i => i.href));
    return !allHrefs.some(h => h !== href && h.length > href.length && path.startsWith(h));
};

// Get user initials
const userInitials = computed(() => {
    if (!user.value) return 'U';
    const names = user.value.name.split(' ');
    if (names.length >= 2) {
        return names[0][0] + names[1][0];
    }
    return user.value.name.substring(0, 2);
});

// User dropdown
const showUserDropdown = ref(false);
const userDropdownRef = ref<HTMLElement | null>(null);

// Mobile sidebar
const mobileMenuOpen = ref(false);

const closeMobileMenu = () => {
    mobileMenuOpen.value = false;
};

const logout = () => {
    router.post('/logout');
};

const handleClickOutside = (event: MouseEvent) => {
    if (userDropdownRef.value && !userDropdownRef.value.contains(event.target as Node)) {
        showUserDropdown.value = false;
    }
};

// Theme toggle
const isDark = ref(true);

const toggleTheme = () => {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('light', !isDark.value);
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light');
};

// Command palette
const { openPalette } = useGlobalCommandPalette();

// SOW Import Modal
const showSowImport = ref(false);
const handleSowImportEvent = () => { showSowImport.value = true; };

onMounted(() => {
    const saved = localStorage.getItem('theme');
    // Default to dark if no preference saved
    isDark.value = saved !== 'light';
    document.documentElement.classList.toggle('light', !isDark.value);

    // Initialize nav group expansion state
    initExpandedGroups();

    // Click outside handler for dropdown
    document.addEventListener('click', handleClickOutside);

    // Listen for SOW import command palette event
    window.addEventListener('command-palette:import-sow', handleSowImportEvent);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    window.removeEventListener('command-palette:import-sow', handleSowImportEvent);
});
</script>

<template>
    <!-- Command Palette (Global) -->
    <CommandPalette />

    <div class="min-h-screen" style="background: var(--color-bg-primary)">
        <!-- Mobile sidebar overlay -->
        <div
            v-if="mobileMenuOpen"
            class="fixed inset-0 z-40 bg-black/50 md:hidden"
            @click="closeMobileMenu"
        ></div>

        <!-- Sidebar -->
        <aside
            :class="[
                'sidebar fixed inset-y-0 left-0 z-50 w-60 transition-transform duration-300 ease-in-out',
                mobileMenuOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'
            ]"
        >
            <!-- Logo -->
            <div class="flex h-14 items-center gap-3 px-4" style="border-bottom: 1px solid var(--color-border-subtle)">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg" style="background: var(--color-accent)">
                    <span class="text-sm font-bold" style="color: #100f0d">Z</span>
                </div>
                <div>
                    <span class="text-sm font-semibold" style="color: var(--color-text-primary); font-family: var(--font-display)">Zao Dash</span>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="sidebar-nav">
                <!-- Primary Nav (Command) -->
                <Link
                    :href="primaryNav.href"
                    :class="['sidebar-item', isActive(primaryNav.href) ? 'active' : '']"
                    @click="closeMobileMenu"
                    @mouseenter="hoveredNavItem = 'command'"
                    @mouseleave="hoveredNavItem = null"
                >
                    <CommandIcon :size="16" :hovered="hoveredNavItem === 'command'" />
                    {{ primaryNav.name }}
                </Link>

                <!-- Grouped Navigation -->
                <div v-for="group in navGroups" :key="group.name" class="nav-group">
                    <button
                        :class="['nav-group-header', { active: isGroupActive(group) }]"
                        @click="toggleGroup(group.name)"
                        @mouseenter="hoveredGroupHeader = group.name"
                        @mouseleave="hoveredGroupHeader = null"
                    >
                        <BriefcaseIcon v-if="group.icon === 'briefcase'" :size="16" :hovered="hoveredGroupHeader === group.name" />
                        <ChartIcon v-else-if="group.icon === 'chart'" :size="16" :hovered="hoveredGroupHeader === group.name" />
                        <SparklesIcon v-else-if="group.icon === 'sparkles'" :size="16" :hovered="hoveredGroupHeader === group.name" />
                        <CurrencyIcon v-else-if="group.icon === 'currency'" :size="16" :hovered="hoveredGroupHeader === group.name" />
                        <CogIcon v-else-if="group.icon === 'cog'" :size="16" :hovered="hoveredGroupHeader === group.name" />
                        <span class="nav-group-name">{{ group.name }}</span>
                        <ChevronIcon :size="14" :expanded="expandedGroups[group.name]" :hovered="hoveredGroupHeader === group.name" class="nav-group-chevron-icon" />
                    </button>

                    <div v-show="expandedGroups[group.name]" class="nav-group-items">
                        <Link
                            v-for="item in group.items"
                            :key="item.name"
                            :href="item.href"
                            :class="['sidebar-item sidebar-item-nested', isActive(item.href) ? 'active' : '']"
                            @click="closeMobileMenu"
                            @mouseenter="hoveredNavItem = item.href"
                            @mouseleave="hoveredNavItem = null"
                        >
                            <FolderIcon v-if="item.icon === 'folder'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <CheckIcon v-else-if="item.icon === 'check'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <VideoIcon v-else-if="item.icon === 'video'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <UsersIcon v-else-if="item.icon === 'users'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <FunnelIcon v-else-if="item.icon === 'funnel'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <TeamIcon v-else-if="item.icon === 'team'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <WalletIcon v-else-if="item.icon === 'wallet'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <DocumentIcon v-else-if="item.icon === 'document'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <CurrencyIcon v-else-if="item.icon === 'currency'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <ChartIcon v-else-if="item.icon === 'chart'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <SparklesIcon v-else-if="item.icon === 'sparkles'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <CpuIcon v-else-if="item.icon === 'cpu'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <ShieldIcon v-else-if="item.icon === 'shield'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <LockIcon v-else-if="item.icon === 'lock'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <BookIcon v-else-if="item.icon === 'book'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <PlugIcon v-else-if="item.icon === 'plug'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <StarIcon v-else-if="item.icon === 'star'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <CalculatorIcon v-else-if="item.icon === 'calculator'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <TrendingIcon v-else-if="item.icon === 'trending'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <CashIcon v-else-if="item.icon === 'cash'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <HomeIcon v-else-if="item.icon === 'home'" :size="14" :hovered="hoveredNavItem === item.href" />
                            <PiggyBankIcon v-else-if="item.icon === 'piggybank'" :size="14" :hovered="hoveredNavItem === item.href" />
                            {{ item.name }}
                            <span
                                v-if="item.icon === 'shield' && (pendingApprovals ?? 0) > 0"
                                class="count-badge ml-auto"
                            >
                                {{ pendingApprovals }}
                            </span>
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- System Status — minimal single line -->
            <div class="absolute bottom-0 left-0 right-0 px-4 py-3" style="border-top: 1px solid var(--color-border-subtle)">
                <div class="flex items-center gap-3">
                    <div class="status-dot" :class="(runningAgents ?? 0) > 0 ? 'running' : 'completed'"></div>
                    <span class="text-caption flex-1">
                        <span v-if="(runningAgents ?? 0) > 0" class="text-mono" style="color: var(--color-status-green)">{{ runningAgents }}</span>
                        <span v-if="(runningAgents ?? 0) > 0"> active</span>
                        <span v-else style="color: var(--color-text-quaternary)">Idle</span>
                    </span>
                    <span v-if="(pendingApprovals ?? 0) > 0" class="count-badge">
                        {{ pendingApprovals }}
                    </span>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="pl-0 md:pl-60">
            <!-- Top Bar -->
            <header class="sticky top-0 z-40 flex h-14 items-center justify-between gap-4 px-3 md:px-6" style="border-bottom: 1px solid var(--color-border-subtle); background: var(--color-bg-primary)">
                <div class="flex items-center gap-2 md:gap-3">
                    <!-- Mobile menu toggle -->
                    <button
                        @click="mobileMenuOpen = !mobileMenuOpen"
                        class="md:hidden p-1.5 -ml-1 rounded-lg hover:bg-[var(--color-bg-tertiary)] transition-colors flex-shrink-0"
                        aria-label="Toggle menu"
                    >
                        <MenuIcon :size="20" :is-open="mobileMenuOpen" style="color: var(--color-text-secondary)" />
                    </button>
                    <h1 v-if="title" class="text-sm md:text-xl font-semibold truncate max-w-[180px] md:max-w-none" style="color: var(--color-text-primary); font-family: var(--font-display)">{{ title }}</h1>
                </div>

                <div class="flex items-center gap-1.5 md:gap-3">
                    <!-- Command Palette Trigger - icon only on mobile -->
                    <button
                        @click="openPalette"
                        class="command-palette-trigger hidden md:flex"
                        title="Command Palette (⌘K)"
                    >
                        <SearchIcon :size="16" style="color: var(--color-text-quaternary)" />
                        <span>Search...</span>
                        <kbd class="command-kbd">⌘K</kbd>
                    </button>
                    <!-- Mobile search button -->
                    <button
                        @click="openPalette"
                        class="md:hidden p-1.5 rounded-lg hover:bg-[var(--color-bg-tertiary)] transition-colors"
                        title="Search"
                    >
                        <SearchIcon :size="18" style="color: var(--color-text-tertiary)" />
                    </button>

                    <!-- SOW Import Quick Action -->
                    <button
                        @click="showSowImport = true"
                        class="sow-import-btn hidden md:flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors"
                        title="Import Statement of Work"
                    >
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        Import SOW
                    </button>

                    <!-- Notification Bell -->
                    <NotificationBell />

                    <!-- Theme Toggle (hidden on small mobile) -->
                    <button
                        @click="toggleTheme"
                        class="theme-toggle hidden sm:flex"
                        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    >
                        <SunIcon v-if="isDark" :size="20" />
                        <MoonIcon v-else :size="20" />
                    </button>

                    <!-- User Avatar & Dropdown -->
                    <button
                        v-if="demoMode"
                        @click="toggleDemoMode"
                        class="badge badge-sm badge-accent flex items-center gap-1.5"
                        title="Demo mode is on — all data is anonymized. Click to turn off."
                        style="cursor: pointer;"
                    >
                        <span class="inline-block w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                        DEMO
                    </button>

                    <div class="relative" ref="userDropdownRef">
                        <button
                            @click="showUserDropdown = !showUserDropdown"
                            class="avatar avatar-md avatar-gradient cursor-pointer hover:opacity-80 transition-opacity"
                        >
                            <span>{{ userInitials }}</span>
                        </button>

                        <!-- Dropdown Menu -->
                        <div
                            v-if="showUserDropdown"
                            class="absolute right-0 mt-2 w-56 surface-elevated border border-[var(--color-border-default)] shadow-lg"
                            style="border-radius: 8px; z-index: 50;"
                        >
                            <div class="p-3 border-b border-[var(--color-border-subtle)]">
                                <div class="text-sm font-medium" style="color: var(--color-text-primary)">{{ user?.name }}</div>
                                <div class="text-xs mt-0.5" style="color: var(--color-text-tertiary)">{{ user?.email }}</div>
                                <div class="mt-1">
                                    <span class="badge badge-sm badge-accent">{{ user?.role }}</span>
                                </div>
                            </div>

                            <div class="p-2">
                                <button
                                    v-if="isOwner"
                                    @click="toggleDemoMode"
                                    class="w-full flex items-center justify-between gap-2 px-3 py-2 text-sm rounded-md hover:bg-[var(--color-bg-tertiary)] transition-colors text-left"
                                    style="color: var(--color-text-secondary)"
                                >
                                    <span class="flex items-center gap-2">
                                        <ShieldIcon :size="16" />
                                        Demo Mode
                                    </span>
                                    <span
                                        class="badge badge-sm"
                                        :class="demoMode ? 'badge-accent' : ''"
                                        style="opacity: 0.9"
                                    >{{ demoMode ? 'On' : 'Off' }}</span>
                                </button>
                                <Link
                                    href="/profile"
                                    @click="showUserDropdown = false"
                                    class="w-full flex items-center gap-2 px-3 py-2 text-sm rounded-md hover:bg-[var(--color-bg-tertiary)] transition-colors"
                                    style="color: var(--color-text-secondary)"
                                >
                                    <UserIcon :size="16" />
                                    Profile Settings
                                </Link>
                                <button
                                    @click="logout"
                                    class="w-full flex items-center gap-2 px-3 py-2 text-sm rounded-md hover:bg-[var(--color-bg-tertiary)] transition-colors text-left"
                                    style="color: var(--color-text-secondary)"
                                >
                                    <LogoutIcon :size="16" />
                                    Sign out
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="p-3 md:p-6">
                <slot />
            </div>
        </main>
    </div>

    <!-- Toast Notifications -->
    <ToastContainer />

    <!-- Agent Interaction Modal (for interactive Claude sessions) -->
    <InteractionModal
        v-if="interactions"
        :show="interactions.hasPendingInteraction.value"
        :interaction="interactions.currentInteraction.value"
        :state="interactions.state.value"
        :remaining-seconds="interactions.remainingSeconds.value"
        :error="interactions.error.value"
        @submit="interactions.submitResponse"
        @dismiss="interactions.dismissInteraction"
        @retry="interactions.retry"
    />

    <!-- SOW Import Modal -->
    <SowImportModal
        :show="showSowImport"
        @close="showSowImport = false"
    />
</template>

<style scoped>
.command-palette-trigger {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    color: var(--color-text-tertiary);
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.15s ease;
    min-width: 200px;
}

.command-palette-trigger:hover {
    background: var(--color-bg-tertiary);
    border-color: var(--color-border-default);
    color: var(--color-text-secondary);
}

.command-palette-trigger span {
    flex: 1;
    text-align: left;
}

.command-kbd {
    padding: 0.125rem 0.375rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 4px;
    font-size: 0.65rem;
    font-family: inherit;
    color: var(--color-text-quaternary);
}

/* Grouped Navigation Styles */
.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.75rem;
    overflow-y: auto;
    max-height: calc(100vh - 200px);
}

.nav-group {
    margin-top: 0.5rem;
}

.nav-group:first-of-type {
    margin-top: 0.75rem;
}

.nav-group-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.5rem 0.75rem;
    background: transparent;
    border: none;
    border-radius: 6px;
    color: var(--color-text-quaternary);
    font-family: var(--font-display);
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    cursor: pointer;
    transition: all 0.15s;
}

.nav-group-header:hover {
    color: var(--color-text-secondary);
    background: var(--color-bg-tertiary);
}

.nav-group-header.active {
    color: var(--color-text-secondary);
}

.nav-group-header .icon {
    width: 1rem;
    height: 1rem;
    flex-shrink: 0;
}

.nav-group-name {
    flex: 1;
    text-align: left;
}

.nav-group-chevron-icon {
    opacity: 0.5;
    flex-shrink: 0;
}

.nav-group-items {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    margin-top: 0.25rem;
    padding-left: 0.25rem;
}

.sidebar-item-nested {
    padding-left: 2rem !important;
    font-size: 0.8125rem !important;
}

.sidebar-item-nested .icon {
    width: 0.875rem !important;
    height: 0.875rem !important;
}

.badge-accent {
    background: rgba(196, 154, 75, 0.15);
    color: var(--color-accent);
}

.sow-import-btn {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    color: var(--color-text-tertiary);
}

.sow-import-btn:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
}
</style>
