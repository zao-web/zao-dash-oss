<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'

interface Snapshot {
    monthly_income: number
    monthly_obligations: number
    disposable_income: number
    total_debt: number
    tax_debt: number
    collections_debt: number
    monthly_minimums: number
}

interface RevenueTargets {
    current_effective_rate: number
    additional_retainers_needed: number
}

interface ExpenseOptimization {
    total_monthly_savings: number
}

interface TaxOptimization {
    monthly_tax_savings: number
}

interface NorthStarProjection {
    scenarios: Array<{
        label: string
        monthly_capacity: number
        debt_free_date: string
        north_star_date: string | null
    }>
}

const props = defineProps<{
    snapshot: Snapshot
    revenueTargets: RevenueTargets
    expenseOptimization: ExpenseOptimization
    taxOptimization: TaxOptimization
    northStarProjection: NorthStarProjection
    avgRetainerValue: number
    northStarCost: number
}>()

// Scenario parameters
const params = reactive({
    additional_retainers: 0,
    retainer_value: props.avgRetainerValue || 3500,
    additional_project_revenue: 0,
    expense_reduction: 0,
    additional_expense_cuts: 0,
    extra_debt_payment: 0,
    payoff_method: 'hybrid' as 'avalanche' | 'snowball' | 'hybrid',
    apply_tax_optimization: false,
})

// Saved scenarios for comparison
const savedScenarios = ref<Array<{
    name: string
    params: typeof params
    income: number
    obligations: number
    debtFreeDate: string
    northStarDate: string
    monthsDebtFree: number
    monthsNorthStar: number
}>>([])

// Server response
const detailedPlan = ref<any>(null)
const isCalculating = ref(false)

// Client-side computed projections (instant)
const newMonthlyIncome = computed(() =>
    props.snapshot.monthly_income
    + (params.additional_retainers * params.retainer_value)
    + params.additional_project_revenue
)

const newMonthlyObligations = computed(() =>
    Math.max(0, props.snapshot.monthly_obligations - params.expense_reduction - params.additional_expense_cuts)
)

const newDisposableIncome = computed(() =>
    newMonthlyIncome.value - newMonthlyObligations.value
)

const monthlyDebtCapacity = computed(() =>
    Math.max(0, newDisposableIncome.value
        + params.extra_debt_payment
        + (params.apply_tax_optimization ? (props.taxOptimization?.monthly_tax_savings ?? 0) : 0))
)

const monthsToDebtFree = computed(() =>
    monthlyDebtCapacity.value > 0
        ? Math.ceil(props.snapshot.total_debt / monthlyDebtCapacity.value)
        : null
)

const debtFreeDate = computed(() => {
    if (!monthsToDebtFree.value) return 'Need more income'
    const d = new Date()
    d.setMonth(d.getMonth() + monthsToDebtFree.value)
    return d.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
})

const totalNeeded = computed(() => props.snapshot.total_debt + (props.northStarCost || 0))

const monthsToNorthStar = computed(() =>
    monthlyDebtCapacity.value > 0 && props.northStarCost > 0
        ? Math.ceil(totalNeeded.value / monthlyDebtCapacity.value)
        : null
)

const northStarDate = computed(() => {
    if (!monthsToNorthStar.value) return props.northStarCost > 0 ? 'Need more income' : 'No goal set'
    const d = new Date()
    d.setMonth(d.getMonth() + monthsToNorthStar.value)
    return d.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
})

const incomeChange = computed(() => newMonthlyIncome.value - props.snapshot.monthly_income)
const obligationsChange = computed(() => props.snapshot.monthly_obligations - newMonthlyObligations.value)

// Format helpers
const fmt = (n: number) => '$' + Math.abs(n).toLocaleString('en-US', { maximumFractionDigits: 0 })
const fmtSigned = (n: number) => (n >= 0 ? '+' : '-') + fmt(n)

