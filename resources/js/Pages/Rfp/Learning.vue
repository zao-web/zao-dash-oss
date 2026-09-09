<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/Badge.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import FormTextarea from '@/Components/FormTextarea.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, reactive, computed } from 'vue'

interface LearningInsight {
    id: number
    insight_type: string
    title: string
    description: string
    evidence: string[] | null
    confidence: number
    impact_area: string
    actionable_recommendation: string | null
    applied_to_proposals: number
    is_active: boolean
    created_at: string
}

interface WinRateTrend {
    month: string
    win_rate: number
    total: number
    won: number
}

interface Competitor {
    name: string
    count: number
    avg_pricing: number | null
    strengths: string[] | null
}

interface CompetitiveIntel {
    competitors: Competitor[]
    pricing: Record<string, number> | null
}

interface OutcomeBreakdown {
    outcome: string
    count: number
}

const props = defineProps<{
    insights: LearningInsight[]
    winRateTrend: WinRateTrend[]
    competitiveIntel: CompetitiveIntel
    outcomeBreakdown: OutcomeBreakdown[]
}>()

// Add Insight modal
const showAddInsightModal = ref(false)
const isSavingInsight = ref(false)

const insightForm = reactive({
    insight_type: 'manual_feedback',
    title: '',
    description: '',
    impact_area: 'content',
    confidence: '0.80',
    actionable_recommendation: '',
})

const insightTypeOptions = [
    { value: 'manual_feedback', label: 'General Feedback' },
    { value: 'win_pattern', label: 'Win Pattern' },
    { value: 'loss_pattern', label: 'Loss Pattern' },
    { value: 'pricing_insight', label: 'Pricing Insight' },
    { value: 'industry_trend', label: 'Industry Trend' },
    { value: 'content_improvement', label: 'Content Improvement' },
]

const impactAreaOptions = [
    { value: 'content', label: 'Content' },
    { value: 'pricing', label: 'Pricing' },
    { value: 'targeting', label: 'Targeting' },
    { value: 'process', label: 'Process' },
    { value: 'presentation', label: 'Presentation' },
]

const confidenceOptions = [
    { value: '0.50', label: 'Low (50%)' },
    { value: '0.65', label: 'Moderate (65%)' },
    { value: '0.80', label: 'High (80%)' },
    { value: '0.95', label: 'Very High (95%)' },
    { value: '1.00', label: 'Certain (100%)' },
]

const openAddInsightModal = () => {
    insightForm.insight_type = 'manual_feedback'
    insightForm.title = ''
    insightForm.description = ''
    insightForm.impact_area = 'content'
    insightForm.confidence = '0.80'
    insightForm.actionable_recommendation = ''
    showAddInsightModal.value = true
}

const saveInsight = () => {
    isSavingInsight.value = true
    router.post('/rfp/learning', {
        ...insightForm,
        confidence: parseFloat(insightForm.confidence),
    }, {
        onSuccess: () => {
            showAddInsightModal.value = false
        },
        onFinish: () => {
            isSavingInsight.value = false
        },
    })
}

// Stats
const totalOutcomes = computed(() => {
    return props.outcomeBreakdown.reduce((sum, o) => sum + o.count, 0)
})

const winRate = computed(() => {
    const won = props.outcomeBreakdown.find(o => o.outcome === 'won')?.count || 0
    const lost = props.outcomeBreakdown.find(o => o.outcome === 'lost')?.count || 0
    const total = won + lost
    if (total === 0) return 0
    return Math.round((won / total) * 100)
})

const avgFitScoreDisplay = computed(() => {
    // Derive from insights if present, otherwise show '--'
    return '--'
})

const activeInsightsCount = computed(() => {
    return props.insights.filter(i => i.is_active).length
})

// Win rate trend chart helpers
const maxWinRate = computed(() => {
    if (props.winRateTrend.length === 0) return 100
    return Math.max(...props.winRateTrend.map(t => t.win_rate), 20)
})

