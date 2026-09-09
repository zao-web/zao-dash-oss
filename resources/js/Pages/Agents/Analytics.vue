<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { VisXYContainer, VisLine, VisArea, VisAxis, VisStackedBar, VisBulletLegend } from '@unovis/vue'

interface SummaryMetrics {
  total_runs: number
  total_cost: number
  successful_runs: number
  failed_runs: number
  success_rate: number
  avg_cost_per_run: number
  active_agents: number
  runs_change: number
  cost_change: number
}

interface CostTrend {
  date: string
  cost: number
  runs: number
}

interface UsageTrend {
  date: string
  completed: number
  failed: number
  cancelled: number
  running: number
}

interface AgentMetric {
  id: number
  name: string
  slug: string
  total_runs: number
  successful: number
  failed: number
  success_rate: number
  total_cost: number
  avg_cost: number
  total_tokens: number
}

interface SourceMetric {
  source: string
  count: number
  cost: number
  success_rate: number
}

interface Props {
  analytics: {
    summary: SummaryMetrics
    cost_trend: CostTrend[]
    usage_trend: UsageTrend[]
    by_agent: AgentMetric[]
    by_source: SourceMetric[]
    top_performers: any[]
    failure_analysis: any
    token_usage: any
  }
  days: number
  agents: { id: number; name: string; slug: string; status: string }[]
}

const props = defineProps<Props>()

const selectedDays = ref(props.days)
const daysOptions = [7, 14, 30, 60, 90]

// Chart data
const costTrendData = computed(() => props.analytics.cost_trend)
const usageTrendData = computed(() => props.analytics.usage_trend)

// Formatters
const formatCurrency = (value: number) => `$${value.toFixed(2)}`
const formatPercent = (value: number) => `${value.toFixed(1)}%`
const formatNumber = (value: number) => value.toLocaleString()

// Chart accessors
const x = (d: CostTrend, i: number) => i
const costY = (d: CostTrend) => d.cost
const runsY = (d: CostTrend) => d.runs

const completedY = (d: UsageTrend) => d.completed
const failedY = (d: UsageTrend) => d.failed

// Source labels
const sourceLabels: Record<string, string> = {
  manual: 'Manual',
  webhook: 'Webhook',
  scheduled: 'Scheduled',
  command_palette: 'Command Palette',
  api: 'API',
  chained: 'Chained',
  chain: 'Chain',
}

const getSourceLabel = (source: string) => sourceLabels[source] || source

// Status colors
const statusColors = {
  completed: '#10b981',
  failed: '#ef4444',
  cancelled: '#f59e0b',
  running: '#3b82f6',
}
</script>

