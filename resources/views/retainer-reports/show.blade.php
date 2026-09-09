<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Retainer Report — {{ $client->name }} — {{ $start->format('F Y') }}</title>
    <style>
        :root {
            --bg: #fbf8f3;
            --surface: #f4efe6;
            --rule: #e2dccf;
            --rule-strong: #c6bda9;
            --ink: #14110b;
            --ink-soft: #443d33;
            --ink-faded: #7c7263;
            --accent: #b6802c;
            --accent-soft: #f0e3c5;
            --accent-deep: #8a5e1c;
            --success: #4f7a3a;
            --warn: #b86a1d;
            --danger: #a23a2a;

            --font-display: "Iowan Old Style", "Palatino Linotype", Palatino, "Hoefler Text", Georgia, serif;
            --font-body: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        /* @page margin must be 0 for the background to bleed to the paper
           edge. Inside content is offset via .page padding below. */
        @@page { size: Letter; margin: 0; }

        * {
            box-sizing: border-box;
            /* Force Chrome to print background colors when generating PDFs.
               Without this the cream surface, status pills, and narrative
               card background all drop to white in print/PDF output. */
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color-adjust: exact;
        }

        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: var(--font-body);
            font-size: 13px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            /* Belt-and-suspenders: Chrome's PDF renderer ignores body
               backgrounds on the very first page unless they're declared
               here explicitly too. */
            background-color: #fbf8f3;
        }

        .page {
            max-width: 880px;
            margin: 0 auto;
            /* Painting the background on .page guarantees Chrome includes
               it in the PDF — body backgrounds get treated as "page tint"
               and dropped during PDF rasterisation. */
            background: var(--bg);
            /* Web: comfortable internal padding. PDF: matches what @page
               margin used to provide so content doesn't jam against the
               paper edge. */
            padding: 0.55in 0.6in 0.5in 0.6in;
        }

        /* ───────────── Masthead ───────────── */
        .masthead {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 24px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--rule-strong);
            margin-bottom: 36px;
        }
        .masthead-left {
            flex: 1 1 auto;
            min-width: 0;
        }
        .eyebrow {
            font-family: var(--font-mono);
            font-size: 9.5px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--ink-faded);
            margin: 0 0 6px 0;
        }
        .masthead h1 {
            font-family: var(--font-display);
            font-size: 42px;
            line-height: 1.02;
            font-weight: 700;
            color: var(--ink);
            margin: 0 0 10px 0;
            letter-spacing: -0.02em;
        }
        .masthead .subtitle {
            font-size: 13px;
            color: var(--ink-soft);
            margin: 0;
        }
        .masthead .subtitle strong {
            color: var(--ink);
            font-weight: 600;
        }
        .masthead-right {
            text-align: right;
            flex: 0 0 auto;
        }
        .masthead-right .period-block {
            font-family: var(--font-mono);
            font-size: 10.5px;
            line-height: 1.45;
            color: var(--ink-soft);
        }
        .masthead-right .period-block .label {
            color: var(--ink-faded);
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-size: 9px;
            display: block;
            margin-bottom: 2px;
        }

        /* ───────────── Admin toolbar (admin web view only) ───────────── */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            background: rgba(182, 128, 44, 0.04);
            border: 1px solid var(--rule);
            border-radius: 4px;
            margin-bottom: 28px;
            font-size: 11px;
        }
        .toolbar-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        /* Mobile: stack the two groups vertically and let each take full
           width. Buttons within a group still wrap naturally. */
        @media (max-width: 720px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .toolbar-group { width: 100%; }
            .toolbar-group .toolbar-btn,
            .toolbar-group .toolbar-select { flex: 1 1 auto; min-width: 0; justify-content: center; text-align: center; }
            /* Period nav fits in one row on mobile too — prev/select/next. */
            .toolbar-group:first-child .toolbar-btn { flex: 0 0 auto; }
            .toolbar-group:first-child .toolbar-select { flex: 1 1 auto; }
        }
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 11px;
            background: var(--bg);
            border: 1px solid var(--rule-strong);
            color: var(--ink-soft);
            font-family: var(--font-body);
            font-size: 11px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border-radius: 3px;
            transition: background 0.15s, color 0.15s;
        }
        .toolbar-btn:hover { background: var(--surface); color: var(--ink); }
        .toolbar-btn.is-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: var(--bg);
        }
        .toolbar-btn.is-primary:hover { background: var(--accent-deep); border-color: var(--accent-deep); }
        .toolbar form { display: inline; margin: 0; padding: 0; }
        .toolbar-select {
            padding: 5px 28px 5px 11px;
            background: var(--bg);
            border: 1px solid var(--rule-strong);
            color: var(--ink);
            font-family: var(--font-body);
            font-size: 11px;
            font-weight: 600;
            border-radius: 3px;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'><path d='M2 4l3 3 3-3' stroke='%23443d33' stroke-width='1.4' fill='none' stroke-linecap='round'/></svg>");
            background-repeat: no-repeat;
            background-position: right 8px center;
        }
        .toolbar-select:hover { background-color: var(--surface); }

        /* Refresh-job status banner (admin web view only) */
        .refresh-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            margin-bottom: 12px;
            border: 1px solid var(--rule-strong);
            border-radius: 4px;
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--ink-soft);
            background: rgba(182, 128, 44, 0.06);
        }
        .refresh-banner--done { background: rgba(74, 124, 89, 0.08); }
        .refresh-banner--error { background: rgba(160, 60, 48, 0.08); }
        .refresh-spinner {
            display: inline-block;
            animation: refresh-spin 1.2s linear infinite;
        }
        @keyframes refresh-spin { to { transform: rotate(360deg); } }
        .toolbar-btn:disabled {
            opacity: 0.5;
            cursor: default;
            pointer-events: none;
        }

        /* ───────────── Section headers ───────────── */
        section { margin-bottom: 36px; }
        section:last-of-type { margin-bottom: 24px; }

        .section-head {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 14px;
            padding-bottom: 6px;
        }
        .section-head .number {
            font-family: var(--font-mono);
            font-size: 9.5px;
            color: var(--ink-faded);
            letter-spacing: 0.12em;
        }
        .section-head h2 {
            font-family: var(--font-display);
            font-size: 24px;
            font-weight: 700;
            color: var(--ink);
            margin: 0;
            letter-spacing: -0.015em;
        }
        .section-sub {
            font-size: 11.5px;
            color: var(--ink-soft);
            margin: 0 0 16px 0;
            max-width: 65ch;
        }

        /* ───────────── Narrative (lead) ───────────── */
        .narrative {
            padding: 24px 26px;
            background: var(--surface);
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .narrative .value-summary {
            font-family: var(--font-display);
            font-size: 21px;
            line-height: 1.4;
            font-weight: 500;
            color: var(--ink);
            margin: 0 0 26px 0;
            max-width: 60ch;
        }
        .narrative .narrative-total {
            font-family: var(--font-mono);
            font-size: 10.5px;
            color: var(--ink-faded);
            letter-spacing: 0.05em;
        }
        .narrative .narrative-total strong {
            color: var(--ink);
            font-weight: 600;
        }

        .topic-list { margin-top: 18px; }
        .topic {
            padding: 14px 0;
            border-top: 1px solid var(--rule);
            display: grid;
            grid-template-columns: 1fr auto;
            grid-column-gap: 18px;
            align-items: start;
        }
        .topic:first-child { border-top: none; padding-top: 4px; }
        .topic-body { min-width: 0; }
        .topic-title {
            font-family: var(--font-display);
            font-size: 17px;
            font-weight: 600;
            color: var(--ink);
            margin: 0 0 5px 0;
            letter-spacing: -0.005em;
        }
        .topic-summary {
            font-size: 13px;
            color: var(--ink-soft);
            margin: 0;
            max-width: 62ch;
        }
        .topic-meta {
            text-align: right;
            font-family: var(--font-mono);
            white-space: nowrap;
        }
        .topic-hours {
            font-size: 20px;
            font-weight: 600;
            color: var(--ink);
            letter-spacing: -0.015em;
        }
        .topic-hours .unit { font-size: 11px; color: var(--ink-faded); margin-left: 2px; }
        .topic-status {
            display: inline-block;
            margin-top: 5px;
            font-family: var(--font-body);
            font-size: 9.5px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 2px;
            font-weight: 600;
        }
        .topic-status.completed { background: rgba(79, 122, 58, 0.14); color: var(--success); }
        .topic-status.in_progress { background: rgba(184, 106, 29, 0.16); color: var(--warn); }
        .topic-status.incomplete { background: rgba(162, 58, 42, 0.14); color: var(--danger); }
        .topic-status.discussion_only { background: rgba(92, 84, 71, 0.10); color: var(--ink-soft); }

        /* ───────────── Hours panel ───────────── */
        .hours-panel {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 32px;
            align-items: end;
            padding-bottom: 22px;
            border-bottom: 1px solid var(--rule);
            margin-bottom: 22px;
        }
        .hours-main .hours-eyebrow {
            font-family: var(--font-mono);
            font-size: 9.5px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--ink-faded);
            margin-bottom: 8px;
        }
        .hours-main .hours-number {
            font-family: var(--font-mono);
            font-size: 56px;
            font-weight: 600;
            line-height: 1;
            color: var(--ink);
            letter-spacing: -0.03em;
        }
        .hours-main .hours-number .of {
            font-size: 18px;
            color: var(--ink-faded);
            margin-left: 8px;
            font-weight: 400;
        }
        .hours-main .hours-pct {
            font-family: var(--font-mono);
            font-size: 11.5px;
            color: var(--ink-soft);
            margin-top: 6px;
        }
        .hours-main .hours-pct.is-over { color: var(--danger); font-weight: 600; }

        .bar {
            height: 6px;
            background: var(--rule);
            border-radius: 0;
            overflow: hidden;
            margin-top: 14px;
            position: relative;
        }
        .bar-fill {
            height: 100%;
            background: var(--accent);
            transition: width 0.4s ease-out;
        }
        .bar-fill.is-over { background: var(--danger); }
        .bar-marker {
            position: absolute;
            top: -3px;
            bottom: -3px;
            width: 1px;
            background: var(--ink-faded);
        }

        .hours-meta {
            text-align: right;
            font-family: var(--font-body);
        }
        .hours-meta .retainer-amount {
            font-family: var(--font-mono);
            font-size: 22px;
            font-weight: 500;
            color: var(--ink);
            letter-spacing: -0.01em;
        }
        .hours-meta .retainer-label {
            font-size: 10.5px;
            color: var(--ink-faded);
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-top: 4px;
        }

        /* ───────────── Breakdown strip ───────────── */
        .breakdown {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
        }
        .breakdown-item .label {
            font-family: var(--font-mono);
            font-size: 9.5px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-faded);
            margin-bottom: 4px;
        }
        .breakdown-item .value {
            font-family: var(--font-mono);
            font-size: 18px;
            font-weight: 500;
            color: var(--ink);
            letter-spacing: -0.005em;
        }
        .breakdown-item .value .unit { color: var(--ink-faded); font-size: 12px; margin-left: 2px; }
        .breakdown-item .sub {
            font-size: 10.5px;
            color: var(--ink-soft);
            margin-top: 2px;
        }
        .estimated-flag {
            font-size: 9.5px;
            color: var(--warn);
            margin-left: 6px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-weight: 600;
        }

        /* ───────────── Estimation detail (when source = estimated) ───────────── */
        .estimation {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            padding: 20px 0;
        }
        .estimation-cell .label {
            font-family: var(--font-mono);
            font-size: 9.5px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-faded);
            margin-bottom: 6px;
        }
        .estimation-cell .value {
            font-family: var(--font-mono);
            font-size: 20px;
            font-weight: 500;
            color: var(--ink);
            letter-spacing: -0.01em;
            margin-bottom: 8px;
        }
        .estimation-cell .value .unit { color: var(--ink-faded); font-size: 11px; margin-left: 2px; }
        .estimation-cell .detail {
            font-size: 11px;
            color: var(--ink-soft);
            line-height: 1.6;
        }
        .estimation-cell .detail strong { color: var(--ink); font-weight: 600; }
        .estimation-cell .detail .sub { color: var(--ink-faded); font-size: 10px; }

        /* ───────────── Activity strip ───────────── */
        .activity-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            padding: 18px 0;
            border-top: 1px solid var(--rule);
            border-bottom: 1px solid var(--rule);
        }
        .activity-cell .label {
            font-family: var(--font-mono);
            font-size: 9.5px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-faded);
            margin-bottom: 8px;
        }
        .activity-cell .stat-list {
            font-size: 11px;
            color: var(--ink-soft);
            line-height: 1.7;
        }
        .activity-cell .stat-list strong { color: var(--ink); font-weight: 600; font-family: var(--font-mono); }
        .activity-cell .stat-list .sub { color: var(--ink-faded); font-size: 10px; }

        .email-subjects {
            margin-top: 18px;
        }
        .email-subjects .label {
            font-family: var(--font-mono);
            font-size: 9.5px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-faded);
            margin-bottom: 10px;
        }
        .email-subject {
            padding: 8px 0;
            border-top: 1px solid var(--rule);
        }
        .email-subject:first-of-type { border-top: none; padding-top: 0; }
        .email-subject .title {
            font-size: 12px;
            color: var(--ink);
            font-weight: 500;
        }
        .email-subject .meta {
            font-size: 10.5px;
            color: var(--ink-faded);
            margin-top: 2px;
            font-family: var(--font-mono);
        }
        .email-subject .action-flag {
            display: inline-block;
            margin-left: 8px;
            font-size: 9px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--warn);
            font-weight: 600;
            font-family: var(--font-body);
        }

        /* ───────────── Tables ───────────── */
        table.entries {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 11px;
        }
        table.entries th {
            background: transparent;
            padding: 7px 0 9px;
            text-align: left;
            font-family: var(--font-mono);
            font-size: 9.5px;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-faded);
            border-bottom: 1px solid var(--rule-strong);
        }
        table.entries td {
            padding: 9px 12px 9px 0;
            border-bottom: 1px solid var(--rule);
            vertical-align: top;
            color: var(--ink-soft);
        }
        table.entries td:last-child { padding-right: 0; }
        table.entries td.right { text-align: right; }
        table.entries td.ink { color: var(--ink); }
        table.entries td.mono { font-family: var(--font-mono); font-size: 10.5px; }
        table.entries tr.total td {
            border-bottom: none;
            border-top: 1px solid var(--rule-strong);
            padding-top: 11px;
            color: var(--ink);
            font-weight: 600;
        }
        table.entries code {
            font-family: var(--font-mono);
            font-size: 10px;
            background: var(--surface);
            color: var(--ink-soft);
            padding: 1px 5px;
            border-radius: 2px;
            margin-right: 6px;
        }

        .empty-state {
            padding: 18px 0;
            color: var(--ink-faded);
            font-style: italic;
            font-size: 11px;
        }

        h3.subhead {
            font-family: var(--font-mono);
            font-size: 9.5px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--ink-faded);
            margin: 22px 0 8px 0;
            font-weight: 500;
        }
        h3.subhead .count {
            color: var(--ink-soft);
            font-family: var(--font-mono);
            margin-left: 6px;
        }

        /* ───────────── Footnotes & footer ───────────── */
        .footnotes {
            margin-top: 36px;
            padding: 18px 0 0;
            border-top: 1px solid var(--rule);
            font-size: 10px;
            line-height: 1.65;
            color: var(--ink-faded);
        }
        .footnotes .label {
            font-family: var(--font-mono);
            font-size: 9px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--ink-faded);
            margin-bottom: 6px;
        }
        .footnotes p { margin: 0 0 4px 0; max-width: 80ch; }
        .footnotes strong { color: var(--ink-soft); font-weight: 600; }

        .footer {
            margin-top: 28px;
            padding-top: 14px;
            border-top: 1px solid var(--rule);
            font-family: var(--font-mono);
            font-size: 9.5px;
            letter-spacing: 0.06em;
            color: var(--ink-faded);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Page-break behaviour for PDF (also applies in browser print).
           Outside @media print so Cloudflare Browser Rendering honours
           them when it generates the PDF without forcing print media.

           Important: do NOT add break-inside:avoid to large block-level
           containers (.narrative, section, .breakdown, .activity-strip).
           If a container is taller than a single page, that rule forces
           it to wait for the next page — leaving the prior page blank.
           Only small atomic units (a topic row, a table row, a single
           card) should resist breaking. */
        .topic,
        .hours-panel,
        .summary-cell,
        .email-subject,
        table.entries tr,
        table.entries thead { break-inside: avoid; page-break-inside: avoid; }

        /* Don't orphan a heading at the bottom of a page. */
        h2, .section-head, h3.subhead { break-after: avoid; page-break-after: avoid; }

        /* Sections + large grids can break across pages at natural boundaries. */
        section, .narrative, .breakdown, .estimation, .activity-strip, .summary-grid {
            break-inside: auto;
        }

        /* Inline time-entry editing (admin view only). */
        .entry-actions { white-space: nowrap; width: 1%; }
        .entry-edit { margin: 0; }
        .entry-edit input, .entries input[type=number] { font: inherit; padding: 2px 4px; border: 1px solid var(--rule); border-radius: 3px; background: #fff; }
        .entry-edit input { width: 100%; }
        .entries input[type=number] { width: 5em; text-align: right; }
        .entry-actions button { font-size: 11px; padding: 2px 8px; margin-right: 4px; cursor: pointer; }
        .entry-remove { color: var(--danger); }
        .entry-badge { font-size: 9px; color: var(--ink-faded); text-transform: uppercase; margin-left: 6px; }

        /* Print-only adjustments. */
        @media print {
            .toolbar { display: none; }
            .entry-actions, .entries input { display: none; }
        }
    </style>
</head>
<body>
<div class="page">

    @php
        $hoursBudget = $snapshot['hours_budget'];
        $used = $snapshot['total_equivalent_hours'];
        $pct = $hoursBudget > 0 ? round($used / $hoursBudget * 100) : 0;
        $pctClamped = max(0, min(100, $pct));
        $isOver = $snapshot['is_over_budget'];
        $sourceLabel = match ($snapshot['human_hours_source'] ?? 'tracked') {
            'tracked' => 'tracked',
            'narrative' => 'AI-analysed',
            default => 'estimated',
        };
        $isEstimated = ($snapshot['human_hours_source'] ?? 'tracked') === 'estimated';
        $est = $snapshot['estimated_hours'] ?? null;
        $activity = $snapshot['period_activity'] ?? null;
        $hasActivity = $activity && (
            ($activity['github']['commits'] ?? 0) > 0
            || ($activity['github']['prs_merged'] ?? 0) > 0
            || ($activity['github']['prs_opened'] ?? 0) > 0
            || ($activity['github']['issues_closed'] ?? 0) > 0
            || ($activity['slack']['total_messages'] ?? 0) > 0
            || (($activity['email']['inbound_count'] ?? 0) + ($activity['email']['outbound_count'] ?? 0)) > 0
            || ($activity['tasks']['completed_count'] ?? 0) > 0
        );
        $sectionNum = 0;
        $secLabel = fn () => sprintf('§ %02d', ++$sectionNum);
        $formatSlaDuration = function (?float $seconds): string {
            if ($seconds === null) {
                return '—';
            }
            if ($seconds < 60) {
                return round($seconds).'s';
            }
            if ($seconds < 3600) {
                return round($seconds / 60).'m';
            }
            if ($seconds < 86400) {
                $hours = $seconds / 3600;

                return (abs($hours - round($hours)) < 0.05 ? (string) round($hours) : number_format($hours, 1)).'h';
            }
            $days = $seconds / 86400;

            return (abs($days - round($days)) < 0.05 ? (string) round($days) : number_format($days, 1)).'d';
        };
        $firstResponse = $activity['slack']['first_response'] ?? [];
        $timeToMerge = $activity['slack']['time_to_merge'] ?? [];
        $firstResponseTotal = (int) ($firstResponse['total_conversations'] ?? 0);
        $firstResponseReplied = (int) ($firstResponse['replied_conversations'] ?? 0);
        $timeToMergeTotal = (int) ($timeToMerge['total_conversations'] ?? 0);
        $timeToMergeShipped = (int) ($timeToMerge['shipped_conversations'] ?? 0);
    @endphp

    {{-- ───────── Masthead ───────── --}}
    <header class="masthead">
        <div class="masthead-left">
            <p class="eyebrow">{{ $company['name'] }} · Retainer Brief</p>
            <h1>{{ $client->name }}</h1>
            <p class="subtitle">
                Reporting period <strong>{{ $start->format('F j') }} – {{ $end->format('F j, Y') }}</strong>
            </p>
        </div>
        <div class="masthead-right">
            <div class="period-block">
                <span class="label">Issued</span>
                {{ now()->format('M j, Y') }}
            </div>
        </div>
    </header>

    {{-- ───────── Public toolbar (client share view) ───────── --}}
    @if(($isPublicView ?? false) && ! ($isPdf ?? false))
        <div class="toolbar">
            <div class="toolbar-group" style="flex: 1 1 auto;">
                <span style="font-family: var(--font-mono); font-size: 10.5px; color: var(--ink-faded); letter-spacing: 0.08em; text-transform: uppercase;">Downloads</span>
            </div>
            <div class="toolbar-group">
                @if(! empty($publicPdfUrl))
                    <a href="{{ $publicPdfUrl }}" class="toolbar-btn">↓ PDF</a>
                @endif
                @if(! empty($publicCsvUrl))
                    <a href="{{ $publicCsvUrl }}" class="toolbar-btn">↓ Timesheet CSV</a>
                @endif
            </div>
        </div>
    @endif

    {{-- ───────── Admin toolbar (web view only) ───────── --}}
    @if(($isAdminView ?? false) && ! ($isPdf ?? false))
        @php $refreshState = $refreshStatus['state'] ?? null; @endphp
        @if($refreshState === 'running')
            <div class="refresh-banner">
                <span class="refresh-spinner">↻</span>
                Refresh in progress — re-pulling commits and regenerating the narrative.
                This usually takes a minute or two; the page reloads automatically.
            </div>
            <script>setTimeout(() => window.location.reload(), 8000);</script>
        @elseif($refreshState === 'done')
            <div class="refresh-banner refresh-banner--done">✓ {{ $refreshStatus['message'] ?? 'Report refreshed.' }}</div>
        @elseif($refreshState === 'error')
            <div class="refresh-banner refresh-banner--error">⚠ {{ $refreshStatus['message'] ?? 'Refresh failed.' }}</div>
        @endif
        <div class="toolbar">
            <div class="toolbar-group">
                @if($prevPeriod ?? null)
                    <a href="/retainers/{{ $prevPeriod->id }}/report" class="toolbar-btn" title="Previous period">
                        ← {{ \Carbon\Carbon::parse($prevPeriod->period_start)->format('M Y') }}
                    </a>
                @endif

                @if(! empty($allPeriods) && $allPeriods->count() >= 1)
                    <select class="toolbar-select"
                            onchange="if (this.value) window.location.href = this.value">
                        @foreach($allPeriods->sortByDesc('period_start') as $opt)
                            <option value="/retainers/{{ $opt->id }}/report" {{ $opt->id === $period->id ? 'selected' : '' }}>
                                {{ \Carbon\Carbon::parse($opt->period_start)->format('M Y') }}
                            </option>
                        @endforeach
                    </select>
                @endif

                @if($nextPeriod ?? null)
                    <a href="/retainers/{{ $nextPeriod->id }}/report" class="toolbar-btn" title="Next period">
                        {{ \Carbon\Carbon::parse($nextPeriod->period_start)->format('M Y') }} →
                    </a>
                @endif
            </div>
            <div class="toolbar-group">
                <form action="/retainers/{{ $period->id }}/report/refresh" method="POST">
                    @csrf
                    <button type="submit" class="toolbar-btn" @disabled(($refreshStatus['state'] ?? null) === 'running')
                            title="Re-pull GitHub commits, re-run snapshot, regenerate narrative. Runs in the background.">
                        ↻&nbsp;&nbsp;{{ ($refreshStatus['state'] ?? null) === 'running' ? 'Refreshing…' : 'Refresh' }}
                    </button>
                </form>
                <a href="/retainers/{{ $period->id }}/report/pdf" class="toolbar-btn">↓ PDF</a>
                <a href="/retainers/{{ $period->id }}/report/csv" class="toolbar-btn">↓ Timesheet CSV</a>
                @if(! empty($shareUrl))
                    <button type="button"
                            class="toolbar-btn"
                            data-share-url="{{ $shareUrl }}"
                            onclick="(async (btn) => {
                                try {
                                    await navigator.clipboard.writeText(btn.dataset.shareUrl);
                                    const orig = btn.textContent;
                                    btn.textContent = '✓ Link copied (90-day)';
                                    setTimeout(() => { btn.textContent = orig; }, 2200);
                                } catch (e) {
                                    window.prompt('Copy the share link (90-day):', btn.dataset.shareUrl);
                                }
                            })(this)"
                            title="Public, signature-protected URL valid for 90 days. Anyone with the link can view this report — no login required.">
                        🔗 Copy share link
                    </button>
                @endif
                <form action="/retainers/{{ $period->id }}/report/send" method="POST">
                    @csrf
                    <button type="submit" class="toolbar-btn is-primary"
                            onclick="return confirm('Email this report to {{ $client->name }}?');">
                        Send to Client →
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- Admin-only: surface narrative warnings so we know WHY topics are missing.
         Hidden from public view + PDF — these are internal diagnostics. --}}
    @if(($isAdminView ?? false) && ! ($isPdf ?? false) && $narrative && ! empty($narrative['warnings']))
        <section>
            <div class="narrative" style="background: rgba(192, 64, 64, 0.08); border: 1px solid rgba(192, 64, 64, 0.25);">
                <p class="value-summary" style="font-size: 13px; color: #8b3a3a; margin: 0;">
                    <strong>Narrative diagnostic:</strong>
                    @foreach($narrative['warnings'] as $w)
                        {{ $w }}@if(! $loop->last) · @endif
                    @endforeach
                </p>
            </div>
        </section>
    @endif

    {{-- ───────── 1. Narrative ───────── --}}
    @if(! $narrative && ($isAdminView ?? false) && ! ($isPdf ?? false))
        <section>
            <div class="section-head">
                <span class="number">{{ $secLabel() }}</span>
                <h2>What we delivered</h2>
            </div>
            <div class="narrative" style="background: rgba(184, 106, 29, 0.08);">
                <p class="value-summary" style="font-size: 14px; color: var(--ink-soft);">
                    The AI narrative for this period hasn't been generated yet. Click <strong>Refresh</strong> above to analyse the period's Slack, emails, and commits and produce the topic breakdown.
                </p>
            </div>
        </section>
    @endif

    @php
        $activeBuckets = ['not_started' => [], 'in_progress' => [], 'waiting_on_client' => []];
        if (! empty($currentlyActive['items'] ?? [])) {
            foreach ($currentlyActive['items'] as $aItem) {
                if (isset($activeBuckets[$aItem['status']])) {
                    $activeBuckets[$aItem['status']][] = $aItem;
                }
            }
        }
        $activeCount = array_sum(array_map('count', $activeBuckets));
        $activeLabels = [
            'not_started' => 'Open',
            'in_progress' => 'In progress',
            'waiting_on_client' => 'Waiting on you',
        ];
    @endphp
    @if($activeCount > 0)
        <section>
            <div class="section-head">
                <span class="number">{{ $secLabel() }}</span>
                <h2>Currently active</h2>
            </div>
            <div class="narrative">
                @foreach($activeBuckets as $bucketStatus => $bucketItems)
                    @if(! empty($bucketItems))
                        <p style="margin: 14px 0 8px; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #7a6e58;">
                            {{ $activeLabels[$bucketStatus] }} <span style="color:#b6a98c;">({{ count($bucketItems) }})</span>
                        </p>
                        <div class="topic-list">
                            @foreach($bucketItems as $aItem)
                                <div class="topic">
                                    <div class="topic-body">
                                        <p class="topic-title">{{ $aItem['title'] }}</p>
                                        @if(! empty($aItem['client_summary']))
                                            <p class="topic-summary">{{ $aItem['client_summary'] }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    @if($narrative && ! empty($narrative['value_summary']))
        <section>
            <div class="section-head">
                <span class="number">{{ $secLabel() }}</span>
                <h2>What we delivered</h2>
            </div>
            <div class="narrative">
                <p class="value-summary">{{ $narrative['value_summary'] }}</p>
                @if(! empty($narrative['topics']))
                    <div class="topic-list">
                        @foreach($narrative['topics'] as $topic)
                            <div class="topic">
                                <div class="topic-body">
                                    <p class="topic-title">{{ $topic['title'] }}</p>
                                    @if(! empty($topic['summary']))
                                        <p class="topic-summary">{{ $topic['summary'] }}</p>
                                    @endif
                                </div>
                                <div class="topic-meta">
                                    <div class="topic-hours">{{ number_format($topic['estimated_hours'], 2) }}<span class="unit">h</span></div>
                                    <span class="topic-status {{ $topic['status'] }}">{{ str_replace('_', ' ', $topic['status']) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="narrative-total" style="margin-top: 18px;">
                        Analysed from observable activity ·
                        <strong>{{ number_format($narrative['total_estimated_hours'], 2) }} hrs</strong> across {{ count($narrative['topics']) }} {{ Str::plural('topic', count($narrative['topics'])) }}
                    </p>
                @endif
            </div>
        </section>
    @endif

    {{-- ───────── 2. Hours vs budget ───────── --}}
    <section>
        <div class="section-head">
            <span class="number">{{ $secLabel() }}</span>
            <h2>Hours against retainer</h2>
        </div>

        <div class="hours-panel">
            <div class="hours-main">
                <div class="hours-eyebrow">Total hours · {{ $sourceLabel }}</div>
                <div class="hours-number">
                    {{ number_format($used, 1) }}<span class="of">/ {{ number_format($hoursBudget, 0) }} hrs</span>
                </div>
                <div class="bar">
                    <div class="bar-fill {{ $isOver ? 'is-over' : '' }}" style="width: {{ $pctClamped }}%;"></div>
                    @if($isOver)
                        <div class="bar-marker" style="left: 100%;"></div>
                    @endif
                </div>
                <div class="hours-pct {{ $isOver ? 'is-over' : '' }}">
                    {{ $snapshot['usage_percent'] }}% of retainer{{ $isOver ? ' — over budget' : '' }}
                </div>
            </div>
            <div class="hours-meta">
                <div class="retainer-amount">${{ number_format($snapshot['monthly_amount'], 0) }}<span style="font-size: 12px; color: var(--ink-faded); margin-left: 2px;">/mo</span></div>
                <div class="retainer-label">{{ $period->tier ? str_replace('_', ' ', $period->tier) : 'Standard retainer' }}</div>
            </div>
        </div>

        <div class="breakdown">
            <div class="breakdown-item">
                <div class="label">Human time</div>
                <div class="value">{{ number_format($snapshot['human_hours'], 1) }}<span class="unit">h</span></div>
                @if($isEstimated)
                    <div class="sub"><span class="estimated-flag">est.</span></div>
                @endif
            </div>
            <div class="breakdown-item">
                <div class="label">Meetings</div>
                <div class="value">{{ number_format($snapshot['meeting_hours'], 1) }}<span class="unit">h</span></div>
                <div class="sub">{{ $snapshot['meeting_count'] }} {{ Str::plural('session', $snapshot['meeting_count']) }}</div>
            </div>
            <div class="breakdown-item">
                <div class="label">Agent equivalent</div>
                <div class="value">{{ number_format($snapshot['agent_equivalent_hours'], 1) }}<span class="unit">h</span></div>
                <div class="sub">based on agent cost</div>
            </div>
        </div>
    </section>

    {{-- ───────── 3. Estimated time detail (only when source = estimated) ───────── --}}
    @if($isEstimated && ! empty($est))
        <section>
            <div class="section-head">
                <span class="number">{{ $secLabel() }}</span>
                <h2>How those hours were estimated</h2>
            </div>
            <p class="section-sub">
                No manual time entries were logged this period, so hours above are estimated from observable activity across Slack, code commits, and email. Methodology footnoted below.
            </p>

            <div class="estimation">
                <div class="estimation-cell">
                    <div class="label">Slack on-task</div>
                    <div class="value">{{ number_format($est['slack_hours'], 1) }}<span class="unit">h</span></div>
                    <div class="detail">
                        @forelse($est['slack_by_user'] as $u)
                            {{ $u['user_name'] }} · <strong>{{ number_format($u['hours'], 1) }} h</strong>
                            <span class="sub">({{ $u['sessions'] ?? $u['days'] ?? 0 }} {{ Str::plural('session', $u['sessions'] ?? $u['days'] ?? 0) }})</span><br>
                        @empty
                            <span class="sub">No internal Slack activity.</span>
                        @endforelse
                    </div>
                </div>
                <div class="estimation-cell">
                    <div class="label">Commit effort</div>
                    <div class="value">{{ number_format($est['commit_hours'], 1) }}<span class="unit">h</span></div>
                    <div class="detail">
                        @forelse($est['commit_repos'] as $r)
                            {{ $r['repo'] }}<br>
                            <strong>{{ number_format($r['human_hours'] ?? $r['hours'], 1) }} h</strong>
                            <span class="sub">across {{ $r['human_commits'] ?? $r['count'] }} {{ Str::plural('commit', $r['human_commits'] ?? $r['count']) }}</span>
                            @if(($r['ai_commits'] ?? 0) > 0)
                                <br><span class="sub">+ {{ number_format($r['ai_hours'], 1) }} h review on {{ $r['ai_commits'] }} AI-authored</span>
                            @endif
                            <br>
                        @empty
                            <span class="sub">No commit activity.</span>
                        @endforelse
                    </div>
                </div>
                <div class="estimation-cell">
                    <div class="label">Email triage</div>
                    <div class="value">{{ number_format($est['email_hours'] ?? 0, 1) }}<span class="unit">h</span></div>
                    <div class="detail">
                        <strong>{{ $est['email_counts']['inbound'] ?? 0 }}</strong> inbound
                        <span class="sub">from client</span><br>
                        <strong>{{ $est['email_counts']['outbound'] ?? 0 }}</strong> outbound
                        <span class="sub">replies</span>
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- ───────── 4. Period activity ───────── --}}
    @if($hasActivity)
        <section>
            <div class="section-head">
                <span class="number">{{ $secLabel() }}</span>
                <h2>What happened this period</h2>
            </div>
            <p class="section-sub">Activity counts across GitHub, Slack, and email.@if($firstResponseTotal > 0) First response {{ $firstResponseReplied }}/{{ $firstResponseTotal }} client threads with a reply; {{ $timeToMergeShipped }}/{{ $timeToMergeTotal }} threads merged.@endif These signals feed the hour estimates above — they are not additional hours.</p>

            <div class="activity-strip">
                <div class="activity-cell">
                    <div class="label">GitHub</div>
                    <div class="stat-list">
                        Commits · <strong>{{ $activity['github']['commits'] ?? 0 }}</strong><br>
                        PRs merged · <strong>{{ $activity['github']['prs_merged'] ?? 0 }}</strong><br>
                        PRs opened · <strong>{{ $activity['github']['prs_opened'] ?? 0 }}</strong><br>
                        Issues closed · <strong>{{ $activity['github']['issues_closed'] ?? 0 }}</strong>
                    </div>
                </div>
                <div class="activity-cell">
                    <div class="label">Slack</div>
                    <div class="stat-list">
                        Messages · <strong>{{ $activity['slack']['total_messages'] ?? 0 }}</strong><br>
                        From {{ $client->name }} · <strong>{{ $activity['slack']['external_messages'] ?? 0 }}</strong><br>
                        From {{ $company['name'] }} · <strong>{{ $activity['slack']['internal_messages'] ?? 0 }}</strong><br>
                        First response · <strong>{{ $formatSlaDuration($firstResponse['median_seconds'] ?? null) }}</strong> median / <strong>{{ $formatSlaDuration($firstResponse['average_seconds'] ?? null) }}</strong> avg<br>
                        <span class="sub">{{ $firstResponseReplied }}/{{ $firstResponseTotal }} threads with a reply</span><br>
                        Time to merge · <strong>{{ $formatSlaDuration($timeToMerge['median_seconds'] ?? null) }}</strong> median / <strong>{{ $formatSlaDuration($timeToMerge['average_seconds'] ?? null) }}</strong> avg<br>
                        <span class="sub">{{ $timeToMergeShipped }}/{{ $timeToMergeTotal }} threads merged</span>
                    </div>
                </div>
                <div class="activity-cell">
                    <div class="label">Email</div>
                    <div class="stat-list">
                        Inbound · <strong>{{ $activity['email']['inbound_count'] ?? 0 }}</strong><br>
                        Outbound · <strong>{{ $activity['email']['outbound_count'] ?? 0 }}</strong><br>
                        Threads · <strong>{{ $activity['email']['threads'] ?? 0 }}</strong>
                    </div>
                </div>
                <div class="activity-cell">
                    <div class="label">Channels</div>
                    <div class="stat-list">
                        @forelse(array_slice($activity['slack']['channels'] ?? [], 0, 3) as $channel)
                            #{{ $channel['name'] }} · <strong>{{ $channel['count'] }}</strong><br>
                        @empty
                            <span class="sub">—</span>
                        @endforelse
                    </div>
                </div>
                <div class="activity-cell">
                    <div class="label">Tasks</div>
                    <div class="stat-list">
                        Completed · <strong>{{ $activity['tasks']['completed_count'] ?? 0 }}</strong>
                    </div>
                </div>
            </div>

            @if(! empty($activity['email']['top_subjects']))
                <div class="email-subjects">
                    <div class="label">Recent email subjects</div>
                    @foreach(array_slice($activity['email']['top_subjects'], 0, 6) as $email)
                        <div class="email-subject">
                            <div class="title">
                                {{ \Illuminate\Support\Str::limit($email['subject'], 80) }}
                                @if($email['action_required'])
                                    <span class="action-flag">action required</span>
                                @endif
                            </div>
                            <div class="meta">
                                {{ $email['from'] }} · {{ $email['received_at'] ? \Carbon\Carbon::parse($email['received_at'])->setTimezone(config('app.display_timezone'))->format('M j') : '—' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if(! empty($activity['github']['merged_pull_requests']))
                <h3 class="subhead">Merged pull requests<span class="count">— {{ count($activity['github']['merged_pull_requests']) }}</span></h3>
                <table class="entries">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Date</th>
                            <th style="width: 160px;">Repo</th>
                            <th>Pull request</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activity['github']['merged_pull_requests'] as $pr)
                            <tr>
                                <td class="mono">{{ $pr['merged_at'] ? \Carbon\Carbon::parse($pr['merged_at'])->setTimezone(config('app.display_timezone'))->format('M j') : '—' }}</td>
                                <td class="mono">{{ $pr['repo'] ?? '—' }}</td>
                                <td class="ink">#{{ $pr['pr_number'] }} — {{ $pr['title'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if(! empty($activity['github']['recent_commits']))
                <h3 class="subhead">Recent commits<span class="count">— {{ $activity['github']['commits'] }} total</span></h3>
                <table class="entries">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Date</th>
                            <th style="width: 220px;">Repo / branch</th>
                            <th>Commit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activity['github']['recent_commits'] as $commit)
                            <tr>
                                <td class="mono">{{ $commit['committed_at'] ? \Carbon\Carbon::parse($commit['committed_at'])->setTimezone(config('app.display_timezone'))->format('M j') : '—' }}</td>
                                <td class="mono">{{ $commit['repo'].' @ '.$commit['branch'] }}</td>
                                <td class="ink">
                                    <code>{{ $commit['sha'] }}</code>{{ \Illuminate\Support\Str::limit($commit['message'], 80) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if(! empty($activity['tasks']['completed']))
                <h3 class="subhead">Tasks completed<span class="count">— {{ count($activity['tasks']['completed']) }}</span></h3>
                <p class="section-sub">Work closed out in tracking sheets this period. The same item may also appear in Slack or a commit above — the hour estimates fold those surfaces into one topic rather than counting them twice.</p>
                <table class="entries">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Date</th>
                            <th>Task</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activity['tasks']['completed'] as $task)
                            <tr>
                                <td class="mono">{{ $task['completed_at'] ? \Carbon\Carbon::parse($task['completed_at'])->setTimezone(config('app.display_timezone'))->format('M j') : '—' }}</td>
                                <td class="ink">{{ $task['title'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

    {{-- ───────── 5. Time entries ───────── --}}
    @if($timeEntries->isNotEmpty())
        @php($canEditEntries = ($isAdminView ?? false) && ! ($isPdf ?? false))
        <section>
            <div class="section-head">
                <span class="number">{{ $secLabel() }}</span>
                <h2>Time entries</h2>
            </div>
            <table class="entries">
                <thead>
                    <tr>
                        <th style="width: 60px;">Date</th>
                        <th>Description</th>
                        <th class="right" style="width: 70px;">Hours</th>
                        @if($canEditEntries)<th class="entry-actions">Edit</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($timeEntries as $entry)
                        @if($canEditEntries)
                            <tr>
                                <td class="mono">{{ \Carbon\Carbon::parse($entry->spent_date)->format('M j') }}</td>
                                <td class="ink">
                                    <form method="POST" action="{{ route('retainers.report.entries.adjust', [$period, $entry]) }}" id="adjust-{{ $entry->id }}" class="entry-edit">
                                        @csrf
                                        <input type="text" name="notes" value="{{ $entry->notes ?? $entry->description }}" aria-label="Description">
                                    </form>
                                    @if(($entry->source ?? null) === 'manual')<span class="entry-badge" title="Edited — survives narrative refresh">manual</span>@endif
                                </td>
                                <td class="right mono ink">
                                    <input type="number" step="0.25" min="0" name="hours" value="{{ number_format((float) $entry->hours, 2, '.', '') }}" form="adjust-{{ $entry->id }}" aria-label="Hours">
                                </td>
                                <td class="entry-actions">
                                    <button type="submit" form="adjust-{{ $entry->id }}">Save</button>
                                    <form method="POST" action="{{ route('retainers.report.entries.delete', [$period, $entry]) }}" onsubmit="return confirm('Remove this entry?');" style="display:inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="entry-remove">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @else
                            <tr>
                                <td class="mono">{{ \Carbon\Carbon::parse($entry->spent_date)->format('M j') }}</td>
                                <td class="ink">{{ $entry->notes ?? $entry->description ?? '—' }}</td>
                                <td class="right mono ink">{{ number_format($entry->hours, 2) }}</td>
                            </tr>
                        @endif
                    @endforeach
                    <tr class="total">
                        <td colspan="2" class="right">Total</td>
                        <td class="right mono">{{ number_format($timeEntries->sum('hours'), 2) }} h</td>
                        @if($canEditEntries)<td></td>@endif
                    </tr>
                </tbody>
            </table>
        </section>
    @endif

    {{-- ───────── 6. Meetings ───────── --}}
    @if($meetings->isNotEmpty())
        <section>
            <div class="section-head">
                <span class="number">{{ $secLabel() }}</span>
                <h2>Client meetings</h2>
            </div>
            <table class="entries">
                <thead>
                    <tr>
                        <th style="width: 130px;">Date</th>
                        <th>Title</th>
                        <th class="right" style="width: 80px;">Duration</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($meetings as $meeting)
                        <tr>
                            <td class="mono">{{ \Carbon\Carbon::parse($meeting->start_at)->format('M j · g:i a') }}</td>
                            <td class="ink">{{ $meeting->title ?? $meeting->summary ?? 'Meeting' }}</td>
                            <td class="right mono">{{ number_format($meeting->duration_hours ?? 0, 2) }} h</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- ───────── 7. Agent work ───────── --}}
    @if($agentRuns->isNotEmpty())
        <section>
            <div class="section-head">
                <span class="number">{{ $secLabel() }}</span>
                <h2>Agent activity</h2>
            </div>
            <table class="entries">
                <thead>
                    <tr>
                        <th style="width: 60px;">Date</th>
                        <th>Agent / task</th>
                        <th style="width: 100px;">Status</th>
                        <th class="right" style="width: 80px;">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($agentRuns as $run)
                        <tr>
                            <td class="mono">{{ \Carbon\Carbon::parse($run->started_at)->format('M j') }}</td>
                            <td class="ink">{{ $run->agent?->name ?? $run->agent_name ?? 'Agent run' }}</td>
                            <td class="mono">{{ $run->status }}</td>
                            <td class="right mono">${{ number_format((float) $run->cost_usd, 4) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- ───────── Footnotes ───────── --}}
    @if($isEstimated)
        <div class="footnotes">
            <div class="label">Estimation methodology</div>
            <p><strong>Slack.</strong> Messages clustered into work sessions (gap &gt; 30 min = new session). Each session counted as actual span + 5 min wrap, minimum 5 min.</p>
            <p><strong>Commit effort.</strong> Human commits estimated from lines and files changed. AI-authored commits (claude, github-actions, dependabot, etc.) counted as 0.1 hours each for review time only.</p>
            <p><strong>Email triage.</strong> 10 minutes per inbound message (30 minutes if body &gt; 1500 characters); 5 minutes per outbound reply.</p>
            @if($narrative && ! empty($narrative['topics']))
                <p style="margin-top: 6px;"><strong>Narrative.</strong> Topics, summaries, and per-topic hour estimates above were synthesized by an LLM from raw activity. Hour totals from the narrative analysis may differ from the heuristic estimate; the narrative is the more accurate reading.</p>
            @endif
        </div>
    @endif

    <footer class="footer">
        <span>{{ $company['name'] }} · {{ $company['email'] }}</span>
        <span>Generated {{ now()->format('M j, Y') }}</span>
    </footer>

</div>
</body>
</html>