// Insight type styling
const insightTypeColors: Record<string, { bg: string; text: string; label: string }> = {
    win_pattern: { bg: 'rgba(34, 197, 94, 0.1)', text: 'var(--color-status-green)', label: 'Win Pattern' },
    loss_pattern: { bg: 'rgba(239, 68, 68, 0.1)', text: 'var(--color-status-red)', label: 'Loss Pattern' },
    decline_pattern: { bg: 'rgba(239, 68, 68, 0.06)', text: 'var(--color-status-red)', label: 'Decline Pattern' },
    pricing_insight: { bg: 'rgba(59, 130, 246, 0.1)', text: 'var(--color-status-blue)', label: 'Pricing' },
    industry_trend: { bg: 'rgba(139, 92, 246, 0.1)', text: 'var(--color-accent)', label: 'Industry Trend' },
    content_improvement: { bg: 'rgba(234, 179, 8, 0.1)', text: '#eab308', label: 'Content' },
    manual_feedback: { bg: 'rgba(20, 184, 166, 0.1)', text: '#14b8a6', label: 'Manual Feedback' },
}

const impactAreaColors: Record<string, string> = {
    pricing: 'info',
    content: 'warning',
    targeting: 'success',
    process: 'neutral',
    presentation: 'info',
}

// Outcome colors
const outcomeColors: Record<string, { bg: string; text: string; variant: string }> = {
    won: { bg: 'rgba(34, 197, 94, 0.1)', text: 'var(--color-status-green)', variant: 'success' },
    lost: { bg: 'rgba(239, 68, 68, 0.1)', text: 'var(--color-status-red)', variant: 'danger' },
    no_response: { bg: 'rgba(100, 116, 139, 0.1)', text: 'var(--color-text-tertiary)', variant: 'neutral' },
    withdrawn: { bg: 'rgba(234, 179, 8, 0.1)', text: '#eab308', variant: 'warning' },
}

const formatCurrency = (value: number | null) => {
    if (!value) return '--'
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(value)
}
</script>