// Pre-built scenario presets
const presets = computed(() => {
    const s = props.snapshot
    const avg = params.retainer_value || 3500
    const maxSavings = props.expenseOptimization?.total_monthly_savings ?? 0
    const taxSavings = props.taxOptimization?.monthly_tax_savings ?? 0

    const breakEvenRetainers = s.disposable_income < 0
        ? Math.ceil(Math.abs(s.disposable_income) / avg)
        : 0

    const debtFree36Monthly = s.total_debt / 36
    const needed36 = Math.max(0, debtFree36Monthly - s.disposable_income)
    const retainers36 = Math.ceil(needed36 / avg)

    const totalForNS = s.total_debt + (props.northStarCost || 0)
    const needed60 = Math.max(0, (totalForNS / 60) - s.disposable_income)
    const retainers60 = Math.ceil(needed60 / avg)

    const cutOnlyMonths = maxSavings > 0 && (s.disposable_income + maxSavings) > 0
        ? Math.ceil(s.total_debt / (s.disposable_income + maxSavings))
        : null

    return [
        {
            label: breakEvenRetainers > 0
                ? `${breakEvenRetainers} more retainer${breakEvenRetainers > 1 ? 's' : ''} = break even`
                : 'You already break even',
            description: breakEvenRetainers > 0
                ? `At ${fmt(avg)}/mo each, ${breakEvenRetainers} retainers covers the gap`
                : 'Your income exceeds obligations',
            icon: '=',
            apply: () => {
                resetParams()
                params.additional_retainers = breakEvenRetainers
            },
        },
        {
            label: `Debt-free in 3 years`,
            description: `${retainers36} retainers at ${fmt(avg)}/mo needed`,
            icon: '3Y',
            apply: () => {
                resetParams()
                params.additional_retainers = retainers36
            },
        },
        {
            label: props.northStarCost > 0 ? 'North Star in 5 years' : 'Debt-free in 5 years',
            description: `${retainers60} retainers + tax optimization`,
            icon: '5Y',
            apply: () => {
                resetParams()
                params.additional_retainers = retainers60
                params.apply_tax_optimization = true
            },
        },
        {
            label: 'Expense cuts only',
            description: cutOnlyMonths
                ? `Cut ${fmt(maxSavings)}/mo = debt free in ${Math.floor(cutOnlyMonths / 12)}y ${cutOnlyMonths % 12}mo`
                : 'Not enough savings identified',
            icon: '$$',
            apply: () => {
                resetParams()
                params.expense_reduction = maxSavings
            },
        },
    ]
})

const resetParams = () => {
    params.additional_retainers = 0
    params.retainer_value = props.avgRetainerValue || 3500
    params.additional_project_revenue = 0
    params.expense_reduction = 0
    params.additional_expense_cuts = 0
    params.extra_debt_payment = 0
    params.payoff_method = 'hybrid'
    params.apply_tax_optimization = false
    detailedPlan.value = null
}

const calculateDetailedPlan = () => {
    isCalculating.value = true
    fetch('/life/strategy/scenarios', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
            monthly_debt_budget: monthlyDebtCapacity.value,
            payoff_method: params.payoff_method,
        }),
    })
        .then(r => r.json())
        .then(data => {
            detailedPlan.value = data
        })
        .finally(() => {
            isCalculating.value = false
        })
}

const saveScenario = () => {
    if (savedScenarios.value.length >= 3) {
        savedScenarios.value.shift()
    }
    savedScenarios.value.push({
        name: `Scenario ${savedScenarios.value.length + 1}`,
        params: { ...params },
        income: newMonthlyIncome.value,
        obligations: newMonthlyObligations.value,
        debtFreeDate: debtFreeDate.value,
        northStarDate: northStarDate.value,
        monthsDebtFree: monthsToDebtFree.value || 0,
        monthsNorthStar: monthsToNorthStar.value || 0,
    })
}

const removeScenario = (index: number) => {
    savedScenarios.value.splice(index, 1)
}

// AI Analysis
const aiQuestion = ref('')
const aiAnalysis = ref('')
const isAnalyzing = ref(false)

let pollTimer: ReturnType<typeof setInterval> | null = null

