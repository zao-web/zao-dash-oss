<?php

namespace App\Services\Google;

use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Docs creation service for RFP proposals.
 *
 * Creates Google Docs from proposal content by uploading HTML to the
 * Drive API with the Google Docs MIME type (auto-converts on import).
 * Requires drive.file OAuth scope.
 *
 * Note: Re-authorization is required after adding the drive.file scope.
 * Users should re-connect Google via Settings > Integrations.
 */
class GoogleDocsService
{
    private const DRIVE_UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    private const DRIVE_FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    public function __construct(
        private GoogleOAuthService $oauth,
    ) {}

    /**
     * Create a Google Doc from an RFP proposal and return the doc URL.
     */
    public function createProposalDoc(User $user, RfpOpportunity $rfp, RfpProposal $proposal): string
    {
        $token = $this->oauth->getValidAccessToken($user);

        if (! $token) {
            throw new \RuntimeException('Google OAuth token not available. Please reconnect Google in Settings.');
        }

        $title = "Proposal: {$rfp->title} — {$rfp->issuing_organization} (v{$proposal->version})";
        $html = $this->buildProposalHtml($rfp, $proposal);

        // Upload as HTML with Google Docs MIME type — Drive auto-converts
        $metadata = json_encode([
            'name' => $title,
            'mimeType' => 'application/vnd.google-apps.document',
        ]);

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'multipart/related; boundary=boundary_rfp'])
            ->timeout(30)
            ->send('POST', self::DRIVE_UPLOAD_URL.'?uploadType=multipart', [
                'body' => implode("\r\n", [
                    '--boundary_rfp',
                    'Content-Type: application/json; charset=UTF-8',
                    '',
                    $metadata,
                    '--boundary_rfp',
                    'Content-Type: text/html; charset=UTF-8',
                    '',
                    $html,
                    '--boundary_rfp--',
                ]),
            ]);

        if (! $response->successful()) {
            Log::error('GoogleDocsService: failed to create doc', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            // Check if it's a scope error
            if ($response->status() === 403) {
                throw new \RuntimeException(
                    'Google Drive write access not authorized. '.
                    'Please reconnect Google in Settings > Integrations to grant document creation permission.'
                );
            }

            throw new \RuntimeException('Failed to create Google Doc: '.$response->body());
        }

        $fileId = $response->json('id');

        // Make the file readable by anyone with the link
        $this->shareWithLink($token, $fileId);

        $docUrl = "https://docs.google.com/document/d/{$fileId}/edit";

        Log::info('GoogleDocsService: created proposal doc', [
            'opportunity_id' => $rfp->id,
            'proposal_id' => $proposal->id,
            'file_id' => $fileId,
            'url' => $docUrl,
        ]);

        return $docUrl;
    }

    private function shareWithLink(string $token, string $fileId): void
    {
        Http::withToken($token)
            ->timeout(10)
            ->post(self::DRIVE_FILES_URL."/{$fileId}/permissions", [
                'role' => 'commenter',
                'type' => 'anyone',
            ]);
    }

    private function buildProposalHtml(RfpOpportunity $rfp, RfpProposal $proposal): string
    {
        $sections = $proposal->renderableSections();
        $renderableFullContent = $proposal->renderableFullContent();
        $pricing = $proposal->pricing_breakdown ?? [];
        $requirementResponses = $proposal->requirement_responses ?? [];

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 11pt; color: #000; line-height: 1.6; margin: 1in; }
  h1 { font-size: 20pt; color: #1e40af; }
  h2 { font-size: 14pt; color: #1e3a8a; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-top: 32px; }
  h3 { font-size: 12pt; color: #1e40af; }
  table { width: 100%; border-collapse: collapse; margin: 16px 0; }
  th { background: #1e3a8a; color: #fff; padding: 8px; text-align: left; }
  td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
  tr:nth-child(even) td { background: #f8fafc; }
  .meta { color: #64748b; font-size: 10pt; }
  .price { font-weight: bold; color: #1e40af; }
  blockquote { border-left: 3px solid #1e40af; padding-left: 12px; color: #475569; font-style: italic; }
</style>
</head>
<body>

<h1>{$proposal->title}</h1>
<p class="meta">
  Prepared for: <strong>{$rfp->issuing_organization}</strong><br>
  Version: {$proposal->version} &bull; Date: {$proposal->created_at->format('F j, Y')}
</p>
HTML;

        if ($proposal->executive_summary) {
            $html .= '<h2>Executive Summary</h2><p>'.nl2br(htmlspecialchars($proposal->executive_summary)).'</p>';
        }

        if (! empty($sections)) {
            foreach ($sections as $section) {
                $html .= '<h2>'.htmlspecialchars($section['title']).'</h2>';
                $html .= '<p>'.nl2br(htmlspecialchars($section['content'])).'</p>';
            }
        } elseif ($renderableFullContent) {
            $html .= '<h2>Proposed Solution</h2>';
            $html .= \Illuminate\Support\Str::markdown($renderableFullContent);
        }

        if (! empty($pricing)) {
            $html .= '<h2>Investment Summary</h2>';
            $html .= '<table><thead><tr><th>Item</th><th>Amount</th><th>Description</th></tr></thead><tbody>';
            foreach ($pricing as $item) {
                $name = htmlspecialchars($item['item'] ?? $item['name'] ?? '');
                $amount = '$'.number_format($item['total'] ?? $item['amount'] ?? $item['unit_price'] ?? 0, 0);
                $desc = htmlspecialchars($item['description'] ?? '');
                $html .= "<tr><td>{$name}</td><td class=\"price\">{$amount}</td><td>{$desc}</td></tr>";
            }
            $html .= '<tr><td><strong>Total</strong></td><td class="price"><strong>$'.number_format($proposal->total_price ?? 0, 0).'</strong></td><td></td></tr>';
            $html .= '</tbody></table>';
        }

        if (! empty($requirementResponses)) {
            $html .= '<h2>Requirements Compliance</h2>';
            $html .= '<table><thead><tr><th>Requirement</th><th>Our Response</th><th>Status</th></tr></thead><tbody>';
            foreach ($requirementResponses as $rr) {
                $req = htmlspecialchars($rr['requirement'] ?? '');
                $resp = htmlspecialchars($rr['response'] ?? '');
                $status = ($rr['met'] ?? true) !== false ? '✓ Met' : '~ Partial';
                $html .= "<tr><td>{$req}</td><td>{$resp}</td><td>{$status}</td></tr>";
            }
            $html .= '</tbody></table>';
        }

        $html .= '</body></html>';

        return $html;
    }
}