<template>
    <AppLayout title="RFP Learning Dashboard">
        <!-- Back link + Nav -->
        <div class="page-nav">
            <Link href="/rfp" class="back-link">
                <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                Back to RFP Pipeline
            </Link>
            <div class="sub-nav">
                <Link href="/rfp" class="sub-nav-link">
                    Pipeline
                </Link>
                <Link href="/rfp/sources" class="sub-nav-link">
                    Sources
                </Link>
                <Link href="/rfp/learning" class="sub-nav-link sub-nav-active">
                    Learning
                </Link>
            </div>
        </div>

        <!-- Title -->
        <div class="page-header-simple">
            <div class="page-header-row">
                <div>
                    <h1 class="page-title">Learning Dashboard</h1>
                    <p class="page-subtitle">Aggregate insights from RFP outcomes and competitive intelligence</p>
                </div>
                <button class="btn-add-insight" @click="openAddInsightModal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Insight
                </button>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-grid">
            <div class="metric-card">
                <div class="metric-label">TOTAL OUTCOMES</div>
                <div class="metric-value">{{ totalOutcomes }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">WIN RATE</div>
                <div class="metric-value" style="color: var(--color-status-green);">{{ winRate }}%</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIVE INSIGHTS</div>
                <div class="metric-value" style="color: var(--color-accent);">{{ activeInsightsCount }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">COMPETITORS TRACKED</div>
                <div class="metric-value">{{ competitiveIntel.competitors?.length || 0 }}</div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div class="content-main">
                <!-- Win Rate Trend -->
                <div class="section-card">
                    <h2 class="section-title">Win Rate Trend</h2>
                    <div v-if="winRateTrend.length > 0" class="chart-bars">
                        <div
                            v-for="item in winRateTrend"
                            :key="item.month"
                            class="chart-bar-col"
                        >
                            <div class="bar-value">{{ item.win_rate }}%</div>
                            <div class="bar-track">
                                <div
                                    class="bar-fill"
                                    :style="{ height: `${(item.win_rate / maxWinRate) * 100}%` }"
                                ></div>
                            </div>
                            <div class="bar-label">{{ item.month }}</div>
                            <div class="bar-count">{{ item.won }}/{{ item.total }}</div>
                        </div>
                    </div>
                    <div v-else class="empty-chart">
                        <p>Not enough data to show trends yet</p>
                    </div>
                </div>

                <!-- Active Insights -->
                <div class="section-card">
                    <h2 class="section-title">Active Insights</h2>
                    <div v-if="insights.length > 0" class="insights-list">
                        <div
                            v-for="insight in insights"
                            :key="insight.id"
                            class="insight-card"
                        >
                            <div class="insight-header">
                                <div class="insight-badges">
                                    <span
                                        class="type-badge"
                                        :style="{
                                            background: (insightTypeColors[insight.insight_type] || insightTypeColors.content_improvement).bg,
                                            color: (insightTypeColors[insight.insight_type] || insightTypeColors.content_improvement).text,
                                        }"
                                    >
                                        {{ (insightTypeColors[insight.insight_type] || insightTypeColors.content_improvement).label }}
                                    </span>
                                    <Badge :variant="(impactAreaColors[insight.impact_area] as any) || 'neutral'" size="sm">
                                        {{ insight.impact_area }}
                                    </Badge>
                                </div>
                                <span v-if="insight.applied_to_proposals > 0" class="applied-count">
                                    Applied {{ insight.applied_to_proposals }}x
                                </span>
                            </div>

                            <h3 class="insight-title">{{ insight.title }}</h3>
                            <p class="insight-description">{{ insight.description }}</p>

                            <!-- Confidence Bar -->
                            <div class="confidence-row">
                                <span class="confidence-label">Confidence</span>
                                <div class="confidence-track">
                                    <div
                                        class="confidence-fill"
                                        :style="{ width: `${Math.round(insight.confidence * 100)}%` }"
                                    ></div>
                                </div>
                                <span class="confidence-value">{{ Math.round(insight.confidence * 100) }}%</span>
                            </div>

                            <!-- Actionable Recommendation -->
                            <div v-if="insight.actionable_recommendation" class="recommendation-box">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                <span>{{ insight.actionable_recommendation }}</span>
                            </div>

                            <!-- Evidence count -->
                            <div v-if="insight.evidence && insight.evidence.length > 0" class="evidence-count">
                                Based on {{ insight.evidence.length }} data point{{ insight.evidence.length === 1 ? '' : 's' }}
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-section">
                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                        </svg>
                        <p>No insights generated yet. Record outcomes to build learning data.</p>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="content-sidebar">
                <!-- Outcome Breakdown -->
                <div class="section-card">
                    <h2 class="section-title">Outcome Breakdown</h2>
                    <div v-if="outcomeBreakdown.length > 0" class="outcome-list">
                        <div
                            v-for="item in outcomeBreakdown"
                            :key="item.outcome"
                            class="outcome-row"
                        >
                            <div class="outcome-info">
                                <span
                                    class="outcome-dot"
                                    :style="{ background: (outcomeColors[item.outcome] || outcomeColors.no_response).text }"
                                ></span>
                                <span class="outcome-label">{{ item.outcome.replace(/_/g, ' ') }}</span>
                            </div>
                            <div class="outcome-count-wrap">
                                <span
                                    class="outcome-count"
                                    :style="{ color: (outcomeColors[item.outcome] || outcomeColors.no_response).text }"
                                >
                                    {{ item.count }}
                                </span>
                                <div class="outcome-bar-track">
                                    <div
                                        class="outcome-bar-fill"
                                        :style="{
                                            width: totalOutcomes > 0 ? `${(item.count / totalOutcomes) * 100}%` : '0%',
                                            background: (outcomeColors[item.outcome] || outcomeColors.no_response).text,
                                        }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-section-sm">
                        <p>No outcomes recorded yet</p>
                    </div>
                </div>

                <!-- Competitive Intelligence -->
                <div class="section-card">
                    <h2 class="section-title">Competitive Intelligence</h2>
                    <div v-if="competitiveIntel.competitors && competitiveIntel.competitors.length > 0" class="competitors-list">
                        <div
                            v-for="competitor in competitiveIntel.competitors"
                            :key="competitor.name"
                            class="competitor-card"
                        >
                            <div class="competitor-header">
                                <span class="competitor-name">{{ competitor.name }}</span>
                                <span class="competitor-encounters">{{ competitor.count }} encounter{{ competitor.count === 1 ? '' : 's' }}</span>
                            </div>
                            <div v-if="competitor.avg_pricing" class="competitor-pricing">
                                Avg. pricing: {{ formatCurrency(competitor.avg_pricing) }}
                            </div>
                            <div v-if="competitor.strengths && competitor.strengths.length > 0" class="competitor-strengths">
                                <span v-for="(s, i) in competitor.strengths" :key="i" class="strength-pill">
                                    {{ s }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-section-sm">
                        <p>No competitor data available yet</p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Add Insight Modal -->
        <Modal :show="showAddInsightModal" title="Add Learning Insight" size="md" @close="showAddInsightModal = false">
            <div class="modal-form">
                <p class="modal-hint">Capture feedback received outside the system — from calls, emails, or meetings.</p>

                <FormSelect
                    v-model="insightForm.insight_type"
                    :options="insightTypeOptions"
                    label="Type"
                    :required="true"
                />

                <FormInput
                    v-model="insightForm.title"
                    label="Title"
                    placeholder="e.g., Client praised our case study approach"
                    :required="true"
                />

                <FormTextarea
                    v-model="insightForm.description"
                    label="Description"
                    placeholder="Describe the feedback or insight in detail..."
                    :rows="4"
                    :required="true"
                />

                <div class="form-row-2col">
                    <FormSelect
                        v-model="insightForm.impact_area"
                        :options="impactAreaOptions"
                        label="Impact Area"
                        :required="true"
                    />

                    <FormSelect
                        v-model="insightForm.confidence"
                        :options="confidenceOptions"
                        label="Confidence"
                        :required="true"
                    />
                </div>

                <FormTextarea
                    v-model="insightForm.actionable_recommendation"
                    label="Actionable Recommendation"
                    placeholder="What should we do differently based on this? (optional)"
                    :rows="2"
                />

                <div class="modal-actions">
                    <button class="btn-cancel" @click="showAddInsightModal = false">Cancel</button>
                    <button
                        class="btn-save"
                        :disabled="isSavingInsight || !insightForm.title || !insightForm.description"
                        @click="saveInsight"
                    >
                        {{ isSavingInsight ? 'Saving...' : 'Save Insight' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Page Nav */
.page-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    text-decoration: none;
}

.back-link:hover {
    color: var(--color-accent);
}

.back-icon {
    width: 16px;
    height: 16px;
}

.sub-nav {
    display: flex;
    gap: 2px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 3px;
}

.sub-nav-link {
    font-size: 13px;
    font-weight: 500;
    padding: 5px 14px;
    border-radius: 6px;
    color: var(--color-text-tertiary);
    text-decoration: none;
    transition: all 0.15s ease;
}

.sub-nav-link:hover {
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
}

.sub-nav-active {
    color: var(--color-text-primary);
    background: var(--color-bg-tertiary);
}

/* Page Header */
.page-header-simple {
    margin-bottom: 24px;
}

.page-header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
}

.btn-add-insight {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #fff;
    background: var(--color-accent);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
}

.btn-add-insight:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.page-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
}

