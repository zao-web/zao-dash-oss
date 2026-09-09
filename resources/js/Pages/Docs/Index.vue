<script setup lang="ts">
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import MarkdownRenderer from '@/Components/MarkdownRenderer.vue';

interface DocItem {
    slug: string;
    title: string;
    description: string;
    category: string | null;
    sections: number;
    reading_time: number;
    updated_at: string;
}

interface TocItem {
    level: number;
    text: string;
    anchor: string;
}

interface DocContent {
    markdown: string;
    toc: TocItem[];
}

interface SearchMatch {
    line: number;
    context: string;
}

interface SearchResult {
    slug: string;
    title: string;
    category: string | null;
    matches: SearchMatch[];
}

const props = defineProps<{
    docs: DocItem[];
    currentDoc: DocItem | null;
    content: DocContent | null;
}>();

// Search state
const searchQuery = ref('');
const searchResults = ref<SearchResult[]>([]);
const isSearching = ref(false);
const showSearch = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);

// Sidebar state
const sidebarCollapsed = ref(false);
const activeSection = ref('');

// Group docs by category
const docsByCategory = computed(() => {
    const groups: Record<string, DocItem[]> = {};

    props.docs.forEach(doc => {
        const category = doc.category || 'General';
        if (!groups[category]) {
            groups[category] = [];
        }
        groups[category].push(doc);
    });

    return groups;
});

// Category order - General first, then alphabetical
const categoryOrder = computed(() => {
    const categories = Object.keys(docsByCategory.value);
    return categories.sort((a, b) => {
        if (a === 'General') return -1;
        if (b === 'General') return 1;
        return a.localeCompare(b);
    });
});

// Search with debounce
let searchTimeout: ReturnType<typeof setTimeout>;
watch(searchQuery, (query) => {
    clearTimeout(searchTimeout);
    if (query.length < 2) {
        searchResults.value = [];
        return;
    }

    isSearching.value = true;
    searchTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`/docs/search?q=${encodeURIComponent(query)}`);
            const data = await response.json();
            searchResults.value = data.results;
        } catch (error) {
            console.error('Search failed:', error);
        } finally {
            isSearching.value = false;
        }
    }, 300);
});

// Navigate to doc
const navigateToDoc = (slug: string) => {
    router.get(`/docs/${slug}`, {}, { preserveScroll: false });
    showSearch.value = false;
    searchQuery.value = '';
};

// Toggle search
const toggleSearch = () => {
    showSearch.value = !showSearch.value;
    if (showSearch.value) {
        nextTick(() => searchInput.value?.focus());
    }
};

// Scroll to section
const scrollToSection = (anchor: string) => {
    const element = document.getElementById(anchor);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        activeSection.value = anchor;
    }
};

// Track scroll position for TOC highlighting
const handleScroll = () => {
    if (!props.content?.toc) return;

    const headings = props.content.toc.map(t => document.getElementById(t.anchor));
    const scrollPos = window.scrollY + 100;

    for (let i = headings.length - 1; i >= 0; i--) {
        const heading = headings[i];
        if (heading && heading.offsetTop <= scrollPos) {
            activeSection.value = props.content.toc[i].anchor;
            return;
        }
    }
};

onMounted(() => {
    window.addEventListener('scroll', handleScroll);
    handleScroll();
});

// Keyboard shortcuts
const handleKeydown = (e: KeyboardEvent) => {
    if (e.key === '/' && !showSearch.value) {
        e.preventDefault();
        toggleSearch();
    }
    if (e.key === 'Escape' && showSearch.value) {
        showSearch.value = false;
        searchQuery.value = '';
    }
};

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
});

// Format category name
const formatCategory = (category: string) => {
    return category
        .replace(/-/g, ' ')
        .replace(/\b\w/g, l => l.toUpperCase());
};

// Get icon for category
const getCategoryIcon = (category: string) => {
    const icons: Record<string, string> = {
        'General': 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
        'features': 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z',
        'agents': 'M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z',
        'api': 'M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5',
        'integrations': 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244',
    };
    return icons[category.toLowerCase()] || icons['General'];
};
</script>