const askAI = () => {
    if (!aiQuestion.value || isAnalyzing.value) return
    isAnalyzing.value = true
    aiAnalysis.value = ''

    fetch('/life/strategy/analyze', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
            question: aiQuestion.value,
            scenario: {
                additional_retainers: params.additional_retainers,
                retainer_value: params.retainer_value,
                additional_project_revenue: params.additional_project_revenue,
                expense_reduction: params.expense_reduction + params.additional_expense_cuts,
                extra_debt_payment: params.extra_debt_payment,
                new_monthly_income: newMonthlyIncome.value,
                new_monthly_obligations: newMonthlyObligations.value,
                monthly_debt_capacity: monthlyDebtCapacity.value,
                estimated_debt_free_months: monthsToDebtFree.value,
                estimated_north_star_months: monthsToNorthStar.value,
            },
        }),
    })
        .then(r => r.json())
        .then(data => {
            if (data.key) {
                pollForResult(data.key)
            }
        })
        .catch(() => {
            aiAnalysis.value = 'Failed to start analysis. Please try again.'
            isAnalyzing.value = false
        })
}

const pollForResult = (key: string) => {
    if (pollTimer) clearInterval(pollTimer)

    pollTimer = setInterval(() => {
        fetch(`/life/strategy/analyze/${key}`)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'complete' || data.status === 'failed') {
                    if (pollTimer) clearInterval(pollTimer)
                    pollTimer = null
                    aiAnalysis.value = data.analysis || 'No analysis returned.'
                    isAnalyzing.value = false
                }
            })
            .catch(() => {
                if (pollTimer) clearInterval(pollTimer)
                pollTimer = null
                aiAnalysis.value = 'Failed to retrieve analysis.'
                isAnalyzing.value = false
            })
    }, 3000)
}

