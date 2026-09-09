<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

interface ProposalSection {
    title: string
    content: string
}

interface PricingItem {
    item?: string
    name?: string
    description?: string
    total?: number
    amount?: number
    unit_price?: number
}

interface RequirementResponse {
    requirement: string
    response: string
    met?: boolean
}

interface DebateMetadata {
    rounds: number
    providers: string[]
    confidence: number
}

interface Version {
    id: number
    version: number
    status: string
    total_price: number | null
    created_at: string
    review_url: string
}

const props = defineProps<{
    rfp: {
        id: number
        slug: string
        title: string
        issuing_organization: string
        contact_name: string | null
        contact_email: string | null
        submission_deadline: string | null
        budget_min: number | null
        budget_max: number | null
        status: string
        fit_score: number | null
        requirements_summary: string | null
        evaluation_criteria: string | null
    }
    proposal: {
        id: number
        version: number
        status: string
        title: string
        executive_summary: string | null
        full_content: string | null
        proposal_sections: ProposalSection[]
        pricing_breakdown: PricingItem[]
        total_price: number | null
        requirement_responses: RequirementResponse[]
        case_studies_used: string[]
        testimonials_used: string[]
        tone_profile: string | null
        review_notes: string | null
        debate_metadata: DebateMetadata | null
        pdf_url: string
        download_url: string
        corporate_pdf_url: string
        google_doc_url: string
        send_email_url: string
        created_at: string
        google_drive_id: string | null
    }
    all_versions: Version[]
}>()

const activeSection = ref<string>('summary')
const exportingGoogleDoc = ref(false)
const googleDocUrl = ref<string | null>(props.proposal.google_drive_id ?? null)
const showEmailModal = ref(false)
const emailForm = ref({
    to_email: props.rfp.contact_email ?? '',
    to_name: props.rfp.contact_name ?? '',
    subject: `Proposal: ${props.rfp.title}`,
    body: `Dear ${props.rfp.contact_name ?? props.rfp.issuing_organization},\n\nPlease find attached our proposal for "${props.rfp.title}". We look forward to the opportunity to work with you.\n\nBest regards,\n${props.rfp.contact_name ?? 'The Zao Team'}`,
    is_test: false,
})
const sendingEmail = ref(false)

const sections = computed(() => [
    { key: 'summary', label: 'Executive Summary', count: null },
    { key: 'content', label: 'Full Proposal', count: null },
    { key: 'pricing', label: 'Pricing', count: props.proposal.pricing_breakdown.length },
    { key: 'requirements', label: 'Requirements', count: props.proposal.requirement_responses.length },
    { key: 'experience', label: 'Experience', count: (props.proposal.case_studies_used.length + props.proposal.testimonials_used.length) || null },
])

const metReqCount = computed(() =>
    props.proposal.requirement_responses.filter(r => r.met !== false).length
)

const totalItems = computed(() =>
    props.proposal.pricing_breakdown.reduce((sum, item) => sum + (item.total ?? item.amount ?? item.unit_price ?? 0), 0)
)

async function exportGoogleDoc() {
    exportingGoogleDoc.value = true
    try {
        const res = await fetch(props.proposal.google_doc_url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '', 'Accept': 'application/json' },
        })
        const data = await res.json()
        if (data.url) {
            googleDocUrl.value = data.url
            window.open(data.url, '_blank')
        }
    } finally {
        exportingGoogleDoc.value = false
    }
}

function sendEmail() {
    sendingEmail.value = true
    router.post(props.proposal.send_email_url, emailForm.value, {
        onSuccess: () => { showEmailModal.value = false },
        onFinish: () => { sendingEmail.value = false },
    })
}

function formatCurrency(val: number | null | undefined) {
    if (!val) return '—'
    return '$' + Number(val).toLocaleString()
}
</script>

