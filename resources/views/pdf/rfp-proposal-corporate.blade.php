{{--
    RFP Proposal — Corporate/Government PDF variant
    Traditional Times New Roman formal style for government RFPs.
    TailwindPdf::view('pdf.rfp-proposal-corporate', ['proposal', 'opportunity', 'company'])->save(...)
--}}

@php
    $sections = $proposal->renderableSections();
    $renderableFullContent = $proposal->renderableFullContent();
    $pricing = $proposal->pricing_breakdown ?? [];
    $caseStudies = $proposal->case_studies_used ?? [];
    $testimonials = $proposal->testimonials_used ?? [];
    $requirementResponses = $proposal->requirement_responses ?? [];
    $totalMet = collect($requirementResponses)->filter(fn($r) => ($r['met'] ?? true) !== false)->count();
    $sectionNum = 1;
@endphp

<style>
    body { font-family: 'Times New Roman', Times, serif; }
    h1, h2, h3, h4 { font-family: 'Times New Roman', Times, serif; }
    .mono { font-family: 'Courier New', Courier, monospace; }
    @page { margin: 1in; }
    .page-break { page-break-before: always; }
</style>

<div style="width: 8.5in; background: #ffffff; color: #000000; font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.6;">

    {{-- ═══════════ COVER PAGE ═══════════ --}}
    <div style="min-height: 9in; display: flex; flex-direction: column; justify-content: space-between; padding: 0.5in 0;">

        {{-- Government-style header --}}
        <div style="text-align: center; border-bottom: 3px double #000; padding-bottom: 24px; margin-bottom: 24px;">
            <div style="font-size: 11pt; letter-spacing: 0.15em; text-transform: uppercase; margin-bottom: 8px;">Request for Proposal Response</div>
            <div style="font-size: 10pt; color: #333;">Submitted in accordance with the requirements set forth by</div>
            <div style="font-size: 14pt; font-weight: bold; margin-top: 8px;">{{ $opportunity->issuing_organization }}</div>
        </div>

        {{-- Main title block --}}
        <div style="text-align: center; padding: 40px 0; flex: 1; display: flex; flex-direction: column; justify-content: center;">
            <div style="font-size: 10pt; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 16px;">Proposal for</div>
            <div style="font-size: 20pt; font-weight: bold; line-height: 1.3; margin-bottom: 24px;">{{ $proposal->title }}</div>
            <div style="width: 3in; border-bottom: 1px solid #000; margin: 0 auto 24px;"></div>
            @if($opportunity->contact_name)
                <div style="font-size: 11pt;">Attention: {{ $opportunity->contact_name }}</div>
            @endif
        </div>

        {{-- Submission details table --}}
        <div style="border: 1px solid #000; padding: 16px; margin-bottom: 32px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 11pt;">
                <tr>
                    <td style="padding: 4px 8px; font-weight: bold; width: 35%; vertical-align: top;">Submitted by:</td>
                    <td style="padding: 4px 8px;">
                        {{ $company['name'] }}<br>
                        @if($company['contact_name'] ?? null){{ $company['contact_name'] }}<br>@endif
                        {{ $company['email'] }}<br>
                        @if($company['phone'] ?? null){{ $company['phone'] }}<br>@endif
                        @if($company['website'] ?? null){{ $company['website'] }}@endif
                    </td>
                </tr>
                <tr style="border-top: 1px solid #ccc;">
                    <td style="padding: 4px 8px; font-weight: bold;">Date of Submission:</td>
                    <td style="padding: 4px 8px;">{{ $proposal->created_at->format('F j, Y') }}</td>
                </tr>
                @if($opportunity->submission_deadline)
                <tr style="border-top: 1px solid #ccc;">
                    <td style="padding: 4px 8px; font-weight: bold;">Submission Deadline:</td>
                    <td style="padding: 4px 8px;">{{ $opportunity->submission_deadline->format('F j, Y') }}</td>
                </tr>
                @endif
                @if($proposal->total_price)
                <tr style="border-top: 1px solid #ccc;">
                    <td style="padding: 4px 8px; font-weight: bold;">Proposed Total Cost:</td>
                    <td style="padding: 4px 8px; font-weight: bold;">${{ number_format($proposal->total_price, 0) }}</td>
                </tr>
                @endif
                <tr style="border-top: 1px solid #ccc;">
                    <td style="padding: 4px 8px; font-weight: bold;">Proposal Reference:</td>
                    <td style="padding: 4px 8px;">Version {{ $proposal->version }}</td>
                </tr>
            </table>
        </div>

        {{-- Confidentiality notice --}}
        <div style="text-align: center; font-size: 9pt; color: #555; border-top: 1px solid #ccc; padding-top: 12px;">
            This proposal is confidential and intended solely for {{ $opportunity->issuing_organization }}.
            Any reproduction or distribution without written consent is prohibited.
            &copy; {{ date('Y') }} {{ $company['name'] }}. All rights reserved.
        </div>
    </div>

    {{-- ═══════════ TABLE OF CONTENTS ═══════════ --}}
    <div class="page-break" style="padding: 0.5in 0;">
        <h2 style="text-align: center; font-size: 14pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 24px;">
            Table of Contents
        </h2>
        <table style="width: 100%; border-collapse: collapse; font-size: 11pt;">
            @php $tocNum = 1; @endphp
            @if($proposal->executive_summary)
            <tr style="border-bottom: 1px dotted #ccc;">
                <td style="padding: 6px 0;">{{ $tocNum++ }}. Executive Summary</td>
                <td style="text-align: right; padding: 6px 0;">3</td>
            </tr>
            @endif
            @foreach($sections as $section)
            <tr style="border-bottom: 1px dotted #ccc;">
                <td style="padding: 6px 0;">{{ $tocNum++ }}. {{ $section['title'] }}</td>
                <td style="text-align: right; padding: 6px 0;">–</td>
            </tr>
            @endforeach
            @if(!empty($sections) === false && $proposal->full_content)
            <tr style="border-bottom: 1px dotted #ccc;">
                <td style="padding: 6px 0;">{{ $tocNum++ }}. Proposed Solution</td>
                <td style="text-align: right; padding: 6px 0;">–</td>
            </tr>
            @endif
            @if(!empty($pricing))
            <tr style="border-bottom: 1px dotted #ccc;">
                <td style="padding: 6px 0;">{{ $tocNum++ }}. Cost Proposal</td>
                <td style="text-align: right; padding: 6px 0;">–</td>
            </tr>
            @endif
            @if(!empty($requirementResponses))
            <tr style="border-bottom: 1px dotted #ccc;">
                <td style="padding: 6px 0;">{{ $tocNum++ }}. Requirements Compliance Matrix</td>
                <td style="text-align: right; padding: 6px 0;">–</td>
            </tr>
            @endif
            @if(!empty($caseStudies) || !empty($testimonials))
            <tr style="border-bottom: 1px dotted #ccc;">
                <td style="padding: 6px 0;">{{ $tocNum++ }}. Relevant Experience & References</td>
                <td style="text-align: right; padding: 6px 0;">–</td>
            </tr>
            @endif
        </table>
    </div>

    {{-- ═══════════ EXECUTIVE SUMMARY ═══════════ --}}
    @if($proposal->executive_summary)
    <div class="page-break" style="padding: 0.25in 0;">
        <h2 style="font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 20px;">
            {{ $sectionNum++ }}. Executive Summary
        </h2>
        <p style="text-align: justify; white-space: pre-line; margin: 0; font-size: 12pt; line-height: 1.8;">{{ $proposal->executive_summary }}</p>
    </div>
    @endif

    {{-- ═══════════ PROPOSAL SECTIONS ═══════════ --}}
    @if(!empty($sections))
        @foreach($sections as $section)
        <div class="page-break" style="padding: 0.25in 0;">
            <h2 style="font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 20px;">
                {{ $sectionNum++ }}. {{ $section['title'] }}
            </h2>
            <div style="text-align: justify; white-space: pre-line; font-size: 12pt; line-height: 1.8;">{{ $section['content'] }}</div>
        </div>
        @endforeach
    @elseif($renderableFullContent)
    <div class="page-break" style="padding: 0.25in 0;">
        <h2 style="font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 20px;">
            {{ $sectionNum++ }}. Proposed Solution
        </h2>
        <div style="font-size: 12pt; line-height: 1.8; text-align: justify;">
            {!! Str::markdown($renderableFullContent) !!}
        </div>
    </div>
    @endif

    {{-- ═══════════ COST PROPOSAL ═══════════ --}}
    @if(!empty($pricing))
    <div class="page-break" style="padding: 0.25in 0;">
        <h2 style="font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 20px;">
            {{ $sectionNum++ }}. Cost Proposal
        </h2>

        <table style="width: 100%; border-collapse: collapse; font-size: 11pt; margin-bottom: 20px;">
            <thead>
                <tr style="background: #f0f0f0; border: 1px solid #000; border-bottom: 2px solid #000;">
                    <th style="text-align: left; padding: 8px 10px; font-weight: bold; width: 40%;">Line Item / Deliverable</th>
                    <th style="text-align: right; padding: 8px 10px; font-weight: bold; width: 20%;">Amount</th>
                    <th style="text-align: left; padding: 8px 10px; font-weight: bold; width: 40%;">Description / Notes</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pricing as $item)
                <tr style="border: 1px solid #ccc;">
                    <td style="padding: 7px 10px;">{{ $item['item'] ?? $item['name'] ?? '' }}</td>
                    <td style="padding: 7px 10px; text-align: right; font-family: 'Courier New', monospace;">${{ number_format($item['total'] ?? $item['amount'] ?? $item['unit_price'] ?? 0, 0) }}</td>
                    <td style="padding: 7px 10px; font-size: 10pt; color: #333;">{{ $item['description'] ?? '' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background: #f0f0f0; border: 2px solid #000;">
                    <td style="padding: 8px 10px; font-weight: bold;">TOTAL PROPOSED COST</td>
                    <td style="padding: 8px 10px; text-align: right; font-weight: bold; font-size: 13pt; font-family: 'Courier New', monospace;">${{ number_format($proposal->total_price, 0) }}</td>
                    <td style="padding: 8px 10px; font-size: 10pt;">All amounts in U.S. Dollars</td>
                </tr>
            </tfoot>
        </table>
    </div>
    @endif

    {{-- ═══════════ COMPLIANCE MATRIX ═══════════ --}}
    @if(!empty($requirementResponses))
    <div class="page-break" style="padding: 0.25in 0;">
        <h2 style="font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 8px;">
            {{ $sectionNum++ }}. Requirements Compliance Matrix
        </h2>
        <p style="font-size: 10pt; color: #555; margin-bottom: 16px;">
            {{ $totalMet }} of {{ count($requirementResponses) }} requirements addressed. Compliant (C) / Partially Compliant (PC).
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 10pt;">
            <thead>
                <tr style="background: #f0f0f0; border: 1px solid #000;">
                    <th style="padding: 7px 8px; text-align: center; width: 5%; border-right: 1px solid #ccc;">#</th>
                    <th style="padding: 7px 8px; text-align: left; width: 35%; border-right: 1px solid #ccc;">Requirement</th>
                    <th style="padding: 7px 8px; text-align: left; width: 50%; border-right: 1px solid #ccc;">Our Response</th>
                    <th style="padding: 7px 8px; text-align: center; width: 10%;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($requirementResponses as $i => $rr)
                <tr style="border: 1px solid #ccc; {{ $i % 2 === 0 ? '' : 'background: #fafafa;' }} page-break-inside: avoid;">
                    <td style="padding: 7px 8px; text-align: center; border-right: 1px solid #ccc; font-weight: bold;">{{ $i + 1 }}</td>
                    <td style="padding: 7px 8px; border-right: 1px solid #ccc; vertical-align: top;">{{ $rr['requirement'] ?? '' }}</td>
                    <td style="padding: 7px 8px; border-right: 1px solid #ccc; font-size: 9.5pt; vertical-align: top;">{{ $rr['response'] ?? '' }}</td>
                    <td style="padding: 7px 8px; text-align: center; font-weight: bold; {{ ($rr['met'] ?? true) !== false ? 'color: #166534;' : 'color: #92400e;' }}">
                        {{ ($rr['met'] ?? true) !== false ? 'C' : 'PC' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- ═══════════ EXPERIENCE ═══════════ --}}
    @if(!empty($caseStudies) || !empty($testimonials))
    <div class="page-break" style="padding: 0.25in 0;">
        <h2 style="font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 20px;">
            {{ $sectionNum++ }}. Relevant Experience &amp; References
        </h2>

        @if(!empty($caseStudies))
        <h3 style="font-size: 12pt; font-weight: bold; margin-bottom: 12px;">Relevant Projects</h3>
        <ul style="margin: 0 0 20px 20px; padding: 0; font-size: 11pt; line-height: 1.8;">
            @foreach($caseStudies as $cs)
            <li style="margin-bottom: 4px;">{{ $cs }}</li>
            @endforeach
        </ul>
        @endif

        @if(!empty($testimonials))
        <h3 style="font-size: 12pt; font-weight: bold; margin-bottom: 12px;">Client References</h3>
        @foreach($testimonials as $testimonial)
        <blockquote style="border-left: 3px solid #000; padding-left: 16px; margin: 0 0 16px 0; font-style: italic; font-size: 11pt; color: #333;">
            &ldquo;{{ $testimonial }}&rdquo;
        </blockquote>
        @endforeach
        @endif
    </div>
    @endif

    {{-- ═══════════ CERTIFICATION / SIGNATURE PAGE ═══════════ --}}
    <div class="page-break" style="padding: 0.25in 0;">
        <h2 style="font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 20px;">
            Certification
        </h2>
        <p style="font-size: 11pt; line-height: 1.8; margin-bottom: 32px;">
            The undersigned hereby certifies that the information contained in this proposal is accurate and complete,
            and that {{ $company['name'] }} has the capability and intent to perform the work as described herein
            at the price stated, subject to the terms and conditions of the solicitation.
        </p>

        <table style="width: 100%; font-size: 11pt; margin-bottom: 48px;">
            <tr>
                <td style="width: 50%; padding-right: 32px; vertical-align: top;">
                    <div style="border-bottom: 1px solid #000; margin-bottom: 4px; padding-bottom: 32px;"></div>
                    <div style="font-size: 10pt; color: #555;">Authorized Signature</div>
                </td>
                <td style="width: 50%; padding-left: 32px; vertical-align: top;">
                    <div style="border-bottom: 1px solid #000; margin-bottom: 4px; padding-bottom: 32px;"></div>
                    <div style="font-size: 10pt; color: #555;">Date</div>
                </td>
            </tr>
            <tr style="margin-top: 24px;">
                <td style="padding-right: 32px; padding-top: 20px;">
                    <div style="border-bottom: 1px solid #000; margin-bottom: 4px; padding-bottom: 20px;">
                        {{ $company['contact_name'] ?? $company['name'] }}
                    </div>
                    <div style="font-size: 10pt; color: #555;">Printed Name</div>
                </td>
                <td style="padding-left: 32px; padding-top: 20px;">
                    <div style="border-bottom: 1px solid #000; margin-bottom: 4px; padding-bottom: 20px;">
                        {{ $company['name'] }}
                    </div>
                    <div style="font-size: 10pt; color: #555;">Company Name</div>
                </td>
            </tr>
        </table>

        <div style="text-align: center; font-size: 9pt; color: #888; border-top: 1px solid #ccc; padding-top: 12px; margin-top: 24px;">
            {{ $company['name'] }} &bull; {{ $company['email'] }}
            @if($company['phone'] ?? null) &bull; {{ $company['phone'] }} @endif
            @if($company['website'] ?? null) &bull; {{ $company['website'] }} @endif
        </div>
    </div>

</div>