const renderMarkdown = (text: string): string => {
    return text
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/^### (.*$)/gm, '<h4>$1</h4>')
        .replace(/^## (.*$)/gm, '<h3>$1</h3>')
        .replace(/^# (.*$)/gm, '<h2>$1</h2>')
        .replace(/^- (.*$)/gm, '<li>$1</li>')
        .replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>')
        .replace(/\n{2,}/g, '</p><p>')
        .replace(/\n/g, '<br>')
        .replace(/^(.*)$/, '<p>$1</p>')
}
</script>

<template>
    <div class="scenario-section">
        <div class="scenario-header">
            <h2 class="scenario-title">What-If Scenario Planner</h2>
            <p class="scenario-subtitle">Drag the sliders to explore how different decisions affect your timeline</p>
        </div>

        <!-- Preset Cards -->
        <div class="preset-grid">
            <button
                v-for="(preset, i) in presets"
                :key="i"
                class="preset-card"
                @click="preset.apply"
            >
                <span class="preset-icon">{{ preset.icon }}</span>
                <span class="preset-label">{{ preset.label }}</span>
                <span class="preset-desc">{{ preset.description }}</span>
            </button>
        </div>

        <!-- Main Planner -->
        <div class="planner-layout">
            <!-- Left: Sliders -->
            <div class="sliders-panel">
                <!-- Revenue -->
                <div class="slider-group">
                    <h3 class="slider-group-title">Revenue</h3>

                    <div class="slider-row">
                        <div class="slider-header">
                            <label>Additional Retainers</label>
                            <span class="slider-value">{{ params.additional_retainers }}</span>
                        </div>
                        <input
                            v-model.number="params.additional_retainers"
                            type="range" min="0" max="15" step="1"
                            class="scenario-slider"
                        />
                        <div class="slider-range"><span>0</span><span>15</span></div>
                    </div>

                    <div class="slider-row">
                        <div class="slider-header">
                            <label>Retainer Value</label>
                            <span class="slider-value">{{ fmt(params.retainer_value) }}/mo</span>
                        </div>
                        <input
                            v-model.number="params.retainer_value"
                            type="range" min="500" max="15000" step="250"
                            class="scenario-slider"
                        />
                        <div class="slider-range"><span>$500</span><span>$15,000</span></div>
                    </div>

                    <div class="slider-row">
                        <div class="slider-header">
                            <label>Additional Project Revenue</label>
                            <span class="slider-value">{{ fmt(params.additional_project_revenue) }}/mo</span>
                        </div>
                        <input
                            v-model.number="params.additional_project_revenue"
                            type="range" min="0" max="50000" step="1000"
                            class="scenario-slider"
                        />
                        <div class="slider-range"><span>$0</span><span>$50k</span></div>
                    </div>
                </div>

                <!-- Expenses -->
                <div class="slider-group">
                    <h3 class="slider-group-title">Expenses</h3>

                    <div class="slider-row">
                        <div class="slider-header">
                            <label>Expense Reduction</label>
                            <span class="slider-value">{{ fmt(params.expense_reduction) }}/mo</span>
                        </div>
                        <input
                            v-model.number="params.expense_reduction"
                            type="range" min="0" :max="Math.max(expenseOptimization?.total_monthly_savings ?? 5000, 1000)" step="50"
                            class="scenario-slider"
                        />
                    </div>

                    <div class="slider-row">
                        <div class="slider-header">
                            <label>Additional Cuts</label>
                            <span class="slider-value">{{ fmt(params.additional_expense_cuts) }}/mo</span>
                        </div>
                        <input
                            v-model.number="params.additional_expense_cuts"
                            type="range" min="0" max="5000" step="100"
                            class="scenario-slider"
                        />
                    </div>
                </div>

                <!-- Debt Strategy -->
                <div class="slider-group">
                    <h3 class="slider-group-title">Debt Strategy</h3>

                    <div class="slider-row">
                        <div class="slider-header">
                            <label>Extra Debt Payment</label>
                            <span class="slider-value">{{ fmt(params.extra_debt_payment) }}/mo</span>
                        </div>
                        <input
                            v-model.number="params.extra_debt_payment"
                            type="range" min="0" max="10000" step="100"
                            class="scenario-slider"
                        />
                    </div>

                    <div class="slider-row">
                        <div class="slider-header">
                            <label>Payoff Method</label>
                        </div>
                        <div class="method-toggle">
                            <button :class="['method-btn', { active: params.payoff_method === 'hybrid' }]" @click="params.payoff_method = 'hybrid'">Hybrid</button>
                            <button :class="['method-btn', { active: params.payoff_method === 'avalanche' }]" @click="params.payoff_method = 'avalanche'">Avalanche</button>
                            <button :class="['method-btn', { active: params.payoff_method === 'snowball' }]" @click="params.payoff_method = 'snowball'">Snowball</button>
                        </div>
                    </div>

                    <div class="slider-row">
                        <label class="toggle-row">
                            <input v-model="params.apply_tax_optimization" type="checkbox" class="toggle-input" />
                            <span class="toggle-label">Apply tax optimization ({{ fmt(taxOptimization?.monthly_tax_savings ?? 0) }}/mo)</span>
                        </label>
                    </div>
                </div>

                <div class="slider-actions">
                    <button class="btn btn-secondary btn-sm" @click="resetParams">Reset</button>
                    <button class="btn btn-secondary btn-sm" @click="saveScenario">Save Scenario</button>
                    <button class="btn btn-primary btn-sm" :disabled="isCalculating" @click="calculateDetailedPlan">
                        {{ isCalculating ? 'Calculating...' : 'Calculate Detailed Plan' }}
                    </button>
                </div>
            </div>

            <!-- Right: Projections -->
            <div class="projections-panel">
                <div class="projection-cards">
                    <div class="proj-card">
                        <div class="proj-label">Monthly Income</div>
                        <div class="proj-value" :style="{ color: 'var(--color-status-green)' }">{{ fmt(newMonthlyIncome) }}</div>
                        <div v-if="incomeChange !== 0" class="proj-delta" :class="incomeChange > 0 ? 'delta-pos' : 'delta-neg'">
                            {{ fmtSigned(incomeChange) }}
                        </div>
                    </div>
                    <div class="proj-card">
                        <div class="proj-label">Monthly Obligations</div>
                        <div class="proj-value">{{ fmt(newMonthlyObligations) }}</div>
                        <div v-if="obligationsChange !== 0" class="proj-delta delta-pos">
                            -{{ fmt(obligationsChange) }} saved
                        </div>
                    </div>
                    <div class="proj-card">
                        <div class="proj-label">Disposable Income</div>
                        <div class="proj-value" :style="{ color: newDisposableIncome >= 0 ? 'var(--color-status-green)' : 'var(--color-status-red)' }">
                            {{ fmt(newDisposableIncome) }}
                        </div>
                    </div>
                    <div class="proj-card">
                        <div class="proj-label">Monthly Debt Capacity</div>
                        <div class="proj-value" style="color: var(--color-accent)">{{ fmt(monthlyDebtCapacity) }}</div>
                    </div>
                </div>

                <!-- Timeline -->
                <div class="timeline-cards">
                    <div class="timeline-card">
                        <div class="timeline-icon" style="color: var(--color-status-green)">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-label">Debt-Free</div>
                            <div class="timeline-date">{{ debtFreeDate }}</div>
                            <div v-if="monthsToDebtFree" class="timeline-months">{{ monthsToDebtFree }} months ({{ (monthsToDebtFree / 12).toFixed(1) }} years)</div>
                        </div>
                    </div>
                    <div v-if="northStarCost > 0" class="timeline-card">
                        <div class="timeline-icon" style="color: var(--color-accent)">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-label">North Star Goal</div>
                            <div class="timeline-date">{{ northStarDate }}</div>
                            <div v-if="monthsToNorthStar" class="timeline-months">{{ monthsToNorthStar }} months ({{ (monthsToNorthStar / 12).toFixed(1) }} years)</div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Plan (from server) -->
                <div v-if="detailedPlan" class="detailed-plan">
                    <h4 class="detailed-title">Detailed Payoff Timeline</h4>
                    <div v-if="detailedPlan.debt_payoff?.hardship_warning" class="hardship-warning">
                        {{ detailedPlan.debt_payoff.hardship_warning }}
                    </div>
                    <div class="payoff-order">
                        <div v-for="(debt, i) in detailedPlan.debt_payoff?.payoff_order ?? []" :key="i" class="payoff-item">
                            <span class="payoff-rank">{{ i + 1 }}</span>
                            <span class="payoff-name">{{ debt.name }}</span>
                            <span class="payoff-date">{{ debt.date || `Month ${debt.month}` }}</span>
                        </div>
                    </div>
                    <div v-if="detailedPlan.debt_payoff?.total_interest_paid" class="interest-note">
                        Total interest paid: {{ fmt(detailedPlan.debt_payoff.total_interest_paid) }}
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Assumption Analysis -->
        <div class="ai-section">
            <h3 class="ai-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                Challenge My Assumptions
            </h3>
            <div class="ai-input-row">
                <input
                    v-model="aiQuestion"
                    class="ai-input"
                    placeholder="e.g., Is $4M enough to pay off all debt, taxes, and buy a $2M house?"
                    @keydown.enter="askAI"
                />
                <button class="btn btn-primary btn-sm" :disabled="isAnalyzing || !aiQuestion" @click="askAI">
                    {{ isAnalyzing ? 'Analyzing...' : 'Analyze' }}
                </button>
            </div>
            <div class="ai-quick-questions">
                <button class="ai-quick-btn" @click="aiQuestion = `With ${fmt(newMonthlyIncome)} monthly income and ${fmt(snapshot.total_debt)} in debt, can I realistically be debt-free by ${debtFreeDate}?`; askAI()">
                    Is my debt-free timeline realistic?
                </button>
                <button class="ai-quick-btn" @click="aiQuestion = `I need about ${fmt(totalNeeded)} total (${fmt(snapshot.total_debt)} debt + ${fmt(northStarCost)} north star goal). With ${fmt(monthlyDebtCapacity)}/mo capacity, is my timeline achievable? What am I missing?`; askAI()">
                    What am I overlooking?
                </button>
                <button class="ai-quick-btn" @click="aiQuestion = `What tax implications should I plan for if I grow my freelance income from ${fmt(snapshot.monthly_income)}/mo to ${fmt(newMonthlyIncome)}/mo?`; askAI()">
                    Tax impact of income growth
                </button>
            </div>
            <div v-if="aiAnalysis" class="ai-response">
                <div class="ai-response-content" v-html="renderMarkdown(aiAnalysis)"></div>
            </div>
        </div>

        <!-- Comparison -->
        <div v-if="savedScenarios.length > 0" class="comparison-section">
            <h3 class="comparison-title">Scenario Comparison</h3>
            <div class="comparison-grid" :style="{ gridTemplateColumns: `repeat(${savedScenarios.length}, 1fr)` }">
                <div v-for="(sc, i) in savedScenarios" :key="i" class="comparison-card">
                    <div class="comparison-header">
                        <span class="comparison-name">{{ sc.name }}</span>
                        <button class="comparison-remove" @click="removeScenario(i)">&times;</button>
                    </div>
                    <div class="comparison-row">
                        <span>Income</span>
                        <span class="mono">{{ fmt(sc.income) }}</span>
                    </div>
                    <div class="comparison-row">
                        <span>Obligations</span>
                        <span class="mono">{{ fmt(sc.obligations) }}</span>
                    </div>
                    <div class="comparison-row highlight">
                        <span>Debt-Free</span>
                        <span class="mono">{{ sc.debtFreeDate }}</span>
                    </div>
                    <div v-if="northStarCost > 0" class="comparison-row highlight">
                        <span>North Star</span>
                        <span class="mono">{{ sc.northStarDate }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.scenario-section {
    margin-top: 32px;
}

.scenario-header {
    margin-bottom: 20px;
}

.scenario-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--color-text-primary);
}

.scenario-subtitle {
    font-size: 13px;
    color: var(--color-text-tertiary);
    margin-top: 4px;
}

/* Presets */
.preset-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.preset-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    padding: 12px 14px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
    cursor: pointer;
    text-align: left;
    transition: all 0.15s;
}