<template>
    <AppLayout :title="`Proposal Review — ${rfp.issuing_organization}`">
        <!-- Back nav -->
        <div class="mb-4">
            <Link :href="`/rfp/${rfp.slug}`" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to {{ rfp.issuing_organization }}
            </Link>
        </div>

        <div class="flex gap-6">
            <!-- ── Left sidebar ── -->
            <div class="w-64 shrink-0 space-y-4">
                <!-- Meta card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-3">Proposal v{{ proposal.version }}</div>
                    <div class="font-semibold text-gray-900 dark:text-gray-100 text-sm leading-snug mb-1">{{ rfp.issuing_organization }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ rfp.title }}</div>

                    <div v-if="proposal.total_price" class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                        <div class="text-xs text-gray-400 dark:text-gray-500">Proposed Investment</div>
                        <div class="text-lg font-bold text-blue-600 dark:text-blue-400">{{ formatCurrency(proposal.total_price) }}</div>
                    </div>

                    <div v-if="rfp.submission_deadline" class="mt-2">
                        <div class="text-xs text-gray-400 dark:text-gray-500">Deadline</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ rfp.submission_deadline }}</div>
                    </div>

                    <div v-if="proposal.debate_metadata" class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                        <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">Generated via</div>
                        <div class="flex flex-wrap gap-1">
                            <span v-for="p in proposal.debate_metadata.providers" :key="p"
                                  class="text-xs px-1.5 py-0.5 bg-purple-50 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded font-mono">
                                {{ p }}
                            </span>
                        </div>
                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ proposal.debate_metadata.rounds }}-round debate · {{ Math.round((proposal.debate_metadata.confidence ?? 0) * 100) }}% confidence</div>
                    </div>
                </div>

                <!-- Section nav -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 px-4 pt-3 pb-2">Sections</div>
                    <button v-for="s in sections" :key="s.key"
                            class="w-full text-left px-4 py-2.5 text-sm flex items-center justify-between transition-colors"
                            :class="activeSection === s.key
                                ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 font-medium'
                                : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700/50'"
                            @click="activeSection = s.key">
                        {{ s.label }}
                        <span v-if="s.count" class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded-full px-1.5 py-0.5">{{ s.count }}</span>
                    </button>
                </div>

                <!-- Versions -->
                <div v-if="all_versions.length > 1" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 px-4 pt-3 pb-2">Versions</div>
                    <Link v-for="v in all_versions" :key="v.id" :href="v.review_url"
                          class="block px-4 py-2.5 text-sm transition-colors"
                          :class="v.id === proposal.id
                              ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 font-medium'
                              : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700/50'">
                        <span class="font-medium">v{{ v.version }}</span>
                        <span class="text-gray-400 dark:text-gray-500 ml-1 text-xs">{{ v.created_at }}</span>
                        <span v-if="v.total_price" class="block text-xs text-gray-400 dark:text-gray-500">{{ formatCurrency(v.total_price) }}</span>
                    </Link>
                </div>

                <!-- Actions -->
                <div class="space-y-2">
                    <a :href="proposal.pdf_url" target="_blank"
                       class="btn btn-secondary w-full text-center text-sm">
                        Preview PDF
                    </a>
                    <a :href="proposal.download_url"
                       class="btn btn-secondary w-full text-center text-sm">
                        Download PDF
                    </a>
                    <a :href="proposal.corporate_pdf_url"
                       class="btn btn-secondary w-full text-center text-sm">
                        Corporate PDF (Gov't)
                    </a>
                    <a v-if="googleDocUrl" :href="googleDocUrl" target="_blank"
                       class="btn btn-secondary w-full text-center text-sm">
                        Open Google Doc
                    </a>
                    <button v-else class="btn btn-secondary w-full text-sm"
                            :disabled="exportingGoogleDoc" @click="exportGoogleDoc">
                        {{ exportingGoogleDoc ? 'Creating…' : 'Export to Google Doc' }}
                    </button>
                    <button class="btn btn-primary w-full text-sm" @click="showEmailModal = true">
                        Send to Client
                    </button>
                </div>
            </div>

            <!-- ── Main content ── -->
            <div class="flex-1 min-w-0">
                <!-- Executive Summary -->
                <div v-if="activeSection === 'summary'" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-6">Executive Summary</h2>
                    <div v-if="proposal.executive_summary" class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line text-base">
                        {{ proposal.executive_summary }}
                    </div>
                    <div v-else class="text-gray-400 dark:text-gray-500 italic">No executive summary generated.</div>

                    <div v-if="proposal.review_notes" class="mt-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                        <div class="text-xs font-semibold text-amber-700 dark:text-amber-400 uppercase tracking-wide mb-1">Review Notes</div>
                        <div class="text-sm text-amber-800 dark:text-amber-300">{{ proposal.review_notes }}</div>
                    </div>
                </div>

                <!-- Full Content -->
                <div v-if="activeSection === 'content'" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-6">Full Proposal</h2>

                    <!-- Structured sections if available -->
                    <div v-if="proposal.proposal_sections.length" class="space-y-6">
                        <div v-for="section in proposal.proposal_sections" :key="section.title">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3 pb-2 border-b border-gray-100 dark:border-gray-700">{{ section.title }}</h3>
                            <div class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line">{{ section.content }}</div>
                        </div>
                    </div>

                    <!-- Fallback: full markdown content -->
                    <div v-else-if="proposal.full_content"
                         class="prose prose-gray dark:prose-invert max-w-none text-base"
                         v-html="proposal.full_content.replace(/\n/g, '<br>')">
                    </div>

                    <div v-else class="text-gray-400 dark:text-gray-500 italic">No proposal content generated yet.</div>
                </div>

                <!-- Pricing -->
                <div v-if="activeSection === 'pricing'" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-6">Investment Summary</h2>

                    <div v-if="proposal.pricing_breakdown.length">
                        <table class="w-full mb-6">
                            <thead>
                                <tr class="border-b-2 border-gray-200 dark:border-gray-600">
                                    <th class="text-left py-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-semibold w-2/5">Item</th>
                                    <th class="text-right py-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-semibold w-1/5">Amount</th>
                                    <th class="text-left py-3 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-semibold pl-4 w-2/5">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, i) in proposal.pricing_breakdown" :key="i"
                                    class="border-b border-gray-100 dark:border-gray-700">
                                    <td class="py-3 font-medium text-gray-900 dark:text-gray-100">{{ item.item ?? item.name }}</td>
                                    <td class="py-3 text-right font-mono text-gray-700 dark:text-gray-300">{{ formatCurrency(item.total ?? item.amount ?? item.unit_price) }}</td>
                                    <td class="py-3 text-sm text-gray-500 dark:text-gray-400 pl-4">{{ item.description }}</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-gray-200 dark:border-gray-600">
                                    <td class="py-3 font-bold text-gray-900 dark:text-gray-100">Total Investment</td>
                                    <td class="py-3 text-right font-mono font-bold text-lg text-blue-600 dark:text-blue-400">{{ formatCurrency(proposal.total_price) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div v-else class="text-gray-400 dark:text-gray-500 italic">No pricing breakdown available.</div>
                </div>

                <!-- Requirements -->
                <div v-if="activeSection === 'requirements'" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Requirements Compliance</h2>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ metReqCount }} / {{ proposal.requirement_responses.length }} addressed</span>
                    </div>

                    <div v-if="proposal.requirement_responses.length" class="space-y-3">
                        <div v-for="(rr, i) in proposal.requirement_responses" :key="i"
                             class="p-4 rounded-lg border"
                             :class="rr.met === false
                                 ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20'
                                 : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30'">
                            <div class="flex items-start gap-3">
                                <div class="shrink-0 mt-0.5">
                                    <div v-if="rr.met !== false" class="w-5 h-5 rounded-full bg-emerald-100 dark:bg-emerald-900/50 flex items-center justify-center">
                                        <svg class="w-3 h-3 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </div>
                                    <div v-else class="w-5 h-5 rounded-full bg-amber-100 dark:bg-amber-900/50 flex items-center justify-center">
                                        <svg class="w-3 h-3 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"/>
                                        </svg>
                                    </div>
                                </div>
                                <div>
                                    <div class="font-semibold text-sm text-gray-900 dark:text-gray-100">{{ rr.requirement }}</div>
                                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ rr.response }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-gray-400 dark:text-gray-500 italic">No requirement responses mapped.</div>
                </div>

                <!-- Experience -->
                <div v-if="activeSection === 'experience'" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-8">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-6">Relevant Experience</h2>

                    <div v-if="proposal.case_studies_used.length" class="mb-8">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">Case Studies Referenced</h3>
                        <ul class="space-y-2">
                            <li v-for="cs in proposal.case_studies_used" :key="cs"
                                class="flex items-start gap-2 text-gray-700 dark:text-gray-300 text-sm">
                                <span class="shrink-0 w-1.5 h-1.5 rounded-full bg-blue-500 mt-2"></span>
                                {{ cs }}
                            </li>
                        </ul>
                    </div>

                    <div v-if="proposal.testimonials_used.length">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">Testimonials</h3>
                        <div v-for="t in proposal.testimonials_used" :key="t"
                             class="border-l-4 border-blue-400 dark:border-blue-600 pl-4 py-2 mb-4 italic text-gray-600 dark:text-gray-400 text-sm">
                            "{{ t }}"
                        </div>
                    </div>

                    <div v-if="!proposal.case_studies_used.length && !proposal.testimonials_used.length"
                         class="text-gray-400 dark:text-gray-500 italic">
                        No case studies or testimonials cited in this proposal.
                    </div>
                </div>
            </div>
        </div>

        <!-- Send email modal -->
        <Teleport to="body">
            <div v-if="showEmailModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Send Proposal</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-medium text-gray-600 dark:text-gray-400 block mb-1">To Email</label>
                                <input v-model="emailForm.to_email" type="email" class="input w-full text-sm" placeholder="contact@org.com"/>
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-600 dark:text-gray-400 block mb-1">To Name</label>
                                <input v-model="emailForm.to_name" type="text" class="input w-full text-sm" placeholder="Jane Smith"/>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-400 block mb-1">Subject</label>
                            <input v-model="emailForm.subject" type="text" class="input w-full text-sm"/>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-400 block mb-1">Message</label>
                            <textarea v-model="emailForm.body" rows="5" class="input w-full text-sm resize-none"></textarea>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                            <input v-model="emailForm.is_test" type="checkbox" class="rounded"/>
                            Send as test (to my email, not the client)
                        </label>
                    </div>
                    <div class="p-6 border-t border-gray-200 dark:border-gray-700 flex gap-3 justify-end">
                        <button class="btn btn-secondary" @click="showEmailModal = false">Cancel</button>
                        <button class="btn btn-primary" :disabled="sendingEmail" @click="sendEmail">
                            {{ sendingEmail ? 'Sending…' : emailForm.is_test ? 'Send Test' : 'Send to Client' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