<template>
    <AppLayout title="Documentation">
        <div class="docs-layout">
            <!-- Sidebar -->
            <aside :class="['docs-sidebar', { collapsed: sidebarCollapsed }]">
                <div class="sidebar-header">
                    <div class="sidebar-title">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                        </svg>
                        <span>Docs</span>
                    </div>
                    <button class="search-trigger" @click="toggleSearch" title="Search (/)">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                        <kbd>/</kbd>
                    </button>
                </div>

                <nav class="sidebar-nav">
                    <div v-for="category in categoryOrder" :key="category" class="nav-category">
                        <div class="category-header">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="getCategoryIcon(category)" />
                            </svg>
                            <span>{{ formatCategory(category) }}</span>
                        </div>
                        <ul class="nav-items">
                            <li v-for="doc in docsByCategory[category]" :key="doc.slug">
                                <button
                                    :class="['nav-item', { active: currentDoc?.slug === doc.slug }]"
                                    @click="navigateToDoc(doc.slug)"
                                >
                                    <span class="nav-item-title">{{ doc.title }}</span>
                                    <span class="nav-item-meta">{{ doc.reading_time }}m</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </nav>
            </aside>

            <!-- Main Content -->
            <main class="docs-main">
                <template v-if="currentDoc && content">
                    <!-- Doc Header -->
                    <header class="doc-header">
                        <div class="doc-breadcrumb">
                            <span v-if="currentDoc.category" class="breadcrumb-category">
                                {{ formatCategory(currentDoc.category) }}
                            </span>
                            <span v-else class="breadcrumb-category">General</span>
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                            <span>{{ currentDoc.title }}</span>
                        </div>
                        <div class="doc-meta">
                            <span class="meta-item">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {{ currentDoc.reading_time }} min read
                            </span>
                            <span class="meta-item">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                </svg>
                                Updated {{ currentDoc.updated_at }}
                            </span>
                        </div>
                    </header>

                    <!-- Content with TOC -->
                    <div class="doc-content-wrapper">
                        <article class="doc-content">
                            <MarkdownRenderer :content="content.markdown" />
                        </article>

                        <!-- Table of Contents -->
                        <aside v-if="content.toc.length > 0" class="doc-toc">
                            <div class="toc-header">On this page</div>
                            <nav class="toc-nav">
                                <button
                                    v-for="item in content.toc"
                                    :key="item.anchor"
                                    :class="['toc-item', `toc-level-${item.level}`, { active: activeSection === item.anchor }]"
                                    @click="scrollToSection(item.anchor)"
                                >
                                    {{ item.text }}
                                </button>
                            </nav>
                        </aside>
                    </div>
                </template>

                <!-- Empty State -->
                <template v-else>
                    <div class="docs-empty">
                        <div class="empty-icon">
                            <svg class="h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                            </svg>
                        </div>
                        <h2 class="empty-title">No documentation found</h2>
                        <p class="empty-description">
                            Add markdown files to the <code>/docs</code> folder to get started.
                        </p>
                    </div>
                </template>
            </main>
        </div>

        <!-- Search Modal -->
        <Teleport to="body">
            <Transition name="fade">
                <div v-if="showSearch" class="search-overlay" @click="showSearch = false">
                    <div class="search-modal" @click.stop>
                        <div class="search-input-wrapper">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                            <input
                                ref="searchInput"
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search documentation..."
                                class="search-input"
                            />
                            <div v-if="isSearching" class="search-spinner"></div>
                            <kbd class="search-escape" @click="showSearch = false">esc</kbd>
                        </div>

                        <div v-if="searchResults.length > 0" class="search-results">
                            <div
                                v-for="result in searchResults"
                                :key="result.slug"
                                class="search-result"
                                @click="navigateToDoc(result.slug)"
                            >
                                <div class="result-header">
                                    <span class="result-title">{{ result.title }}</span>
                                    <span v-if="result.category" class="result-category">
                                        {{ formatCategory(result.category) }}
                                    </span>
                                </div>
                                <div class="result-matches">
                                    <div
                                        v-for="(match, idx) in result.matches"
                                        :key="idx"
                                        class="result-match"
                                        v-html="match.context"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="searchQuery.length >= 2 && !isSearching" class="search-empty">
                            No results found for "{{ searchQuery }}"
                        </div>

                        <div v-else class="search-hint">
                            <span>Type to search across all documentation</span>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.docs-layout {
    display: flex;
    min-height: calc(100vh - 64px);
    margin: -1.5rem -1.5rem -1.5rem -1.5rem;
}

/* Sidebar */
.docs-sidebar {
    width: 280px;
    flex-shrink: 0;
    background: var(--color-bg-secondary);
    border-right: 1px solid var(--color-border-subtle);
    overflow-y: auto;
    position: sticky;
    top: 64px;
    height: calc(100vh - 64px);
}

.docs-sidebar.collapsed {
    width: 60px;
}

.sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.sidebar-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.search-trigger {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.625rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 6px;
    color: var(--color-text-tertiary);
    cursor: pointer;
    transition: all 0.15s;
}

.search-trigger:hover {
    background: var(--color-bg-primary);
    color: var(--color-text-secondary);
    border-color: var(--color-border-default);
}

.search-trigger kbd {
    font-family: var(--font-mono);
    font-size: 0.6875rem;
    padding: 0.125rem 0.375rem;
    background: var(--color-bg-primary);
    border-radius: 4px;
    color: var(--color-text-quaternary);
}

.sidebar-nav {
    padding: 1rem 0;
}

.nav-category {
    margin-bottom: 1.5rem;
}

