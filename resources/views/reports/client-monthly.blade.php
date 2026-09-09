<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $report->client->name }} - {{ $report->period_label }}</title>
    @php
        $primaryColor = $settings->primary_color ?? '#2563eb';
        $accentColor = $settings->accent_color ?? '#4f46e5';
        $isPdf = $isPdf ?? true;
    @endphp
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #1f2937;
            background: white;
        }

        .page {
            width: 8.5in;
            min-height: 11in;
            padding: 0.6in;
            background: white;
            page-break-after: always;
        }

        .page:last-child {
            page-break-after: auto;
        }

        /* Header */
        .header-table {
            width: 100%;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid {{ $primaryColor }};
        }

        .logo-cell {
            vertical-align: middle;
        }

        .logo {
            max-height: 40px;
            max-width: 150px;
        }

        .company-name {
            font-size: 16pt;
            font-weight: bold;
            color: #111827;
            padding-left: 12px;
            vertical-align: middle;
        }

        .period-cell {
            text-align: right;
            vertical-align: middle;
        }

        .period-badge {
            display: inline-block;
            background: {{ $primaryColor }};
            color: white;
            padding: 6px 14px;
            font-size: 10pt;
            font-weight: 600;
        }

        /* Hero Section */
        .hero {
            text-align: center;
            padding: 40px 20px;
            margin-bottom: 25px;
            background: #f8fafc;
            border-left: 4px solid {{ $primaryColor }};
        }

        .hero-title {
            font-size: 24pt;
            font-weight: bold;
            color: {{ $primaryColor }};
            margin-bottom: 8px;
        }

        .hero-subtitle {
            font-size: 12pt;
            color: #6b7280;
        }

        /* Section Titles */
        .section-title {
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: {{ $primaryColor }};
            margin-bottom: 10px;
            font-weight: bold;
        }

        /* Summary */
        .summary-box {
            background: #f9fafb;
            padding: 18px;
            margin-bottom: 25px;
            border-left: 3px solid {{ $accentColor }};
        }

        .summary-text {
            font-size: 11pt;
            color: #374151;
            line-height: 1.7;
        }

        /* Metrics Grid */
        .metrics-table {
            width: 100%;
            margin-bottom: 25px;
            border-collapse: collapse;
        }

        .metric-cell {
            width: 33.33%;
            padding: 20px 15px;
            text-align: center;
            background: #f9fafb;
            vertical-align: top;
        }

        .metric-cell-middle {
            border-left: 2px solid white;
            border-right: 2px solid white;
        }

        .metric-value {
            font-size: 28pt;
            font-weight: bold;
            color: {{ $primaryColor }};
            line-height: 1.1;
        }

        .metric-label {
            font-size: 10pt;
            color: #6b7280;
            margin-top: 6px;
        }

        .metric-context {
            font-size: 9pt;
            color: #9ca3af;
            margin-top: 4px;
        }

        /* Highlights */
        .highlight-box {
            margin-bottom: 12px;
            padding: 15px;
            background: #f9fafb;
        }

        .highlight-table {
            width: 100%;
        }

        .highlight-icon-cell {
            width: 40px;
            vertical-align: top;
            padding-right: 12px;
        }

        .highlight-icon {
            font-size: 18pt;
            width: 36px;
            height: 36px;
            text-align: center;
            line-height: 36px;
            background: {{ $primaryColor }}20;
        }

        .highlight-title {
            font-size: 11pt;
            font-weight: bold;
            color: #111827;
            margin-bottom: 3px;
        }

        .highlight-description {
            font-size: 10pt;
            color: #6b7280;
        }

        /* Time Breakdown */
        .breakdown-box {
            background: #f9fafb;
            padding: 18px;
            margin-bottom: 25px;
        }

        .breakdown-header {
            width: 100%;
            margin-bottom: 15px;
        }

        .total-hours {
            font-size: 24pt;
            font-weight: bold;
            color: {{ $primaryColor }};
        }

        .hours-label {
            font-size: 11pt;
            color: #6b7280;
        }

        .category-table {
            width: 100%;
            border-collapse: collapse;
        }

        .category-row td {
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .category-name-cell {
            width: 40%;
            font-size: 10pt;
            color: #374151;
        }

        .category-bar-cell {
            width: 45%;
            padding: 0 10px;
        }

        .category-bar-bg {
            width: 100%;
            height: 14px;
            background: #e5e7eb;
        }

        .category-bar {
            height: 14px;
            background: {{ $primaryColor }};
        }

        .category-hours-cell {
            width: 15%;
            text-align: right;
            font-size: 10pt;
            font-weight: 600;
            color: #111827;
        }

        /* Stats Grid */
        .stats-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .stat-cell {
            width: 50%;
            padding: 20px;
            text-align: center;
            background: #f9fafb;
        }

        .stat-cell-left {
            border-right: 2px solid white;
        }

        .stat-value {
            font-size: 32pt;
            font-weight: bold;
            color: {{ $primaryColor }};
        }

        .stat-label {
            font-size: 10pt;
            color: #6b7280;
            margin-top: 5px;
        }

        /* Code Changes */
        .code-changes-box {
            padding: 12px;
            background: #f9fafb;
            text-align: center;
        }

        .additions {
            color: #16a34a;
            font-weight: bold;
            font-size: 11pt;
            padding-right: 20px;
        }

        .deletions {
            color: #dc2626;
            font-weight: bold;
            font-size: 11pt;
            padding-left: 20px;
        }

        /* Simple Stat Box */
        .simple-stat-box {
            text-align: center;
            padding: 25px;
            background: #f9fafb;
            margin-bottom: 20px;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding-top: 25px;
            margin-top: 30px;
            border-top: 1px solid #e5e7eb;
        }

        .footer-text {
            font-size: 9pt;
            color: #9ca3af;
        }

        .footer-brand {
            font-size: 10pt;
            color: #6b7280;
            margin-top: 5px;
        }

        /* Print styles */
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .page {
                margin: 0;
                padding: 0.5in;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <!-- Header -->
        <table class="header-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="logo-cell">
                    @if($settings->logo_url)
                        <img src="{{ $settings->logo_url }}" alt="" class="logo">
                    @endif
                    <span class="company-name">{{ $report->client->name }}</span>
                </td>
                <td class="period-cell">
                    <span class="period-badge">{{ $report->period_label }}</span>
                </td>
            </tr>
        </table>

        <!-- Hero Section -->
        <div class="hero">
            <div class="hero-title">Your Month in Review</div>
            <div class="hero-subtitle">Here's what we accomplished together</div>
        </div>

        <!-- Executive Summary -->
        @if($report->executive_summary)
        <div class="summary-box">
            <div class="section-title">Executive Summary</div>
            <p class="summary-text">{{ $report->executive_summary }}</p>
        </div>
        @endif

        <!-- Hero Metrics -->
        @if($report->metrics && count($report->metrics) > 0)
        <table class="metrics-table" cellpadding="0" cellspacing="0">
            <tr>
                @foreach(array_slice($report->metrics, 0, 3) as $index => $metric)
                <td class="metric-cell {{ $index === 1 ? 'metric-cell-middle' : '' }}">
                    <div class="metric-value">{{ $metric['value'] ?? '—' }}</div>
                    <div class="metric-label">{{ $metric['label'] ?? '' }}</div>
                    @if(!empty($metric['context']))
                    <div class="metric-context">{{ $metric['context'] }}</div>
                    @endif
                </td>
                @endforeach
                @for($i = count($report->metrics); $i < 3; $i++)
                <td class="metric-cell"></td>
                @endfor
            </tr>
        </table>
        @endif

        <!-- Highlights -->
        @if($report->highlights && count($report->highlights) > 0)
        <div style="margin-bottom: 25px;">
            <div class="section-title">Key Achievements</div>
            @foreach(array_slice($report->highlights, 0, 4) as $highlight)
            <div class="highlight-box">
                <table class="highlight-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="highlight-icon-cell">
                            <div class="highlight-icon">{{ $highlight['icon'] ?? '&#10004;' }}</div>
                        </td>
                        <td>
                            <div class="highlight-title">{{ $highlight['title'] ?? '' }}</div>
                            <div class="highlight-description">{{ $highlight['description'] ?? '' }}</div>
                        </td>
                    </tr>
                </table>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    <!-- Page 2: Detailed Breakdown -->
    <div class="page">
        <table class="header-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="logo-cell">
                    @if($settings->logo_url)
                        <img src="{{ $settings->logo_url }}" alt="" class="logo">
                    @endif
                    <span class="company-name">{{ $report->client->name }}</span>
                </td>
                <td class="period-cell">
                    <span class="period-badge">{{ $report->period_label }}</span>
                </td>
            </tr>
        </table>

        <!-- Time Breakdown -->
        @if($settings->include_time_breakdown && $report->hours_by_category && count($report->hours_by_category) > 0)
        <div class="breakdown-box">
            <table class="breakdown-header" cellpadding="0" cellspacing="0">
                <tr>
                    <td>
                        <div class="section-title">Time Investment</div>
                    </td>
                    <td style="text-align: right;">
                        <span class="total-hours">{{ number_format($report->total_hours ?? 0, 1) }}</span>
                        <span class="hours-label">hours</span>
                    </td>
                </tr>
            </table>

            @php
                $maxHours = collect($report->hours_by_category)->max('hours') ?: 1;
            @endphp
            <table class="category-table" cellpadding="0" cellspacing="0">
                @foreach(array_slice($report->hours_by_category, 0, 6) as $category)
                <tr class="category-row">
                    <td class="category-name-cell">{{ $category['name'] ?? 'Other' }}</td>
                    <td class="category-bar-cell">
                        <div class="category-bar-bg">
                            <div class="category-bar" style="width: {{ ($category['hours'] / $maxHours) * 100 }}%"></div>
                        </div>
                    </td>
                    <td class="category-hours-cell">{{ number_format($category['hours'] ?? 0, 1) }}h</td>
                </tr>
                @endforeach
            </table>
        </div>
        @endif

        <!-- GitHub Activity -->
        @if($settings->include_github_activity && (($report->prs_merged ?? 0) > 0 || ($report->issues_closed ?? 0) > 0))
        <div style="margin-bottom: 25px;">
            <div class="section-title">Development Activity</div>

            <table class="stats-table" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="stat-cell stat-cell-left">
                        <div class="stat-value">{{ $report->prs_merged ?? 0 }}</div>
                        <div class="stat-label">Pull Requests Merged</div>
                    </td>
                    <td class="stat-cell">
                        <div class="stat-value">{{ $report->issues_closed ?? 0 }}</div>
                        <div class="stat-label">Issues Resolved</div>
                    </td>
                </tr>
            </table>

            @if(($report->data_snapshot['github']['total_lines_added'] ?? 0) > 0)
            <div class="code-changes-box">
                <span class="additions">+{{ number_format($report->data_snapshot['github']['total_lines_added']) }} lines added</span>
                <span class="deletions">-{{ number_format($report->data_snapshot['github']['total_lines_removed'] ?? 0) }} lines removed</span>
            </div>
            @endif
        </div>
        @endif

        <!-- Tasks Completed -->
        @if($settings->include_tasks_completed && ($report->tasks_completed ?? 0) > 0)
        <div style="margin-bottom: 25px;">
            <div class="section-title">Tasks Completed</div>
            <div class="simple-stat-box">
                <div class="stat-value">{{ $report->tasks_completed }}</div>
                <div class="stat-label">tasks delivered this period</div>
            </div>
        </div>
        @endif

        <!-- Meetings -->
        @if(($report->meetings_held ?? 0) > 0)
        <div style="margin-bottom: 25px;">
            <div class="section-title">Collaboration</div>
            <div class="simple-stat-box">
                <div class="stat-value">{{ $report->meetings_held }}</div>
                <div class="stat-label">meetings & sync calls</div>
            </div>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p class="footer-text">Report generated on {{ now()->format('F j, Y') }}</p>
            <p class="footer-brand">Powered by Zao</p>
        </div>
    </div>
</body>
</html>
