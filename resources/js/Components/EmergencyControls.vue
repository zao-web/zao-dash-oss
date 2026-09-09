<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

interface Agent {
  id: string
  name: string
  status: 'active' | 'disabled' | 'circuit_broken'
  circuit_broken_at?: string
}

interface Props {
  globalKillSwitchActive?: boolean
  agents?: Agent[]
  pendingApprovals?: number
  activeRuns?: number
}

const props = withDefaults(defineProps<Props>(), {
  globalKillSwitchActive: false,
  agents: () => [],
  pendingApprovals: 0,
  activeRuns: 0,
})

const emit = defineEmits<{
  (e: 'kill-switch-toggled', active: boolean): void
  (e: 'agent-toggled', agentId: string, active: boolean): void
  (e: 'circuit-reset', agentId: string): void
}>()

const isExpanded = ref(false)
const confirmingGlobalKill = ref(false)
const confirmingAgentKill = ref<string | null>(null)

const hasIssues = computed(() => {
  return props.globalKillSwitchActive ||
    props.agents.some(a => a.status === 'circuit_broken') ||
    props.activeRuns > 10
})

const statusColor = computed(() => {
  if (props.globalKillSwitchActive) return 'bg-red-500'
  if (props.agents.some(a => a.status === 'circuit_broken')) return 'bg-yellow-500'
  return 'bg-green-500'
})

function toggleGlobalKillSwitch() {
  if (props.globalKillSwitchActive) {
    // Deactivating - do it immediately
    router.post('/api/emergency/kill-switch', { active: false }, {
      preserveScroll: true,
      onSuccess: () => {
        emit('kill-switch-toggled', false)
        confirmingGlobalKill.value = false
      },
    })
  } else {
    // Activating - require confirmation
    confirmingGlobalKill.value = true
  }
}

function confirmGlobalKill() {
  router.post('/api/emergency/kill-switch', { active: true }, {
    preserveScroll: true,
    onSuccess: () => {
      emit('kill-switch-toggled', true)
      confirmingGlobalKill.value = false
    },
  })
}

function toggleAgent(agentId: string) {
  const agent = props.agents.find(a => a.id === agentId)
  if (!agent) return

  if (agent.status === 'active') {
    confirmingAgentKill.value = agentId
  } else {
    router.post(`/api/emergency/agents/${agentId}/toggle`, { active: true }, {
      preserveScroll: true,
      onSuccess: () => emit('agent-toggled', agentId, true),
    })
  }
}

function confirmAgentKill(agentId: string) {
  router.post(`/api/emergency/agents/${agentId}/toggle`, { active: false }, {
    preserveScroll: true,
    onSuccess: () => {
      emit('agent-toggled', agentId, false)
      confirmingAgentKill.value = null
    },
  })
}

function resetCircuitBreaker(agentId: string) {
  router.post(`/api/emergency/agents/${agentId}/reset-circuit`, {}, {
    preserveScroll: true,
    onSuccess: () => emit('circuit-reset', agentId),
  })
}

function cancelAllPending() {
  router.post('/api/emergency/cancel-pending', {}, {
    preserveScroll: true,
  })
}
</script>

