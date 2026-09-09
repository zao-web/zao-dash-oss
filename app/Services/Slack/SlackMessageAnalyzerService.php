<?php

namespace App\Services\Slack;

use App\Models\Client;
use App\Models\Project;
use App\Models\SlackMessage;
use App\Models\SlackRequestPattern;
use App\Models\SlackThread;
use App\Models\Task;
use App\Services\AI\AnthropicService;
use App\Services\Leads\LeadDetectionService;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered Slack message analysis service.
 *
 * Provides:
 * - Action item detection with confidence scoring
 * - Intent classification (question, request, update, etc.)
 * - Urgency detection
 * - Repeated request detection via semantic similarity
 * - Thread summarization
 * - Automatic task creation
 */
class SlackMessageAnalyzerService
{
    private const CACHE_TTL = 3600; // 1 hour

    private const ACTION_CONFIDENCE_THRESHOLD = 0.7;

    private const SIMILARITY_THRESHOLD = 0.85;

    public function __construct(
        protected AnthropicService $ai,
        protected LeadDetectionService $leadDetection
    ) {}

    /**
     * Analyze a single message for action items and intent.
     */
    public function analyzeMessage(SlackMessage $message): array
    {
        // Skip very short messages or bot messages
        if (strlen($message->content ?? '') < 10) {
            return $this->markProcessed($message, [
                'has_action_item' => false,
                'skipped' => true,
                'reason' => 'too_short',
            ]);
        }

        // Build context about the channel and client
        $context = $this->buildMessageContext($message);

        $prompt = $this->buildAnalysisPrompt($message, $context);

        try {
            $response = $this->ai->message(
                $prompt,
                $this->getAnalysisSystemPrompt(),
                [],
                'claude-3-5-haiku-20241022' // Use Haiku for cost efficiency
            );

            $analysis = $this->parseAnalysisResponse($response);

            // Update message with analysis
            $message->update([
                'has_action_item' => $analysis['has_action_item'],
                'action_item_extracted' => $analysis['action_item'] ?? null,
                'action_item_confidence' => $analysis['confidence'] ?? 0,
                'processed_at' => now(),
            ]);

            // Check for repeated requests if this is from an external user
            if ($analysis['is_question'] && $message->user_is_external && $message->client_id) {
                $this->checkForRepeatedRequest($message, $analysis);
            }

            // Check for lead potential from external users not linked to existing clients
            if ($message->user_is_external && ! $message->client_id) {
                $this->detectAndCreateLead($message, $context);
            }

            return $analysis;
        } catch (\Exception $e) {
            Log::error('Slack message analysis failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            // Mark as processed to avoid infinite retries
            $message->update(['processed_at' => now()]);

            return [
                'has_action_item' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Batch analyze multiple messages efficiently.
     */
    public function analyzeMessages(array $messageIds, int $batchSize = 10): array
    {
        $results = [];
        $messages = SlackMessage::whereIn('id', $messageIds)
            ->whereNull('processed_at')
            ->get();

        foreach ($messages->chunk($batchSize) as $batch) {
            // For efficiency, analyze in batches using a single prompt
            $batchResults = $this->analyzeBatch($batch);
            $results = array_merge($results, $batchResults);
        }

        return $results;
    }

    /**
     * Analyze a batch of messages in a single AI call.
     */
    protected function analyzeBatch($messages): array
    {
        if ($messages->isEmpty()) {
            return [];
        }

        $prompt = "Analyze the following Slack messages and return a JSON array with analysis for each:\n\n";

        foreach ($messages as $i => $message) {
            $context = $this->buildMessageContext($message);
            $prompt .= "--- MESSAGE {$i} ---\n";
            $prompt .= "From: {$message->user_name}".($message->user_is_external ? ' (external)' : '')."\n";
            $prompt .= "Channel: {$context['channel_name']} ({$context['channel_type']})\n";
            if ($context['client_name']) {
                $prompt .= "Client: {$context['client_name']}\n";
            }
            $prompt .= "Content: {$message->content}\n\n";
        }

        $prompt .= <<<'PROMPT'

Return a JSON array where each element has:
{
  "message_index": <number>,
  "has_action_item": <boolean>,
  "action_item": <string or null - the extracted action>,
  "confidence": <0-1 float>,
  "intent": <"question"|"request"|"update"|"information"|"discussion"|"other">,
  "urgency": <"critical"|"high"|"normal"|"low">,
  "is_question": <boolean>,
  "topic_summary": <brief topic in 5-10 words>,
  "suggested_assignee": <null or "internal_team"|"client"|"specific_person">
}
PROMPT;

        try {
            $response = $this->ai->message(
                $prompt,
                $this->getAnalysisSystemPrompt(),
                [],
                'claude-3-5-haiku-20241022'
            );

            $content = $this->extractTextContent($response);
            $analyses = json_decode($content, true);

            if (! is_array($analyses)) {
                throw new \Exception('Invalid JSON response from AI');
            }

            $results = [];
            foreach ($analyses as $analysis) {
                $index = $analysis['message_index'] ?? -1;
                if ($index >= 0 && isset($messages[$index])) {
                    $message = $messages[$index];

                    $message->update([
                        'has_action_item' => $analysis['has_action_item'] ?? false,
                        'action_item_extracted' => $analysis['action_item'] ?? null,
                        'action_item_confidence' => $analysis['confidence'] ?? 0,
                        'processed_at' => now(),
                    ]);

                    // Check for repeated requests
                    if (($analysis['is_question'] ?? false) && $message->user_is_external && $message->client_id) {
                        $this->checkForRepeatedRequest($message, $analysis);
                    }

                    $results[$message->id] = $analysis;
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error('Batch message analysis failed', ['error' => $e->getMessage()]);

            // Fall back to individual analysis
            $results = [];
            foreach ($messages as $message) {
                $results[$message->id] = $this->analyzeMessage($message);
            }

            return $results;
        }
    }

    /**
     * Summarize a thread.
     */
    public function summarizeThread(SlackThread $thread): array
    {
        $messages = $thread->messages()->with('channel')->orderBy('message_ts')->get();

        if ($messages->count() < 3) {
            return ['summary' => null, 'skipped' => true, 'reason' => 'too_few_messages'];
        }

        $channel = $thread->channel;
        $client = $channel->client;

        $prompt = "Summarize this Slack thread conversation:\n\n";
        $prompt .= "Channel: {$channel->name}\n";
        if ($client) {
            $prompt .= "Client: {$client->name}\n";
        }
        $prompt .= 'Participants: '.count($thread->participants ?? [])."\n";
        $prompt .= 'Has external participants: '.($thread->has_external_participant ? 'Yes' : 'No')."\n\n";
        $prompt .= "--- MESSAGES ---\n";

        foreach ($messages as $msg) {
            $external = $msg->user_is_external ? ' (external)' : '';
            $prompt .= "[{$msg->user_name}{$external}]: {$msg->content}\n";
        }

        $prompt .= <<<'PROMPT'

Provide a JSON response with:
{
  "summary": "<2-3 sentence summary of the thread>",
  "key_decisions": [<list of decisions made, if any>],
  "action_items": [
    {
      "description": "<what needs to be done>",
      "assignee_hint": "<who should do it - internal team, client, or specific name mentioned>",
      "urgency": "<high|normal|low>"
    }
  ],
  "unresolved_questions": [<list of questions that weren't answered>],
  "sentiment": "<positive|neutral|negative|frustrated>",
  "needs_followup": <boolean>
}
PROMPT;

        try {
            $response = $this->ai->message(
                $prompt,
                $this->getSummarizationSystemPrompt(),
                [],
                'claude-3-5-haiku-20241022'
            );

            $content = $this->extractTextContent($response);
            $analysis = json_decode($content, true);

            if (! is_array($analysis)) {
                throw new \Exception('Invalid JSON response');
            }

            // Update thread
            $thread->update([
                'summary' => $analysis['summary'] ?? null,
                'action_items_extracted' => $analysis['action_items'] ?? [],
            ]);

            return $analysis;
        } catch (\Exception $e) {
            Log::error('Thread summarization failed', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Detect and create lead from Slack message.
     */
    protected function detectAndCreateLead(SlackMessage $message, array $context): void
    {
        $content = $message->content ?? '';
        if (strlen($content) < 30) {
            return;
        }

        $channel = $message->channel;
        $isDm = $channel?->is_im || $channel?->classification === 'dm';

        $leadData = $this->leadDetection->detectFromText($content, [
            'source' => $isDm ? 'slack_dm' : 'slack',
            'from_name' => $message->user_name,
            'channel' => $context['channel_name'] ?? 'unknown',
        ]);

        if (! $leadData) {
            return;
        }

        $leadData['contact_name'] = $leadData['name'] ?? $message->user_name;

        $lead = $this->leadDetection->createLead(
            data: array_merge($leadData, [
                'original_content' => "Slack ({$context['channel_name']}): {$content}",
            ]),
            source: $isDm ? 'slack_dm' : 'slack',
            sourceId: "slack:{$message->id}"
        );

        Log::info('Lead created from Slack message', [
            'message_id' => $message->id,
            'lead_id' => $lead->id,
            'channel' => $context['channel_name'],
            'is_dm' => $isDm,
        ]);
    }

    /**
     * Check if a message is a repeated request from the same client.
     */
    protected function checkForRepeatedRequest(SlackMessage $message, array $analysis): void
    {
        $topicSummary = $analysis['topic_summary'] ?? null;
        if (! $topicSummary || ! $message->client_id) {
            return;
        }

        // Look for similar recent patterns from this client
        $recentPatterns = SlackRequestPattern::where('client_id', $message->client_id)
            ->where('is_resolved', false)
            ->where('last_asked_at', '>=', now()->subDays(30))
            ->get();

        foreach ($recentPatterns as $pattern) {
            // Simple similarity check using topic summary
            // In production, you'd use embeddings for semantic similarity
            $similarity = $this->calculateTopicSimilarity($topicSummary, $pattern->topic_summary);

            if ($similarity >= self::SIMILARITY_THRESHOLD) {
                // This is a repeated request
                $pattern->addMessage($message->message_ts);

                $message->update([
                    'is_repeated_request' => true,
                    'repeated_request_count' => $pattern->ask_count,
                ]);

                Log::info('Detected repeated request', [
                    'message_id' => $message->id,
                    'pattern_id' => $pattern->id,
                    'ask_count' => $pattern->ask_count,
                    'client_id' => $message->client_id,
                ]);

                // Update client health if significant repetition
                if ($pattern->ask_count >= 3) {
                    $this->updateClientHealth($message->client_id, $pattern);
                }

                return;
            }
        }

        // New pattern - create it
        SlackRequestPattern::create([
            'client_id' => $message->client_id,
            'topic_summary' => $topicSummary,
            'first_asked_at' => now(),
            'last_asked_at' => now(),
            'ask_count' => 1,
            'messages' => [$message->message_ts],
            'is_resolved' => false,
        ]);
    }

    /**
     * Create a task from an action item.
     */
    public function createTaskFromMessage(SlackMessage $message, array $options = []): ?Task
    {
        if (! $message->has_action_item || ! $message->action_item_extracted) {
            return null;
        }

        $channel = $message->channel;
        $client = $message->client ?? $channel?->client;

        // Find project - prefer channel's linked project, then intelligently select from client's projects
        $project = $channel?->project;
        $projectSelectionMethod = $project ? 'channel_linked' : null;

        if (! $project && $client) {
            $result = $this->selectBestProject($client, $message, $channel);
            $project = $result['project'];
            $projectSelectionMethod = $result['method'];
        }

        // Create notification if we have a client but no project
        if ($client && ! $project) {
            Log::info('Action item detected for client with no active project', [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'channel' => $channel?->name,
            ]);

            \App\Models\Notification::slackActionItem(
                client: $client,
                actionItem: $message->action_item_extracted,
                channel: $channel?->name ?? 'unknown',
                permalink: $message->permalink,
                suggestion: "Action item from {$client->name} but no active project exists. Consider creating a project first.",
            );
        }

        // Build task title from action item
        $title = $this->generateTaskTitle($message->action_item_extracted);

        // Build description with context
        $description = "**Action from Slack**\n\n";
        $description .= "> {$message->content}\n\n";
        $description .= "**From:** {$message->user_name}\n";
        $description .= "**Channel:** #{$channel->name}\n";
        $description .= "**Link:** {$message->permalink}\n";

        if (! empty($message->action_item_extracted)) {
            $description .= "\n**Extracted Action:**\n{$message->action_item_extracted}";
        }

        $task = Task::create([
            'title' => $title,
            'description' => $description,
            'project_id' => $project?->id,
            'status' => 'pending',
            'priority' => $options['priority'] ?? $this->mapUrgencyToPriority($options['urgency'] ?? 'normal'),
            'source' => 'ai',
            'metadata' => [
                'slack_message_id' => $message->id,
                'slack_message_ts' => $message->message_ts,
                'slack_channel_id' => $channel->id,
                'slack_permalink' => $message->permalink,
                'confidence' => $message->action_item_confidence,
                'created_from' => 'slack_analysis',
            ],
        ]);

        Log::info('Created task from Slack message', [
            'task_id' => $task->id,
            'message_id' => $message->id,
            'project_id' => $project?->id,
            'project_selection_method' => $projectSelectionMethod,
        ]);

        return $task;
    }

    /**
     * Select the best project for a task based on message content and available projects.
     *
     * @return array{project: Project|null, method: string}
     */
    protected function selectBestProject(Client $client, SlackMessage $message, $channel): array
    {
        $projects = $client->projects()
            ->where('status', '!=', 'archived')
            ->orderByDesc('updated_at')
            ->get();

        if ($projects->isEmpty()) {
            return ['project' => null, 'method' => 'no_projects'];
        }

        if ($projects->count() === 1) {
            return ['project' => $projects->first(), 'method' => 'single_project'];
        }

        $messageContent = $message->content ?? '';
        $actionItem = $message->action_item_extracted ?? '';
        $channelName = $channel?->name ?? '';

        $projectsInfo = $projects->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'github_repo' => $p->github_repo,
            'type' => $p->type,
        ])->toArray();

        $prompt = <<<PROMPT
Given this Slack message and action item, which project is most likely the correct one to assign this task to?

**Slack Channel:** #{$channelName}
**Message:** {$messageContent}
**Extracted Action Item:** {$actionItem}

**Available Projects for {$client->name}:**
PROMPT;

        foreach ($projectsInfo as $p) {
            $prompt .= "\n- ID {$p['id']}: {$p['name']}";
            if ($p['description']) {
                $prompt .= " - {$p['description']}";
            }
            if ($p['github_repo']) {
                $prompt .= " (repo: {$p['github_repo']})";
            }
        }

        $prompt .= "\n\nRespond with ONLY a JSON object: {\"project_id\": <id>, \"confidence\": <0-1>, \"reason\": \"<brief reason>\"}";
        $prompt .= "\nIf no project is clearly a better match, pick the most general/main project.";

        try {
            $response = $this->ai->message(
                $prompt,
                'You are helping route tasks to the correct project. Be decisive - always pick the most likely project. Return only valid JSON.',
                [],
                'claude-3-5-haiku-20241022'
            );

            $content = $this->extractTextContent($response);
            $result = json_decode($content, true);

            if (isset($result['project_id'])) {
                $selectedProject = $projects->firstWhere('id', $result['project_id']);

                if ($selectedProject) {
                    Log::info('AI selected project for Slack task', [
                        'client_id' => $client->id,
                        'selected_project_id' => $selectedProject->id,
                        'selected_project_name' => $selectedProject->name,
                        'confidence' => $result['confidence'] ?? 'unknown',
                        'reason' => $result['reason'] ?? 'none provided',
                        'available_projects' => $projects->pluck('name', 'id')->toArray(),
                    ]);

                    return ['project' => $selectedProject, 'method' => 'ai_selected'];
                }
            }
        } catch (\Exception $e) {
            Log::warning('AI project selection failed, falling back to most recent', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }

        $fallbackProject = $projects->first();

        Log::info('Using fallback project selection (most recently updated)', [
            'client_id' => $client->id,
            'project_id' => $fallbackProject->id,
            'project_name' => $fallbackProject->name,
        ]);

        return ['project' => $fallbackProject, 'method' => 'fallback_most_recent'];
    }

    /**
     * Build context for message analysis.
     */
    protected function buildMessageContext(SlackMessage $message): array
    {
        $channel = $message->channel;
        $client = $message->client ?? $channel?->client;

        return [
            'channel_name' => $channel?->name ?? $channel?->channel_name ?? 'unknown',
            'channel_type' => $channel?->classification ?? 'general',
            'client_name' => $client?->name,
            'is_shared_channel' => $channel?->is_shared ?? false,
            'is_client_channel' => $channel?->isClientChannel() ?? false,
        ];
    }

    /**
     * Build the analysis prompt for a single message.
     */
    protected function buildAnalysisPrompt(SlackMessage $message, array $context): string
    {
        $prompt = "Analyze this Slack message:\n\n";
        $prompt .= "From: {$message->user_name}".($message->user_is_external ? ' (external user)' : '')."\n";
        $prompt .= "Channel: {$context['channel_name']} ({$context['channel_type']})\n";

        if ($context['client_name']) {
            $prompt .= "Client: {$context['client_name']}\n";
        }

        $prompt .= "\nMessage:\n{$message->content}\n\n";

        $prompt .= <<<'PROMPT'
Analyze and return JSON:
{
  "has_action_item": <boolean - is there something that needs to be done?>,
  "action_item": <string or null - clear description of what needs to be done>,
  "confidence": <0-1 float - how confident are you this is an action item>,
  "intent": <"question"|"request"|"update"|"information"|"discussion"|"other">,
  "urgency": <"critical"|"high"|"normal"|"low">,
  "is_question": <boolean>,
  "topic_summary": <brief topic in 5-10 words>
}

Consider:
- Direct requests ("can you...", "please...", "we need...")
- Questions that imply work ("when will...", "is this ready...")
- Deadlines or time pressure
- External user messages in client channels are higher priority
PROMPT;

        return $prompt;
    }

    /**
     * Get the system prompt for analysis.
     */
    protected function getAnalysisSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an expert at analyzing Slack messages for an agency that works with multiple clients.

Your job is to identify:
1. Action items - things that need to be done
2. Questions that need answers
3. Urgency levels based on language and context
4. The core topic being discussed

Rules:
- Be conservative with action item detection - only flag clear, actionable items
- External user messages (from clients) in shared channels are important
- Questions from clients often imply work needs to be done
- Consider implied urgency from phrases like "ASAP", "urgent", "blocking", deadline mentions
- Return valid JSON only, no markdown code blocks
PROMPT;
    }

    /**
     * Get the system prompt for thread summarization.
     */
    protected function getSummarizationSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an expert at summarizing Slack conversations for an agency.

Your summaries should:
1. Capture the key discussion points
2. Identify any decisions made
3. Extract action items with assignee hints
4. Note unresolved questions
5. Assess the overall sentiment

Focus on what's actionable and important. Be concise but complete.
Return valid JSON only, no markdown code blocks.
PROMPT;
    }

    /**
     * Parse the AI response to extract analysis.
     */
    protected function parseAnalysisResponse(array $response): array
    {
        $content = $this->extractTextContent($response);

        // Try to parse as JSON
        $data = json_decode($content, true);

        if (! is_array($data)) {
            // Try to extract JSON from the response
            if (preg_match('/\{[^}]+\}/s', $content, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        return $data ?? [
            'has_action_item' => false,
            'confidence' => 0,
            'error' => 'Failed to parse response',
        ];
    }

    /**
     * Extract text content from Anthropic response.
     */
    protected function extractTextContent(array $response): string
    {
        $content = $response['content'] ?? [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'] ?? '';
            }
        }

        return '';
    }

    /**
     * Calculate topic similarity (simple implementation).
     * In production, use embeddings.
     */
    protected function calculateTopicSimilarity(string $topic1, string $topic2): float
    {
        $words1 = array_map('strtolower', str_word_count($topic1, 1));
        $words2 = array_map('strtolower', str_word_count($topic2, 1));

        $intersection = count(array_intersect($words1, $words2));
        $union = count(array_unique(array_merge($words1, $words2)));

        return $union > 0 ? $intersection / $union : 0;
    }

    /**
     * Update client health score based on repeated requests.
     */
    protected function updateClientHealth(int $clientId, SlackRequestPattern $pattern): void
    {
        $client = Client::find($clientId);
        if (! $client) {
            return;
        }

        $impact = $pattern->getHealthImpact();
        $currentHealth = $client->health_score ?? 10.0;
        $newHealth = max(0, $currentHealth - $impact);

        $client->update(['health_score' => $newHealth]);

        Log::warning('Client health decreased due to repeated requests', [
            'client_id' => $clientId,
            'previous_health' => $currentHealth,
            'new_health' => $newHealth,
            'pattern_id' => $pattern->id,
            'ask_count' => $pattern->ask_count,
        ]);
    }

    /**
     * Generate a concise task title from action item.
     */
    protected function generateTaskTitle(string $actionItem): string
    {
        // Truncate to reasonable length
        $title = substr($actionItem, 0, 100);

        // Remove common prefixes
        $title = preg_replace('/^(please |can you |could you |we need to |need to )/i', '', $title);

        // Capitalize first letter
        $title = ucfirst(trim($title));

        // Add ellipsis if truncated
        if (strlen($actionItem) > 100) {
            $title .= '...';
        }

        return $title;
    }

    /**
     * Map urgency level to task priority.
     */
    protected function mapUrgencyToPriority(string $urgency): string
    {
        return match ($urgency) {
            'critical' => 'critical',
            'high' => 'high',
            'low' => 'low',
            default => 'medium',
        };
    }

    /**
     * Mark a message as processed with optional data.
     */
    protected function markProcessed(SlackMessage $message, array $data): array
    {
        $message->update([
            'processed_at' => now(),
            'has_action_item' => $data['has_action_item'] ?? false,
        ]);

        return $data;
    }
}
