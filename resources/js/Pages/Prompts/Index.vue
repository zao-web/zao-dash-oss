<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import InlineSelect from '@/Components/InlineSelect.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormCheckbox from '@/Components/FormCheckbox.vue'

interface PromptTemplate {
  id: number
  name: string
  slug: string
  description: string | null
  category: string
  content_preview: string
  variables: string[]
  tags: string[]
  agent: { id: number; name: string } | null
  created_by: string | null
  is_active: boolean
  is_public: boolean
  usage_count: number
  runs_count: number
  metrics: {
    total_runs: number
    success_rate: number
    avg_cost: number
  }
}

interface Props {
  templates: PromptTemplate[]
  categories: Record<string, string>
  agents: { id: number; name: string; slug: string }[]
  filters: {
    category: string | null
    agent_id: number | null
    search: string | null
  }
}

const props = defineProps<Props>()

const showCreateModal = ref(false)
const search = ref(props.filters.search || '')
const selectedCategory = ref(props.filters.category || '')
const selectedAgent = ref(props.filters.agent_id || '')

// Filter options (for toolbar filters)
const categoryFilterOptions = computed(() => [
  { value: '', label: 'All Categories' },
  ...Object.entries(props.categories).map(([key, label]) => ({ value: key, label })),
])

const agentFilterOptions = computed(() => [
  { value: '', label: 'All Agents' },
  ...props.agents.map(a => ({ value: a.id, label: a.name })),
])

// Form options (for create modal)
const categoryFormOptions = computed(() =>
  Object.entries(props.categories).map(([key, label]) => ({ value: key, label }))
)

const agentFormOptions = computed(() => [
  { value: null, label: 'None' },
  ...props.agents.map(a => ({ value: a.id, label: a.name })),
])

// New prompt form
const form = ref({
  name: '',
  description: '',
  category: 'task',
  content: '',
  tags: [] as string[],
  agent_id: null as number | null,
  is_public: false,
})

const tagInput = ref('')

const addTag = () => {
  if (tagInput.value && !form.value.tags.includes(tagInput.value)) {
    form.value.tags.push(tagInput.value)
    tagInput.value = ''
  }
}

const removeTag = (tag: string) => {
  form.value.tags = form.value.tags.filter(t => t !== tag)
}

const applyFilters = () => {
  router.get(route('prompts.index'), {
    category: selectedCategory.value || undefined,
    agent_id: selectedAgent.value || undefined,
    search: search.value || undefined,
  }, { preserveState: true })
}

const createPrompt = () => {
  router.post(route('prompts.store'), form.value, {
    onSuccess: () => {
      showCreateModal.value = false
      form.value = { name: '', description: '', category: 'task', content: '', tags: [], agent_id: null, is_public: false }
    }
  })
}

const categoryColors: Record<string, string> = {
  system: 'bg-purple-500/20 text-purple-400',
  task: 'bg-blue-500/20 text-blue-400',
  analysis: 'bg-cyan-500/20 text-cyan-400',
  content: 'bg-emerald-500/20 text-emerald-400',
  communication: 'bg-amber-500/20 text-amber-400',
  code: 'bg-pink-500/20 text-pink-400',
}
</script>

