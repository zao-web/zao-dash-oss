<?php

use App\Services\Reports\RetainerNarrativeService;

/**
 * Reach the protected extractJsonObject helper via a one-off subclass
 * exposing it. Lighter than reflection and easier to read.
 */
beforeEach(function () {
    $this->svc = new class(app(\App\Services\Reports\RetainerHealthService::class)) extends RetainerNarrativeService
    {
        public function extract(string $text): ?array
        {
            return $this->extractJsonObject($text);
        }
    };
});

it('parses bare JSON', function () {
    $r = $this->svc->extract('{"topics":[],"value_summary":"ok"}');
    expect($r)->toBe(['topics' => [], 'value_summary' => 'ok']);
});

it('parses JSON wrapped in ```json fences', function () {
    $text = "```json\n{\"topics\":[],\"value_summary\":\"ok\"}\n```";
    $r = $this->svc->extract($text);
    expect($r)->toBe(['topics' => [], 'value_summary' => 'ok']);
});

it('parses JSON wrapped in bare ``` fences', function () {
    $text = "```\n{\"topics\":[],\"value_summary\":\"ok\"}\n```";
    $r = $this->svc->extract($text);
    expect($r)->toBe(['topics' => [], 'value_summary' => 'ok']);
});

it('extracts JSON from a chatty preamble + trailing prose', function () {
    $text = "Here's the JSON response: {\"topics\":[],\"value_summary\":\"ok\"} Hope this helps!";
    $r = $this->svc->extract($text);
    expect($r)->toBe(['topics' => [], 'value_summary' => 'ok']);
});

it('returns null on totally non-JSON garbage', function () {
    expect($this->svc->extract('I am sorry, I cannot help with that.'))->toBeNull();
});

it('returns null on empty input', function () {
    expect($this->svc->extract(''))->toBeNull();
    expect($this->svc->extract('   '))->toBeNull();
});

it('handles nested objects correctly', function () {
    $text = '{"topics":[{"title":"x","estimated_hours":2.5}],"value_summary":"hi"}';
    $r = $this->svc->extract($text);
    expect($r['topics'][0]['title'])->toBe('x');
    expect($r['topics'][0]['estimated_hours'])->toBe(2.5);
});