.preset-card:hover {
    border-color: var(--color-accent);
    background: rgba(139, 92, 246, 0.04);
}

.preset-icon {
    font-size: 11px;
    font-weight: 700;
    color: var(--color-accent);
    background: rgba(139, 92, 246, 0.1);
    padding: 2px 6px;
    border-radius: 4px;
    font-family: var(--font-mono);
}

.preset-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-text-primary);
}

.preset-desc {
    font-size: 11px;
    color: var(--color-text-tertiary);
    line-height: 1.3;
}

/* Planner Layout */
.planner-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

/* Sliders Panel */
.sliders-panel {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    padding: 20px;
}

.slider-group {
    margin-bottom: 20px;
}

.slider-group:last-of-type {
    margin-bottom: 16px;
}

.slider-group-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin-bottom: 12px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--color-border-subtle);
}

.slider-row {
    margin-bottom: 14px;
}

.slider-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.slider-header label {
    font-size: 12px;
    font-weight: 500;
    color: var(--color-text-secondary);
}

.slider-value {
    font-size: 13px;
    font-weight: 600;
    font-family: var(--font-mono);
    color: var(--color-accent);
}

.scenario-slider {
    -webkit-appearance: none;
    appearance: none;
    width: 100%;
    height: 6px;
    border-radius: 3px;
    background: var(--color-bg-tertiary);
    outline: none;
}

