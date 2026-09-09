<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import Modal from '@/Components/Modal.vue';

interface Tool {
    id: string;
    name: string;
    description: string;
    category: string;
    requires_approval: boolean;
    risk_level: 'low' | 'medium' | 'high';
}

interface Props {
    modelValue: string[];
    show?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    show: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
    'close': [];
}>();

const tools = ref<Tool[]>([]);
const categories = ref<string[]>([]);
const isLoading = ref(true);
const searchQuery = ref('');
const selectedCategory = ref<string | null>(null);

// Track selected tools locally
const selectedTools = ref<Set<string>>(new Set(props.modelValue));

// Sync with parent
watch(() => props.modelValue, (val) => {
    selectedTools.value = new Set(val);
}, { immediate: true });

// Load tools from API
onMounted(async () => {
    try {
        const response = await fetch('/api/tools?grouped=true', {
            headers: { 'Accept': 'application/json' }
        });
        if (response.ok) {
            const data = await response.json();
            tools.value = Object.values(data.tools).flat() as Tool[];
            categories.value = data.categories || [];
        }
    } catch (e) {
        console.error('Failed to load tools:', e);
    } finally {
        isLoading.value = false;
    }
});

// Filter tools based on search and category
const filteredTools = computed(() => {
    let result = tools.value;

    if (selectedCategory.value) {
        result = result.filter(t => t.category === selectedCategory.value);
    }

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        result = result.filter(t =>
            t.name.toLowerCase().includes(query) ||
            t.description.toLowerCase().includes(query) ||
            t.id.toLowerCase().includes(query)
        );
    }

    return result;
});

// Group tools by category for display
const groupedTools = computed(() => {
    const groups: Record<string, Tool[]> = {};
    for (const tool of filteredTools.value) {
        if (!groups[tool.category]) {
            groups[tool.category] = [];
        }
        groups[tool.category].push(tool);
    }
    // Sort categories alphabetically
    return Object.fromEntries(
        Object.entries(groups).sort(([a], [b]) => a.localeCompare(b))
    );
});

// Count tools per category
const categoryCount = computed(() => {
    const counts: Record<string, number> = {};
    for (const tool of tools.value) {
        counts[tool.category] = (counts[tool.category] || 0) + 1;
    }
    return counts;
});

const toggleTool = (toolId: string) => {
    const newSet = new Set(selectedTools.value);
    if (newSet.has(toolId)) {
        newSet.delete(toolId);
    } else {
        newSet.add(toolId);
    }
    selectedTools.value = newSet;
    emit('update:modelValue', Array.from(newSet));
};

const selectVisible = () => {
    const newSet = new Set([...selectedTools.value, ...filteredTools.value.map(t => t.id)]);
    selectedTools.value = newSet;
    emit('update:modelValue', Array.from(newSet));
};

const clearAll = () => {
    selectedTools.value = new Set();
    emit('update:modelValue', []);
};

const selectCategory = (category: string) => {
    const categoryTools = tools.value.filter(t => t.category === category);
    const newSet = new Set([...selectedTools.value, ...categoryTools.map(t => t.id)]);
    selectedTools.value = newSet;
    emit('update:modelValue', Array.from(newSet));
};

const riskDot = (level: string) => {
    switch (level) {
        case 'low': return 'bg-emerald-500';
        case 'medium': return 'bg-amber-500';
        case 'high': return 'bg-red-500';
        default: return 'bg-gray-400';
    }
};

const formatCategory = (cat: string) => {
    return cat.charAt(0).toUpperCase() + cat.slice(1).replace(/-/g, ' ');
};
</script>

<template>
    <Modal :show="show" max-width="3xl" @close="emit('close')">
        <div class="flex flex-col h-[70vh] max-h-[700px]">
            <!-- Header -->
            <div class="flex-shrink-0 px-6 pt-6 pb-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Select Tools</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ selectedTools.size }} of {{ tools.length }} selected
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            @click="selectVisible"
                            class="text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            Add visible
                        </button>
                        <button
                            @click="clearAll"
                            class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        >
                            Clear
                        </button>
                    </div>
                </div>

                <!-- Search & Category Filter -->
                <div class="flex gap-3">
                    <div class="relative flex-1">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search tools..."
                            class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:text-white"
                        />
                    </div>
                    <select
                        v-model="selectedCategory"
                        class="px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white dark:bg-gray-800 dark:border-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500"
                    >
                        <option :value="null">All categories</option>
                        <option v-for="cat in categories" :key="cat" :value="cat">
                            {{ formatCategory(cat) }} ({{ categoryCount[cat] }})
                        </option>
                    </select>
                </div>
            </div>

            <!-- Tools List -->
            <div class="flex-1 overflow-y-auto">
                <div v-if="isLoading" class="flex items-center justify-center h-full text-gray-500">
                    <svg class="animate-spin h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading...
                </div>

                <div v-else-if="filteredTools.length === 0" class="flex items-center justify-center h-full text-gray-500">
                    No tools match your search
                </div>

                <div v-else class="divide-y divide-gray-100 dark:divide-gray-800">
                    <!-- Grouped by category -->
                    <template v-for="(categoryTools, category) in groupedTools" :key="category">
                        <div class="sticky top-0 z-10 px-6 py-2 bg-gray-50 dark:bg-gray-900/80 backdrop-blur-sm border-b border-gray-100 dark:border-gray-800">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                    {{ formatCategory(category as string) }}
                                </span>
                                <button
                                    @click="selectCategory(category as string)"
                                    class="text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400"
                                >
                                    Select all
                                </button>
                            </div>
                        </div>

                        <div
                            v-for="tool in categoryTools"
                            :key="tool.id"
                            @click="toggleTool(tool.id)"
                            :class="[
                                'flex items-center gap-4 px-6 py-3 cursor-pointer transition-colors',
                                selectedTools.has(tool.id)
                                    ? 'bg-blue-50 dark:bg-blue-900/20'
                                    : 'hover:bg-gray-50 dark:hover:bg-gray-800/50'
                            ]"
                        >
                            <!-- Checkbox -->
                            <div class="flex-shrink-0">
                                <div
                                    :class="[
                                        'w-5 h-5 rounded border-2 flex items-center justify-center transition-colors',
                                        selectedTools.has(tool.id)
                                            ? 'bg-blue-600 border-blue-600'
                                            : 'border-gray-300 dark:border-gray-600'
                                    ]"
                                >
                                    <svg v-if="selectedTools.has(tool.id)" class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>

                            <!-- Tool Info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-900 dark:text-white">
                                        {{ tool.name }}
                                    </span>
                                    <!-- Risk indicator dot -->
                                    <span
                                        :class="['w-2 h-2 rounded-full', riskDot(tool.risk_level)]"
                                        :title="`${tool.risk_level} risk`"
                                    />
                                    <!-- Approval lock icon -->
                                    <svg
                                        v-if="tool.requires_approval"
                                        class="w-3.5 h-3.5 text-purple-500"
                                        fill="currentColor"
                                        viewBox="0 0 20 20"
                                        title="Requires approval"
                                    >
                                        <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-1">
                                    {{ tool.description }}
                                </p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex-shrink-0 px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Low risk
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span> Medium
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-red-500"></span> High
                        </span>
                        <span class="flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 text-purple-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                            Approval
                        </span>
                    </div>
                    <button
                        @click="emit('close')"
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
                    >
                        Done
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>