.category-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1.25rem;
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
}

.nav-items {
    list-style: none;
    padding: 0;
    margin: 0;
}

.nav-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 0.5rem 1.25rem 0.5rem 2.5rem;
    text-align: left;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    background: transparent;
    border: none;
    cursor: pointer;
    transition: all 0.15s;
}

.nav-item:hover {
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
}

.nav-item.active {
    color: var(--color-accent);
    background: rgba(99, 102, 241, 0.08);
    border-right: 2px solid var(--color-accent);
}

.nav-item-meta {
    font-size: 0.75rem;
    color: var(--color-text-quaternary);
}

/* Main Content */
.docs-main {
    flex: 1;
    min-width: 0;
    padding: 2rem 3rem;
    max-width: 100%;
}

.doc-header {
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.doc-breadcrumb {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
    margin-bottom: 0.5rem;
}

.breadcrumb-category {
    color: var(--color-accent);
}

.doc-meta {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

.doc-content-wrapper {
    display: flex;
    gap: 3rem;
}

.doc-content {
    flex: 1;
    min-width: 0;
    max-width: 800px;
}

/* Table of Contents */
.doc-toc {
    width: 220px;
    flex-shrink: 0;
    position: sticky;
    top: 96px;
    max-height: calc(100vh - 128px);
    overflow-y: auto;
}

.toc-header {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin-bottom: 0.75rem;
}

.toc-nav {
    display: flex;
    flex-direction: column;
    border-left: 1px solid var(--color-border-subtle);
}

.toc-item {
    display: block;
    width: 100%;
    padding: 0.375rem 1rem;
    text-align: left;
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    background: transparent;
    border: none;
    border-left: 2px solid transparent;
    margin-left: -1px;
    cursor: pointer;
    transition: all 0.15s;
}

.toc-item:hover {
    color: var(--color-text-secondary);
}

.toc-item.active {
    color: var(--color-accent);
    border-left-color: var(--color-accent);
}

.toc-level-3 {
    padding-left: 1.75rem;
    font-size: 0.75rem;
}

/* Empty State */
.docs-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 4rem 2rem;
    color: var(--color-text-tertiary);
}

.empty-icon {
    margin-bottom: 1.5rem;
    opacity: 0.5;
}

.empty-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-secondary);
    margin-bottom: 0.5rem;
}

.empty-description {
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
}

.empty-description code {
    padding: 0.125rem 0.375rem;
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    font-family: var(--font-mono);
    font-size: 0.8125rem;
}

/* Search Modal */
.search-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    z-index: 100;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 15vh;
}

.search-modal {
    width: 100%;
    max-width: 640px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
    overflow: hidden;
}

.search-input-wrapper {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.search-icon {
    width: 1.25rem;
    height: 1.25rem;
    color: var(--color-text-tertiary);
    flex-shrink: 0;
}

.search-input {
    flex: 1;
    background: transparent;
    border: none;
    font-size: 1rem;
    color: var(--color-text-primary);
    outline: none;
}

.search-input::placeholder {
    color: var(--color-text-quaternary);
}

.search-spinner {
    width: 1rem;
    height: 1rem;
    border: 2px solid var(--color-border-subtle);
    border-top-color: var(--color-accent);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.search-escape {
    font-family: var(--font-mono);
    font-size: 0.6875rem;
    padding: 0.25rem 0.5rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 4px;
    color: var(--color-text-quaternary);
    cursor: pointer;
}

.search-results {
    max-height: 400px;
    overflow-y: auto;
}

.search-result {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
    cursor: pointer;
    transition: background 0.15s;
}

.search-result:hover {
    background: var(--color-bg-tertiary);
}

.search-result:last-child {
    border-bottom: none;
}

.result-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.result-title {
    font-weight: 500;
    color: var(--color-text-primary);
}

.result-category {
    font-size: 0.75rem;
    padding: 0.125rem 0.5rem;
    background: var(--color-bg-primary);
    border-radius: 4px;
    color: var(--color-text-tertiary);
}

.result-matches {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.result-match {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
}

.result-match :deep(strong) {
    color: var(--color-accent);
    font-weight: 600;
}

.search-empty,
.search-hint {
    padding: 2rem;
    text-align: center;
    color: var(--color-text-tertiary);
    font-size: 0.875rem;
}

/* Transitions */
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.15s;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

/* Responsive */
@media (max-width: 1280px) {
    .doc-toc {
        display: none;
    }
}

@media (max-width: 768px) {
    .docs-sidebar {
        position: fixed;
        left: 0;
        top: 64px;
        bottom: 0;
        z-index: 50;
        transform: translateX(-100%);
        transition: transform 0.2s;
    }

    .docs-sidebar.open {
        transform: translateX(0);
    }

    .docs-main {
        padding: 1.5rem;
    }
}
</style>