.scenario-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--color-accent);
    cursor: grab;
    border: 2px solid var(--color-bg-primary);
    box-shadow: 0 2px 6px rgba(139, 92, 246, 0.3);
}

.scenario-slider::-moz-range-thumb {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--color-accent);
    cursor: grab;
    border: 2px solid var(--color-bg-primary);
}

.slider-range {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: var(--color-text-quaternary);
    margin-top: 2px;
}

.method-toggle {
    display: flex;
    gap: 4px;
    background: var(--color-bg-tertiary);
    border-radius: 6px;
    padding: 3px;
}

.method-btn {
    flex: 1;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 500;
    border: none;
    border-radius: 4px;
    background: none;
    color: var(--color-text-tertiary);
    cursor: pointer;
    transition: all 0.15s;
}

.method-btn.active {
    background: var(--color-bg-secondary);
    color: var(--color-text-primary);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.toggle-row {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.toggle-input {
    accent-color: var(--color-accent);
    width: 16px;
    height: 16px;
}

.toggle-label {
    font-size: 12px;
    color: var(--color-text-secondary);
}

.slider-actions {
    display: flex;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid var(--color-border-subtle);
}

/* Projections Panel */
.projections-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.projection-cards {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.proj-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
    padding: 14px;
}

.proj-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin-bottom: 4px;
}

.proj-value {
    font-size: 22px;
    font-weight: 700;
    font-family: var(--font-mono);
    color: var(--color-text-primary);
}

.proj-delta {
    font-size: 11px;
    font-weight: 500;
    margin-top: 2px;
}

.delta-pos { color: var(--color-status-green); }
.delta-neg { color: var(--color-status-red); }

/* Timeline */
.timeline-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.timeline-card {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
    padding: 16px;
}

.timeline-icon {
    flex-shrink: 0;
}

.timeline-content {
    flex: 1;
}

.timeline-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--color-text-tertiary);
}

.timeline-date {
    font-size: 20px;
    font-weight: 700;
    font-family: var(--font-mono);
    color: var(--color-text-primary);
}