<template>
  <AppLayout>
    <Head title="Agent Analytics" />

    <div class="p-6 space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-semibold text-white">Agent Analytics</h1>
          <p class="text-zinc-400 text-sm mt-1">Cost tracking, usage trends, and performance metrics</p>
        </div>
        <div class="flex items-center gap-3">
          <select
            v-model="selectedDays"
            @change="$inertia.get(route('agents.analytics', { days: selectedDays }))"
            class="bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-sm text-white"
          >
            <option v-for="d in daysOptions" :key="d" :value="d">Last {{ d }} days</option>
          </select>
          <a
            :href="route('api.agents.analytics.export', { days: selectedDays })"
            class="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-white text-sm rounded-lg transition-colors"
          >
            Export CSV
          </a>
        </div>
      </div>

      <!-- Summary Cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">Total Runs</div>
          <div class="text-2xl font-semibold text-white mt-1">{{ formatNumber(analytics.summary.total_runs) }}</div>
          <div :class="analytics.summary.runs_change >= 0 ? 'text-emerald-400' : 'text-red-400'" class="text-xs mt-1">
            {{ analytics.summary.runs_change >= 0 ? '+' : '' }}{{ analytics.summary.runs_change }}% vs prev period
          </div>
        </div>

        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">Total Cost</div>
          <div class="text-2xl font-semibold text-white mt-1">{{ formatCurrency(analytics.summary.total_cost) }}</div>
          <div :class="analytics.summary.cost_change <= 0 ? 'text-emerald-400' : 'text-amber-400'" class="text-xs mt-1">
            {{ analytics.summary.cost_change >= 0 ? '+' : '' }}{{ analytics.summary.cost_change }}% vs prev period
          </div>
        </div>

        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">Success Rate</div>
          <div class="text-2xl font-semibold text-emerald-400 mt-1">{{ formatPercent(analytics.summary.success_rate) }}</div>
          <div class="text-xs text-zinc-500 mt-1">
            {{ analytics.summary.successful_runs }} successful / {{ analytics.summary.failed_runs }} failed
          </div>
        </div>

        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <div class="text-zinc-400 text-sm">Avg Cost/Run</div>
          <div class="text-2xl font-semibold text-white mt-1">${{ analytics.summary.avg_cost_per_run.toFixed(4) }}</div>
          <div class="text-xs text-zinc-500 mt-1">{{ analytics.summary.active_agents }} active agents</div>
        </div>
      </div>

      <!-- Charts Row -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Cost Trend -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <h3 class="text-white font-medium mb-4">Cost Trend</h3>
          <div class="h-48">
            <VisXYContainer :data="costTrendData" :margin="{ top: 10, right: 10, bottom: 30, left: 40 }">
              <VisArea :x="x" :y="costY" color="#3b82f6" :opacity="0.3" />
              <VisLine :x="x" :y="costY" color="#3b82f6" />
              <VisAxis type="x" :tickFormat="(i: number) => costTrendData[i]?.date?.slice(5) || ''" />
              <VisAxis type="y" :tickFormat="(v: number) => `$${v.toFixed(2)}`" />
            </VisXYContainer>
          </div>
        </div>

        <!-- Usage Trend -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <h3 class="text-white font-medium mb-4">Usage by Status</h3>
          <div class="h-48">
            <VisXYContainer :data="usageTrendData" :margin="{ top: 10, right: 10, bottom: 30, left: 40 }">
              <VisStackedBar :x="x" :y="[completedY, failedY]" :color="['#10b981', '#ef4444']" />
              <VisAxis type="x" :tickFormat="(i: number) => usageTrendData[i]?.date?.slice(5) || ''" />
              <VisAxis type="y" />
            </VisXYContainer>
          </div>
          <div class="flex gap-4 mt-2 justify-center">
            <div class="flex items-center gap-2 text-xs">
              <div class="w-3 h-3 rounded bg-emerald-500"></div>
              <span class="text-zinc-400">Completed</span>
            </div>
            <div class="flex items-center gap-2 text-xs">
              <div class="w-3 h-3 rounded bg-red-500"></div>
              <span class="text-zinc-400">Failed</span>
            </div>
          </div>
        </div>
      </div>

      <!-- By Agent Table -->
      <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">
        <div class="p-4 border-b border-zinc-800">
          <h3 class="text-white font-medium">Performance by Agent</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="text-left text-zinc-400 text-sm border-b border-zinc-800">
                <th class="px-4 py-3 font-medium">Agent</th>
                <th class="px-4 py-3 font-medium text-right">Runs</th>
                <th class="px-4 py-3 font-medium text-right">Success Rate</th>
                <th class="px-4 py-3 font-medium text-right">Total Cost</th>
                <th class="px-4 py-3 font-medium text-right">Avg Cost</th>
                <th class="px-4 py-3 font-medium text-right">Tokens</th>
                <th class="px-4 py-3 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="agent in analytics.by_agent"
                :key="agent.id"
                class="border-b border-zinc-800/50 hover:bg-zinc-800/30"
              >
                <td class="px-4 py-3">
                  <Link :href="route('agents.show', agent.slug)" class="text-white hover:text-blue-400">
                    {{ agent.name }}
                  </Link>
                </td>
                <td class="px-4 py-3 text-right text-zinc-300">{{ agent.total_runs }}</td>
                <td class="px-4 py-3 text-right">
                  <span
                    :class="agent.success_rate >= 90 ? 'text-emerald-400' : agent.success_rate >= 70 ? 'text-amber-400' : 'text-red-400'"
                  >
                    {{ agent.success_rate }}%
                  </span>
                </td>
                <td class="px-4 py-3 text-right text-zinc-300">${{ agent.total_cost.toFixed(4) }}</td>
                <td class="px-4 py-3 text-right text-zinc-300">${{ agent.avg_cost.toFixed(4) }}</td>
                <td class="px-4 py-3 text-right text-zinc-400 text-sm">{{ formatNumber(agent.total_tokens) }}</td>
                <td class="px-4 py-3 text-right">
                  <Link
                    :href="route('agents.analytics.agent', agent.id)"
                    class="text-zinc-400 hover:text-white text-sm"
                  >
                    Details →
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Bottom Row -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- By Source -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <h3 class="text-white font-medium mb-4">By Invocation Source</h3>
          <div class="space-y-3">
            <div
              v-for="source in analytics.by_source"
              :key="source.source"
              class="flex items-center justify-between"
            >
              <span class="text-zinc-300">{{ getSourceLabel(source.source) }}</span>
              <div class="flex items-center gap-4">
                <span class="text-zinc-400 text-sm">{{ source.count }} runs</span>
                <span class="text-zinc-300">${{ source.cost.toFixed(2) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Token Usage -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <h3 class="text-white font-medium mb-4">Token Usage</h3>
          <div class="space-y-4">
            <div>
              <div class="flex justify-between text-sm mb-1">
                <span class="text-zinc-400">Input Tokens</span>
                <span class="text-zinc-300">{{ formatNumber(analytics.token_usage.total_input) }}</span>
              </div>
              <div class="h-2 bg-zinc-800 rounded-full overflow-hidden">
                <div
                  class="h-full bg-blue-500"
                  :style="{ width: `${(analytics.token_usage.total_input / (analytics.token_usage.total || 1)) * 100}%` }"
                ></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-1">
                <span class="text-zinc-400">Output Tokens</span>
                <span class="text-zinc-300">{{ formatNumber(analytics.token_usage.total_output) }}</span>
              </div>
              <div class="h-2 bg-zinc-800 rounded-full overflow-hidden">
                <div
                  class="h-full bg-emerald-500"
                  :style="{ width: `${(analytics.token_usage.total_output / (analytics.token_usage.total || 1)) * 100}%` }"
                ></div>
              </div>
            </div>
            <div class="pt-2 border-t border-zinc-800">
              <div class="flex justify-between">
                <span class="text-zinc-400">Total</span>
                <span class="text-white font-medium">{{ formatNumber(analytics.token_usage.total) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Failure Analysis -->
        <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-4">
          <h3 class="text-white font-medium mb-4">Failure Analysis</h3>
          <div class="text-3xl font-semibold text-red-400 mb-4">
            {{ analytics.failure_analysis.total_failures }}
            <span class="text-sm text-zinc-400 font-normal">failures</span>
          </div>
          <div class="space-y-2">
            <div
              v-for="error in analytics.failure_analysis.by_error_type?.slice(0, 5)"
              :key="error.error"
              class="flex items-center justify-between text-sm"
            >
              <span class="text-zinc-300 capitalize">{{ error.error.replace('_', ' ') }}</span>
              <span class="text-zinc-400">{{ error.count }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