<template>
  <AppLayout>
    <Head title="Prompt Library" />

    <div class="p-6 space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-white">Prompt Library</h1>
          <p class="text-zinc-400 text-sm mt-1">Reusable prompts with versioning and A/B testing</p>
        </div>
        <button
          @click="showCreateModal = true"
          class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg transition-colors"
        >
          New Prompt
        </button>
      </div>

      <!-- Filters -->
      <div class="flex gap-4">
        <input
          v-model="search"
          @keyup.enter="applyFilters"
          type="text"
          placeholder="Search prompts..."
          class="flex-1 bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-2 text-white placeholder-zinc-500"
        />
        <InlineSelect
          v-model="selectedCategory"
          :options="categoryFilterOptions"
          @update:model-value="applyFilters"
        />
        <InlineSelect
          v-model="selectedAgent"
          :options="agentFilterOptions"
          @update:model-value="applyFilters"
        />
      </div>

      <!-- Templates Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <Link
          v-for="template in templates"
          :key="template.id"
          :href="route('prompts.show', template.id)"
          class="bg-zinc-900 border border-zinc-800 rounded-xl p-4 hover:border-zinc-700 transition-colors group"
        >
          <div class="flex items-start justify-between mb-3">
            <div>
              <h3 class="text-white font-medium group-hover:text-blue-400 transition-colors">{{ template.name }}</h3>
              <span :class="categoryColors[template.category]" class="text-xs px-2 py-0.5 rounded-full mt-1 inline-block">
                {{ categories[template.category] }}
              </span>
            </div>
            <div v-if="template.agent" class="text-xs text-zinc-500">
              {{ template.agent.name }}
            </div>
          </div>

          <p class="text-zinc-400 text-sm line-clamp-2 mb-3">{{ template.content_preview }}</p>

          <!-- Variables -->
          <div v-if="template.variables.length" class="flex flex-wrap gap-1 mb-3">
            <span
              v-for="variable in template.variables.slice(0, 3)"
              :key="variable"
              class="text-xs bg-zinc-800 text-zinc-400 px-2 py-0.5 rounded"
            >
              <span v-text="'{{' + variable + '}}'"></span>
            </span>
            <span v-if="template.variables.length > 3" class="text-xs text-zinc-500">
              +{{ template.variables.length - 3 }} more
            </span>
          </div>

          <!-- Tags -->
          <div v-if="template.tags.length" class="flex flex-wrap gap-1 mb-3">
            <span
              v-for="tag in template.tags.slice(0, 3)"
              :key="tag"
              class="text-xs text-zinc-500"
            >
              #{{ tag }}
            </span>
          </div>

          <!-- Metrics -->
          <div class="flex items-center justify-between text-xs text-zinc-500 pt-3 border-t border-zinc-800">
            <span>{{ template.runs_count }} runs</span>
            <span :class="template.metrics.success_rate >= 90 ? 'text-emerald-400' : template.metrics.success_rate >= 70 ? 'text-amber-400' : 'text-zinc-400'">
              {{ template.metrics.success_rate }}% success
            </span>
            <span>${{ template.metrics.avg_cost.toFixed(4) }} avg</span>
          </div>
        </Link>
      </div>

      <!-- Empty State -->
      <div v-if="!templates.length" class="text-center py-12">
        <div class="text-zinc-500 mb-4">No prompts found</div>
        <button @click="showCreateModal = true" class="text-blue-400 hover:text-blue-300">
          Create your first prompt
        </button>
      </div>
    </div>

    <!-- Create Modal -->
    <div v-if="showCreateModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b border-zinc-800 flex items-center justify-between">
          <h2 class="text-lg font-medium text-white">New Prompt Template</h2>
          <button @click="showCreateModal = false" class="text-zinc-400 hover:text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="p-4 space-y-4">
          <div>
            <label class="block text-sm text-zinc-400 mb-1">Name</label>
            <input
              v-model="form.name"
              type="text"
              class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white"
              placeholder="e.g., Meeting Summary Prompt"
            />
          </div>

          <div>
            <label class="block text-sm text-zinc-400 mb-1">Description</label>
            <input
              v-model="form.description"
              type="text"
              class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white"
              placeholder="Brief description of what this prompt does"
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <FormSelect
              v-model="form.category"
              label="Category"
              :options="categoryFormOptions"
            />
            <FormSelect
              v-model="form.agent_id"
              label="Linked Agent (optional)"
              :options="agentFormOptions"
            />
          </div>

          <div>
            <label class="block text-sm text-zinc-400 mb-1">
              Prompt Content
              <span class="text-zinc-500">(use {'{{variable}}'} for placeholders)</span>
            </label>
            <textarea
              v-model="form.content"
              rows="8"
              class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white font-mono text-sm"
              placeholder="You are an assistant that analyzes {{topic}}..."
            ></textarea>
          </div>

          <div>
            <label class="block text-sm text-zinc-400 mb-1">Tags</label>
            <div class="flex gap-2 mb-2">
              <input
                v-model="tagInput"
                @keyup.enter="addTag"
                type="text"
                class="flex-1 bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white"
                placeholder="Add tag and press Enter"
              />
              <button @click="addTag" class="px-3 py-2 bg-zinc-700 hover:bg-zinc-600 text-white rounded-lg">Add</button>
            </div>
            <div class="flex flex-wrap gap-2">
              <span
                v-for="tag in form.tags"
                :key="tag"
                class="bg-zinc-800 text-zinc-300 px-2 py-1 rounded text-sm flex items-center gap-1"
              >
                {{ tag }}
                <button @click="removeTag(tag)" class="text-zinc-500 hover:text-white">&times;</button>
              </span>
            </div>
          </div>

          <FormCheckbox v-model="form.is_public" label="Share with team" />
        </div>

        <div class="p-4 border-t border-zinc-800 flex justify-end gap-3">
          <button @click="showCreateModal = false" class="px-4 py-2 text-zinc-400 hover:text-white">Cancel</button>
          <button @click="createPrompt" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-lg">Create Prompt</button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