.timeline-months {
    font-size: 12px;
    color: var(--color-text-tertiary);
    margin-top: 2px;
}

/* Detailed Plan */
.detailed-plan {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
    padding: 16px;
}

.detailed-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 10px;
}

.hardship-warning {
    font-size: 12px;
    color: var(--color-status-red);
    background: rgba(239, 68, 68, 0.08);
    padding: 8px 12px;
    border-radius: 6px;
    margin-bottom: 10px;
}

.payoff-order {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.payoff-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
}

.payoff-rank {
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
    font-size: 10px;
    font-weight: 600;
    flex-shrink: 0;
}

.payoff-name {
    flex: 1;
    font-weight: 500;
    color: var(--color-text-primary);
}

.payoff-date {
    font-family: var(--font-mono);
    color: var(--color-text-secondary);
}

.interest-note {
    font-size: 11px;
    color: var(--color-text-tertiary);
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid var(--color-border-subtle);
}

/* Comparison */
.comparison-section {
    margin-top: 20px;
}

.comparison-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 12px;
}

.comparison-grid {
    display: grid;
    gap: 10px;
}

.comparison-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 10px;
    overflow: hidden;
}

.comparison-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: var(--color-bg-tertiary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.comparison-name {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-primary);
}

.comparison-remove {
    background: none;
    border: none;
    color: var(--color-text-quaternary);
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
}

.comparison-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 14px;
    font-size: 12px;
    color: var(--color-text-secondary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.comparison-row:last-child {
    border-bottom: none;
}

.comparison-row.highlight {
    font-weight: 600;
    color: var(--color-text-primary);
}

.mono {
    font-family: var(--font-mono);
}

/* AI Analysis */
.ai-section {
    margin-top: 20px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    padding: 20px;
}

.ai-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 12px;
}

.ai-title svg {
    color: var(--color-accent);
}

.ai-input-row {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
}

.ai-input {
    flex: 1;
    padding: 8px 12px;
    font-size: 13px;
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
}

.ai-input::placeholder {
    color: var(--color-text-quaternary);
}

.ai-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.ai-quick-questions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.ai-quick-btn {
    font-size: 11px;
    padding: 4px 10px;
    border-radius: 12px;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}

.ai-quick-btn:hover {
    border-color: var(--color-accent);
    color: var(--color-accent);
    background: rgba(139, 92, 246, 0.05);
}

.ai-response {
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    padding: 16px;
    margin-top: 8px;
}

.ai-response-content {
    font-size: 13px;
    line-height: 1.6;
    color: var(--color-text-primary);
}

.ai-response-content :deep(h2),
.ai-response-content :deep(h3),
.ai-response-content :deep(h4) {
    font-size: 14px;
    font-weight: 600;
    margin: 12px 0 6px;
    color: var(--color-text-primary);
}

.ai-response-content :deep(ul) {
    padding-left: 16px;
    margin: 6px 0;
}

.ai-response-content :deep(li) {
    margin-bottom: 4px;
}

.ai-response-content :deep(strong) {
    color: var(--color-accent);
}

.ai-response-content :deep(p) {
    margin-bottom: 8px;
}

/* Responsive */
@media (max-width: 1024px) {
    .planner-layout {
        grid-template-columns: 1fr;
    }
    .preset-grid {
        grid-template-columns: 1fr 1fr;
    }
    .comparison-grid {
        grid-template-columns: 1fr !important;
    }
}

@media (max-width: 640px) {
    .preset-grid {
        grid-template-columns: 1fr;
    }
    .projection-cards {
        grid-template-columns: 1fr;
    }
    .slider-actions {
        flex-wrap: wrap;
    }
    .slider-actions .btn {
        flex: 1;
        min-width: 0;
        justify-content: center;
        text-align: center;
    }
    .scenario-slider {
        height: 8px;
    }
    .scenario-slider::-webkit-slider-thumb {
        width: 24px;
        height: 24px;
    }
    .scenario-slider::-moz-range-thumb {
        width: 24px;
        height: 24px;
    }
    .timeline-card {
        flex-direction: column;
        text-align: center;
        gap: 8px;
    }
    .proj-value {
        font-size: 18px;
    }
    .timeline-date {
        font-size: 18px;
    }
    .method-toggle {
        flex-wrap: wrap;
    }
}
</style>
