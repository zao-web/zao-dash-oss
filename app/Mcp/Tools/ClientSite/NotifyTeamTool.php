<?php

namespace App\Mcp\Tools\ClientSite;

use App\Models\Client;
use App\Services\Slack\SlackApiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Client-scoped Slack escalation. A deployed client site (e.g. the Zao Assistant
 * widget) posts a question/escalation to the team, and it lands in THIS client's
 * own Slack channel — resolved from the token, never from a parameter. There is
 * no channel or client id in the input, so one client can never post into
 * another's channel.
 *
 * The message is sent with the primary workspace bot token (chat.postMessage), so
 * no Slack secret ever has to live on the client site. Because it posts into the
 * client's monitored channel, the retainer report's Slack synthesis picks it up
 * automatically — a routed request is logged just by being sent.
 */
class NotifyTeamTool extends Tool
{
    protected string $name = 'notify-team';

    protected string $title = 'Notify the Team (scoped Slack)';

    protected string $description = "Post a question or escalation to THIS client's Slack channel so the Zao team can pick it up. Use when the assistant can't answer or the user asks for a human.";

    public function __construct(
        protected SlackApiService $slack
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'title' => 'required|string|max:200',
            'message' => 'required|string',
            'link' => 'nullable|url',
        ]);

        /** @var Client $client */
        $client = $request->user();

        // Resolve the channel from the token's client ONLY. No channel id is ever
        // accepted from the caller, so cross-client posting is impossible.
        $channel = $client->slackChannel?->slack_id;
        if (! $channel) {
            return Response::structured([
                'success' => false,
                'message' => 'No Slack channel is linked to this client, so there is nowhere to route the message. Link one on the client profile in Zao.',
            ]);
        }

        $title = Str::limit($request->get('title'), 150);
        $body = Str::limit((string) $request->get('message'), 2800);
        $link = $request->get('link');

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '❓ '.$title, 'emoji' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $body]],
            ['type' => 'context', 'elements' => [
                ['type' => 'mrkdwn', 'text' => '🤖 Routed from *'.$client->name.'* via the Zao Assistant'],
            ]],
        ];
        if ($link) {
            $blocks[2]['elements'][] = ['type' => 'mrkdwn', 'text' => '<'.$link.'|Open the page>'];
        }

        try {
            $result = $this->slack->postMessageDirect($channel, '❓ '.$title, null, $blocks);
        } catch (\Throwable $e) {
            return Response::structured([
                'success' => false,
                'message' => 'Slack rejected the message: '.$e->getMessage(),
            ]);
        }

        return Response::structured([
            'success' => true,
            'message' => 'Posted to the team in this client\'s Slack channel.',
            'ts' => $result['ts'] ?? null,
            'channel' => $channel,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Short headline for the escalation, e.g. the user\'s question.'),
            'message' => $schema->string()->required()->description('The full message body (markdown): the question and any transcript/context.'),
            'link' => $schema->string()->description('Optional URL to the page the user was on.'),
        ];
    }
}