.page-subtitle {
    font-size: 0.9375rem;
    color: var(--color-text-tertiary);
    margin-top: 4px;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

/* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 20px;
}

.content-main {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.content-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Section Card */
.section-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 12px;
    padding: 24px;
}

.section-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 16px;
}

/* Chart Bars */
.chart-bars {
    display: flex;
    gap: 0;
    align-items: flex-end;
    height: 200px;
    padding: 0 8px;
}

.chart-bar-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    min-width: 0;
}

.bar-value {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-primary);
    white-space: nowrap;
}

.bar-track {
    width: 100%;
    max-width: 48px;
    height: 120px;
    background: var(--color-bg-tertiary);
    border-radius: 6px 6px 0 0;
    display: flex;
    align-items: flex-end;
    overflow: hidden;
}

.bar-fill {
    width: 100%;
    background: linear-gradient(to top, var(--color-accent), rgba(139, 92, 246, 0.6));
    border-radius: 6px 6px 0 0;
    transition: height 0.5s ease;
    min-height: 4px;
}

.bar-label {
    font-size: 11px;
    font-weight: 500;
    color: var(--color-text-secondary);
    white-space: nowrap;
}

.bar-count {
    font-size: 10px;
    color: var(--color-text-quaternary);
}

.empty-chart {
    height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.empty-chart p {
    color: var(--color-text-quaternary);
    font-size: 0.875rem;
}

/* Insights */
.insights-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.insight-card {
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 10px;
    padding: 18px;
    transition: border-color 0.15s ease;
}

.insight-card:hover {
    border-color: var(--color-border-strong);
}

.insight-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.insight-badges {
    display: flex;
    gap: 6px;
    align-items: center;
}

.type-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
}

