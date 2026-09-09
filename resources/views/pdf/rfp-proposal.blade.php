{{--
    RFP Proposal PDF Template — Beautiful redesign
    TailwindPdf::view('pdf.rfp-proposal', ['proposal', 'opportunity', 'company'])->save(...)
--}}

@php
    $accent = '#1e40af';   // deep blue
    $accentLight = '#dbeafe';
    $sections = $proposal->renderableSections();
    $renderableFullContent = $proposal->renderableFullContent();
    $pricing = $proposal->pricing_breakdown ?? [];
    $caseStudies = $proposal->case_studies_used ?? [];
    $testimonials = $proposal->testimonials_used ?? [];
    $requirementResponses = $proposal->requirement_responses ?? [];
    $totalMet = collect($requirementResponses)->filter(fn($r) => ($r['met'] ?? true) !== false)->count();
@endphp

<div class="w-[8.5in] bg-white" style="font-family: 'Georgia', serif;">

    {{-- ═══════════ COVER PAGE ═══════════ --}}
    <div class="min-h-[11in] flex flex-col" style="background: #0f172a;">

        {{-- Top accent bar --}}
        <div class="h-1.5 w-full" style="background: linear-gradient(90deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);"></div>

        {{-- Company name header --}}
        <div class="flex justify-between items-start px-14 pt-12">
            <div>
                <div class="text-2xl font-bold tracking-tight" style="color: #60a5fa; font-family: 'Arial', sans-serif;">
                    {{ $company['name'] }}
                </div>
                @if($company['website'] ?? null)
                    <div class="text-sm mt-1" style="color: #64748b;">{{ $company['website'] }}</div>
                @endif
            </div>
            <div class="text-right" style="font-family: 'Arial', sans-serif;">
                <div class="text-xs uppercase tracking-[0.2em]" style="color: #475569;">Proposal</div>
                <div class="text-sm mt-1" style="color: #64748b;">Version {{ $proposal->version }}</div>
                <div class="text-sm" style="color: #64748b;">{{ $proposal->created_at->format('F j, Y') }}</div>
            </div>
        </div>

        {{-- Hero text block --}}
        <div class="flex-1 flex flex-col justify-center px-14 py-16">
            <div class="w-16 h-0.5 mb-10" style="background: #1e40af;"></div>

            <div class="text-sm uppercase tracking-[0.25em] mb-4" style="color: #475569; font-family: 'Arial', sans-serif;">
                Submitted to
            </div>
            <div class="text-4xl font-bold leading-tight mb-3" style="color: #f8fafc;">
                {{ $opportunity->issuing_organization }}
            </div>
            @if($opportunity->contact_name)
                <div class="text-lg mb-10" style="color: #94a3b8;">Attn: {{ $opportunity->contact_name }}</div>
            @endif

            <div class="w-full h-px mb-10" style="background: #1e293b;"></div>

            <div class="text-xl font-light leading-relaxed" style="color: #cbd5e1; max-width: 540px;">
                {{ $proposal->title }}
            </div>
        </div>

        {{-- Cover footer strip --}}
        <div class="px-14 py-8 border-t" style="border-color: #1e293b;">
            <div class="grid gap-8" style="grid-template-columns: {{ $opportunity->submission_deadline ? '1fr 1fr 1fr' : ($proposal->total_price ? '1fr 1fr' : '1fr') }}">
                @if($opportunity->submission_deadline)
                    <div>
                        <div class="text-xs uppercase tracking-widest mb-2" style="color: #475569; font-family: 'Arial', sans-serif;">Submission Deadline</div>
                        <div class="text-base font-semibold" style="color: #e2e8f0;">{{ $opportunity->submission_deadline->format('F j, Y') }}</div>
                    </div>
                @endif
                @if($proposal->total_price)
                    <div>
                        <div class="text-xs uppercase tracking-widest mb-2" style="color: #475569; font-family: 'Arial', sans-serif;">Proposed Investment</div>
                        <div class="text-base font-semibold" style="color: #60a5fa;">${{ number_format($proposal->total_price, 0) }}</div>
                    </div>
                @endif
                <div>
                    <div class="text-xs uppercase tracking-widest mb-2" style="color: #475569; font-family: 'Arial', sans-serif;">Prepared by</div>
                    <div class="text-base font-semibold" style="color: #e2e8f0;">{{ $company['contact_name'] ?? $company['name'] }}</div>
                    <div class="text-sm" style="color: #64748b;">{{ $company['email'] }}</div>
                </div>
            </div>
        </div>

        {{-- Bottom accent --}}
        <div class="h-1" style="background: linear-gradient(90deg, #1e40af, #3b82f6);"></div>
    </div>

    {{-- ═══════════ EXECUTIVE SUMMARY ═══════════ --}}
    @if($proposal->executive_summary)
    <div class="p-14 break-before-page" style="background: #ffffff;">
        {{-- Section label --}}
        <div class="flex items-center gap-4 mb-10">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white text-sm font-bold" style="background: {{ $accent }}; font-family: 'Arial', sans-serif;">01</div>
            <div>
                <div class="text-xs uppercase tracking-widest mb-0.5" style="color: #94a3b8; font-family: 'Arial', sans-serif;">Section One</div>
                <h2 class="text-2xl font-bold" style="color: #0f172a; font-family: 'Arial', sans-serif;">Executive Summary</h2>
            </div>
        </div>

        <div class="text-base leading-[1.9] whitespace-pre-line" style="color: #334155;">{{ $proposal->executive_summary }}</div>
    </div>
    @endif

    {{-- ═══════════ PROPOSAL SECTIONS (or full_content) ═══════════ --}}
    @php $sectionNum = 2; @endphp

    @if(!empty($sections))
        @foreach($sections as $section)
        <div class="px-14 py-12 break-before-page" style="background: {{ $loop->even ? '#f8fafc' : '#ffffff' }}">
            <div class="flex items-center gap-4 mb-8">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white text-sm font-bold" style="background: {{ $accent }}; font-family: 'Arial', sans-serif;">{{ str_pad($sectionNum++, 2, '0', STR_PAD_LEFT) }}</div>
                <div>
                    <div class="text-xs uppercase tracking-widest mb-0.5" style="color: #94a3b8; font-family: 'Arial', sans-serif;">Section {{ $sectionNum - 1 }}</div>
                    <h2 class="text-2xl font-bold" style="color: #0f172a; font-family: 'Arial', sans-serif;">{{ $section['title'] }}</h2>
                </div>
            </div>
            <div class="text-base leading-[1.9] whitespace-pre-line" style="color: #334155;">{{ $section['content'] }}</div>
        </div>
        @endforeach
    @elseif($renderableFullContent)
    <div class="px-14 py-12 break-before-page" style="background: #ffffff;">
        <div class="flex items-center gap-4 mb-8">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white text-sm font-bold" style="background: {{ $accent }}; font-family: 'Arial', sans-serif;">02</div>
            <div>
                <div class="text-xs uppercase tracking-widest mb-0.5" style="color: #94a3b8; font-family: 'Arial', sans-serif;">Section Two</div>
                <h2 class="text-2xl font-bold" style="color: #0f172a; font-family: 'Arial', sans-serif;">Our Proposal</h2>
            </div>
        </div>
        <div class="text-base leading-[1.9]"
            style="color: #334155;"
            x-prose>
            {!! Str::markdown($renderableFullContent) !!}
        </div>
    </div>
    @endif

    {{-- ═══════════ INVESTMENT SUMMARY ═══════════ --}}
    @if(!empty($pricing))
    <div class="px-14 py-12 break-before-page" style="background: #f8fafc;">
        <div class="flex items-center gap-4 mb-10">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white text-sm font-bold" style="background: {{ $accent }}; font-family: 'Arial', sans-serif;">{{ str_pad($sectionNum++, 2, '0', STR_PAD_LEFT) }}</div>
            <div>
                <div class="text-xs uppercase tracking-widest mb-0.5" style="color: #94a3b8; font-family: 'Arial', sans-serif;">Section {{ $sectionNum - 1 }}</div>
                <h2 class="text-2xl font-bold" style="color: #0f172a; font-family: 'Arial', sans-serif;">Investment Summary</h2>
            </div>
        </div>

        <table class="w-full mb-6" style="border-collapse: collapse;">
            <thead>
                <tr style="background: #0f172a;">
                    <th class="text-left py-3 px-4 text-xs uppercase tracking-widest font-semibold w-2/5" style="color: #cbd5e1; font-family: 'Arial', sans-serif;">Deliverable</th>
                    <th class="text-right py-3 px-4 text-xs uppercase tracking-widest font-semibold w-1/5" style="color: #cbd5e1; font-family: 'Arial', sans-serif;">Amount</th>
                    <th class="text-left py-3 px-4 text-xs uppercase tracking-widest font-semibold w-2/5" style="color: #cbd5e1; font-family: 'Arial', sans-serif;">Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pricing as $i => $item)
                <tr style="background: {{ $i % 2 === 0 ? '#ffffff' : '#f1f5f9' }}; border-bottom: 1px solid #e2e8f0;">
                    <td class="py-3.5 px-4 font-semibold text-sm" style="color: #1e293b;">{{ $item['item'] ?? $item['name'] ?? '' }}</td>
                    <td class="py-3.5 px-4 text-right font-mono text-sm font-semibold" style="color: #1e40af;">${{ number_format($item['total'] ?? $item['amount'] ?? $item['unit_price'] ?? 0, 0) }}</td>
                    <td class="py-3.5 px-4 text-sm" style="color: #64748b;">{{ $item['description'] ?? '' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background: #0f172a;">
                    <td class="py-4 px-4 font-bold text-sm" style="color: #f8fafc; font-family: 'Arial', sans-serif;">TOTAL INVESTMENT</td>
                    <td class="py-4 px-4 text-right font-mono font-bold text-lg" style="color: #60a5fa;">${{ number_format($proposal->total_price, 0) }}</td>
                    <td class="py-4 px-4 text-xs" style="color: #64748b;">All amounts in USD</td>
                </tr>
            </tfoot>
        </table>
    </div>
    @endif

    {{-- ═══════════ REQUIREMENTS COMPLIANCE ═══════════ --}}
    @if(!empty($requirementResponses))
    <div class="px-14 py-12 break-before-page" style="background: #ffffff;">
        <div class="flex items-center justify-between mb-10">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white text-sm font-bold" style="background: {{ $accent }}; font-family: 'Arial', sans-serif;">{{ str_pad($sectionNum++, 2, '0', STR_PAD_LEFT) }}</div>
                <div>
                    <div class="text-xs uppercase tracking-widest mb-0.5" style="color: #94a3b8; font-family: 'Arial', sans-serif;">Section {{ $sectionNum - 1 }}</div>
                    <h2 class="text-2xl font-bold" style="color: #0f172a; font-family: 'Arial', sans-serif;">Requirements Compliance</h2>
                </div>
            </div>
            <div class="text-right">
                <div class="text-3xl font-bold" style="color: #16a34a;">{{ $totalMet }}/{{ count($requirementResponses) }}</div>
                <div class="text-xs" style="color: #64748b; font-family: 'Arial', sans-serif;">Requirements Addressed</div>
            </div>
        </div>

        <div class="space-y-3">
            @foreach($requirementResponses as $rr)
            <div class="p-4 rounded-lg break-inside-avoid" style="background: {{ ($rr['met'] ?? true) !== false ? '#f0fdf4' : '#fffbeb' }}; border: 1px solid {{ ($rr['met'] ?? true) !== false ? '#bbf7d0' : '#fde68a' }};">
                <div class="flex items-start gap-3">
                    <div class="shrink-0 mt-0.5 w-5 h-5 rounded-full flex items-center justify-center"
                         style="background: {{ ($rr['met'] ?? true) !== false ? '#16a34a' : '#d97706' }};">
                        @if(($rr['met'] ?? true) !== false)
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        @else
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01"/></svg>
                        @endif
                    </div>
                    <div>
                        <div class="font-semibold text-sm mb-1" style="color: #0f172a; font-family: 'Arial', sans-serif;">{{ $rr['requirement'] }}</div>
                        <div class="text-sm leading-relaxed" style="color: #475569;">{{ $rr['response'] }}</div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ═══════════ EXPERIENCE & SOCIAL PROOF ═══════════ --}}
    @if(!empty($caseStudies) || !empty($testimonials))
    <div class="px-14 py-12 break-before-page" style="background: #f8fafc;">
        <div class="flex items-center gap-4 mb-10">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white text-sm font-bold" style="background: {{ $accent }}; font-family: 'Arial', sans-serif;">{{ str_pad($sectionNum++, 2, '0', STR_PAD_LEFT) }}</div>
            <div>
                <div class="text-xs uppercase tracking-widest mb-0.5" style="color: #94a3b8; font-family: 'Arial', sans-serif;">Section {{ $sectionNum - 1 }}</div>
                <h2 class="text-2xl font-bold" style="color: #0f172a; font-family: 'Arial', sans-serif;">Relevant Experience</h2>
            </div>
        </div>

        @if(!empty($caseStudies))
        <div class="mb-10">
            <h3 class="text-sm uppercase tracking-widest font-semibold mb-4" style="color: #64748b; font-family: 'Arial', sans-serif;">Case Studies</h3>
            <div class="grid grid-cols-2 gap-3">
                @foreach($caseStudies as $cs)
                <div class="p-4 rounded-lg border-l-4" style="background: #ffffff; border-left-color: {{ $accent }};">
                    <div class="text-sm font-medium" style="color: #1e293b;">{{ $cs }}</div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        @if(!empty($testimonials))
        <div>
            <h3 class="text-sm uppercase tracking-widest font-semibold mb-4" style="color: #64748b; font-family: 'Arial', sans-serif;">Client Testimonials</h3>
            @foreach($testimonials as $testimonial)
            <div class="p-5 mb-4 rounded-lg" style="background: #ffffff; border: 1px solid #e2e8f0;">
                <div class="text-3xl mb-2" style="color: #bfdbfe; line-height: 1;">&ldquo;</div>
                <div class="text-base italic leading-relaxed mb-3" style="color: #475569;">{{ $testimonial }}</div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif

    {{-- ═══════════ NEXT STEPS / SIGN-OFF ═══════════ --}}
    <div class="px-14 py-12 break-before-page" style="background: #0f172a;">
        <div class="h-0.5 w-16 mb-10" style="background: #1e40af;"></div>
        <h2 class="text-3xl font-bold mb-4" style="color: #f8fafc; font-family: 'Arial', sans-serif;">Let's Build Something Great</h2>
        <div class="text-base leading-[1.9] mb-10" style="color: #94a3b8;">
            We are excited about this opportunity and confident in our ability to deliver exceptional results
            for {{ $opportunity->issuing_organization }}. We would welcome the chance to discuss this proposal
            in detail and answer any questions you may have.
        </div>

        <div class="p-6 rounded-xl" style="background: #1e293b; border: 1px solid #334155;">
            <div class="font-bold text-base mb-4" style="color: #60a5fa; font-family: 'Arial', sans-serif;">{{ $company['name'] }}</div>
            <div class="grid grid-cols-2 gap-3 text-sm" style="color: #94a3b8;">
                @if($company['contact_name'] ?? null)
                    <div><span style="color: #64748b;">Contact:</span> {{ $company['contact_name'] }}</div>
                @endif
                <div><span style="color: #64748b;">Email:</span> {{ $company['email'] }}</div>
                @if($company['phone'] ?? null)
                    <div><span style="color: #64748b;">Phone:</span> {{ $company['phone'] }}</div>
                @endif
                @if($company['website'] ?? null)
                    <div><span style="color: #64748b;">Web:</span> {{ $company['website'] }}</div>
                @endif
            </div>
        </div>

        <div class="mt-12 pt-6 border-t text-center text-xs" style="border-color: #1e293b; color: #334155;">
            Confidential &mdash; Prepared exclusively for {{ $opportunity->issuing_organization }} &mdash; &copy; {{ date('Y') }} {{ $company['name'] }}
        </div>
    </div>

</div>