<template>
  <div class="relative">
    <!-- Collapsed indicator button -->
    <button
      @click="isExpanded = !isExpanded"
      class="flex items-center gap-2 px-3 py-2 rounded-lg border transition-all"
      :class="[
        hasIssues
          ? 'border-red-500/50 bg-red-500/10 hover:bg-red-500/20'
          : 'border-zinc-700 bg-zinc-800/50 hover:bg-zinc-800',
      ]"
    >
      <span
        class="w-2 h-2 rounded-full animate-pulse"
        :class="statusColor"
      />
      <span class="text-sm font-medium text-zinc-200">
        Emergency Controls
      </span>
      <svg
        class="w-4 h-4 text-zinc-400 transition-transform"
        :class="{ 'rotate-180': isExpanded }"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <!-- Expanded panel -->
    <Transition
      enter-active-class="transition ease-out duration-200"
      enter-from-class="opacity-0 translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition ease-in duration-150"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 translate-y-1"
    >
      <div
        v-if="isExpanded"
        class="absolute right-0 mt-2 w-96 bg-zinc-900 border border-zinc-700 rounded-xl shadow-xl z-50"
      >
        <div class="p-4 border-b border-zinc-700">
          <h3 class="text-lg font-semibold text-white flex items-center gap-2">
            <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            Emergency Controls
          </h3>
          <p class="text-sm text-zinc-400 mt-1">
            Manage agent execution and safety controls
          </p>
        </div>

        <!-- Global Kill Switch -->
        <div class="p-4 border-b border-zinc-700">
          <div class="flex items-center justify-between">
            <div>
              <p class="font-medium text-white">Global Kill Switch</p>
              <p class="text-xs text-zinc-400">Stop all agent execution immediately</p>
            </div>
            <button
              @click="toggleGlobalKillSwitch"
              class="px-4 py-2 rounded-lg font-medium text-sm transition-colors"
              :class="[
                globalKillSwitchActive
                  ? 'bg-green-600 hover:bg-green-700 text-white'
                  : 'bg-red-600 hover:bg-red-700 text-white',
              ]"
            >
              {{ globalKillSwitchActive ? 'Restore' : 'KILL ALL' }}
            </button>
          </div>

          <!-- Confirmation dialog -->
          <div
            v-if="confirmingGlobalKill"
            class="mt-3 p-3 bg-red-500/10 border border-red-500/30 rounded-lg"
          >
            <p class="text-sm text-red-400 mb-2">
              This will immediately stop all running agents and cancel pending approvals.
            </p>
            <div class="flex gap-2">
              <button
                @click="confirmGlobalKill"
                class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-sm rounded font-medium"
              >
                Confirm Kill
              </button>
              <button
                @click="confirmingGlobalKill = false"
                class="px-3 py-1.5 bg-zinc-700 hover:bg-zinc-600 text-white text-sm rounded"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>

        <!-- Quick stats -->
        <div class="p-4 border-b border-zinc-700 grid grid-cols-2 gap-4">
          <div class="text-center">
            <p class="text-2xl font-bold text-white">{{ activeRuns }}</p>
            <p class="text-xs text-zinc-400">Active Runs</p>
          </div>
          <div class="text-center">
            <p class="text-2xl font-bold text-white">{{ pendingApprovals }}</p>
            <p class="text-xs text-zinc-400">Pending Approvals</p>
          </div>
        </div>

        <!-- Cancel pending button -->
        <div v-if="pendingApprovals > 0" class="p-4 border-b border-zinc-700">
          <button
            @click="cancelAllPending"
            class="w-full px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg text-sm font-medium"
          >
            Cancel All Pending Approvals
          </button>
        </div>

        <!-- Agent list -->
        <div class="p-4 max-h-64 overflow-y-auto">
          <p class="text-xs font-medium text-zinc-500 uppercase tracking-wider mb-2">
            Agent Status
          </p>
          <div class="space-y-2">
            <div
              v-for="agent in agents"
              :key="agent.id"
              class="flex items-center justify-between p-2 rounded-lg bg-zinc-800/50"
            >
              <div class="flex items-center gap-2">
                <span
                  class="w-2 h-2 rounded-full"
                  :class="{
                    'bg-green-500': agent.status === 'active',
                    'bg-red-500': agent.status === 'disabled',
                    'bg-yellow-500': agent.status === 'circuit_broken',
                  }"
                />
                <span class="text-sm text-zinc-200">{{ agent.name }}</span>
                <span
                  v-if="agent.status === 'circuit_broken'"
                  class="text-xs text-yellow-500"
                >
                  (Circuit Broken)
                </span>
              </div>
              <div class="flex items-center gap-1">
                <button
                  v-if="agent.status === 'circuit_broken'"
                  @click="resetCircuitBreaker(agent.id)"
                  class="px-2 py-1 text-xs bg-yellow-600 hover:bg-yellow-700 text-white rounded"
                >
                  Reset
                </button>
                <button
                  @click="toggleAgent(agent.id)"
                  class="px-2 py-1 text-xs rounded transition-colors"
                  :class="[
                    agent.status === 'active'
                      ? 'bg-red-600/20 text-red-400 hover:bg-red-600/30'
                      : 'bg-green-600/20 text-green-400 hover:bg-green-600/30',
                  ]"
                >
                  {{ agent.status === 'active' ? 'Disable' : 'Enable' }}
                </button>
              </div>
            </div>
          </div>

          <!-- Agent kill confirmation -->
          <div
            v-if="confirmingAgentKill"
            class="mt-3 p-3 bg-red-500/10 border border-red-500/30 rounded-lg"
          >
            <p class="text-sm text-red-400 mb-2">
              Disable this agent? It will stop any running tasks.
            </p>
            <div class="flex gap-2">
              <button
                @click="confirmAgentKill(confirmingAgentKill)"
                class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-sm rounded font-medium"
              >
                Confirm
              </button>
              <button
                @click="confirmingAgentKill = null"
                class="px-3 py-1.5 bg-zinc-700 hover:bg-zinc-600 text-white text-sm rounded"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="p-3 bg-zinc-800/50 rounded-b-xl">
          <p class="text-xs text-zinc-500 text-center">
            Kill switch backed by Redis for instant effect
          </p>
        </div>
      </div>
    </Transition>
  </div>
</template>