.applied-count {
    font-size: 11px;
    color: var(--color-text-quaternary);
    font-weight: 500;
}

.insight-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 6px;
}

.insight-description {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    line-height: 1.6;
    margin-bottom: 12px;
}

/* Confidence Bar */
.confidence-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.confidence-label {
    font-size: 11px;
    color: var(--color-text-quaternary);
    min-width: 64px;
}

.confidence-track {
    flex: 1;
    height: 6px;
    background: var(--color-bg-tertiary);
    border-radius: 3px;
    overflow: hidden;
}

.confidence-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--color-accent), rgba(139, 92, 246, 0.6));
    border-radius: 3px;
    transition: width 0.5s ease;
}

.confidence-value {
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-secondary);
    min-width: 32px;
    text-align: right;
}

/* Recommendation Box */
.recommendation-box {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 14px;
    background: rgba(139, 92, 246, 0.06);
    border: 1px solid rgba(139, 92, 246, 0.15);
    border-radius: 8px;
    margin-bottom: 8px;
}

.recommendation-box svg {
    color: var(--color-accent);
    margin-top: 1px;
}

.recommendation-box span {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
}

.evidence-count {
    font-size: 11px;
    color: var(--color-text-quaternary);
}

/* Outcome Breakdown */
.outcome-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.outcome-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.outcome-info {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 100px;
}

.outcome-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.outcome-label {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    text-transform: capitalize;
}

.outcome-count-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
}

.outcome-count {
    font-size: 1.125rem;
    font-weight: 700;
    min-width: 24px;
    text-align: right;
}

.outcome-bar-track {
    flex: 1;
    height: 8px;
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    overflow: hidden;
}

.outcome-bar-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.5s ease;
    min-width: 2px;
}

/* Competitive Intel */
.competitors-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.competitor-card {
    padding: 14px;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
}

.competitor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.competitor-name {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.competitor-encounters {
    font-size: 11px;
    color: var(--color-text-quaternary);
}

.competitor-pricing {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    margin-bottom: 8px;
}

.competitor-strengths {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.strength-pill {
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 4px;
    background: rgba(239, 68, 68, 0.08);
    color: var(--color-status-red);
}

/* Empty States */
.empty-section {
    text-align: center;
    padding: 40px 20px;
}

.empty-section svg {
    margin: 0 auto 10px;
    color: var(--color-text-quaternary);
}

.empty-section p {
    font-size: 0.875rem;
    color: var(--color-text-quaternary);
}

.empty-section-sm {
    padding: 20px;
    text-align: center;
}

.empty-section-sm p {
    font-size: 0.8125rem;
    color: var(--color-text-quaternary);
}

/* Modal Form */
.modal-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 4px 0;
}

.modal-hint {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    line-height: 1.5;
    margin-bottom: 4px;
}

.form-row-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border-subtle);
}

.btn-cancel {
    padding: 8px 16px;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.btn-cancel:hover {
    color: var(--color-text-primary);
    border-color: var(--color-border-strong);
}

.btn-save {
    padding: 8px 20px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #fff;
    background: var(--color-accent);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.btn-save:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-save:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Responsive */
@media (max-width: 1024px) {
    .content-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 640px) {
    .page-nav {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }

    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
