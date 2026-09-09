<?php

use App\Events\InteractionResponseReceived;
use App\Jobs\RunInteractiveAgentJob;
use App\Models\AgentRun;
use App\Models\InteractionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('respond endpoint', function () {
    test('successfully records a response to a pending interaction', function () {
        Queue::fake();
        Event::fake([InteractionResponseReceived::class]);

        $interaction = InteractionRequest::factory()->pending()->create();

        $response = $this->actingAs($this->user)
            ->postJson(route('api.interactions.respond', $interaction), [
                'response' => 'Option A',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Response recorded successfully')
            ->assertJsonPath('interaction.id', $interaction->id)
            ->assertJsonPath('interaction.response', 'Option A')
            ->assertJsonPath('interaction.is_responded', true)
            ->assertJsonPath('interaction.is_pending', false);

        $interaction->refresh();
        expect($interaction->response)->toBe('Option A')
            ->and($interaction->responded_at)->not->toBeNull()
            ->and($interaction->responded_via)->toBe('dashboard')
            ->and($interaction->responded_by_id)->toBe($this->user->id);

        Event::assertDispatched(InteractionResponseReceived::class);
    });

    test('returns already responded when interaction was already answered', function () {
        $interaction = InteractionRequest::factory()->responded()->create();

        $response = $this->actingAs($this->user)
            ->postJson(route('api.interactions.respond', $interaction), [
                'response' => 'Another answer',
            ]);

        $response->assertOk()
            ->assertJsonPath('already_responded', true)
            ->assertJsonPath('message', 'Response already recorded by another user');
    });

    test('returns validation error when interaction is expired', function () {
        $interaction = InteractionRequest::factory()->expired()->create();

        $response = $this->actingAs($this->user)
            ->postJson(route('api.interactions.respond', $interaction), [
                'response' => 'Too late',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['interaction']);
    });

    test('handles idempotent requests with same idempotency key', function () {
        $interaction = InteractionRequest::factory()->pending()->create();
        $idempotencyKey = 'unique-key-123';

        // First request
        $this->actingAs($this->user)
            ->postJson(route('api.interactions.respond', $interaction), [
                'response' => 'First answer',
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertOk();

        // Second request with same idempotency key
        $response = $this->actingAs($this->user)
            ->postJson(route('api.interactions.respond', $interaction), [
                'response' => 'Duplicate answer',
                'idempotency_key' => $idempotencyKey,
            ]);

        $response->assertOk()
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('message', 'Response already recorded');

        // Response should still be first answer
        $interaction->refresh();
        expect($interaction->response)->toBe('First answer');
    });

    test('validates response is required', function () {
        $interaction = InteractionRequest::factory()->pending()->create();

        $response = $this->actingAs($this->user)
            ->postJson(route('api.interactions.respond', $interaction), [
                'response' => '',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['response']);
    });

    test('validates response max length', function () {
        $interaction = InteractionRequest::factory()->pending()->create();

        $response = $this->actingAs($this->user)
            ->postJson(route('api.interactions.respond', $interaction), [
                'response' => str_repeat('a', 10001),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['response']);
    });

    test('requires authentication', function () {
        $interaction = InteractionRequest::factory()->pending()->create();

        $response = $this->postJson(route('api.interactions.respond', $interaction), [
            'response' => 'Answer',
        ]);

        $response->assertUnauthorized();
    });

    test('dispatches resume job when agent run can resume', function () {
        Queue::fake();
        Event::fake();

        $agentRun = AgentRun::factory()
            ->state(['status' => 'awaiting_input', 'checkpoint' => ['config' => ['timeout' => 300]]])
            ->create();
        $interaction = InteractionRequest::factory()
            ->pending()
            ->create(['agent_run_id' => $agentRun->id]);

        $this->actingAs($this->user)
            ->postJson(route('api.interactions.respond', $interaction), [
                'response' => 'Yes, proceed',
            ])
            ->assertOk();

        Queue::assertPushed(RunInteractiveAgentJob::class, function ($job) use ($agentRun) {
            return $job->run->id === $agentRun->id;
        });
    });
});

describe('show endpoint', function () {
    test('returns interaction details for pending interaction', function () {
        $interaction = InteractionRequest::factory()
            ->pending()
            ->select()
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson(route('api.interactions.show', $interaction));

        $response->assertOk()
            ->assertJsonStructure([
                'interaction' => [
                    'id',
                    'run_id',
                    'question_type',
                    'question',
                    'options',
                    'context',
                    'response',
                    'responded_at',
                    'expires_at',
                    'is_expired',
                    'is_responded',
                    'is_pending',
                    'remaining_seconds',
                    'created_at',
                ],
            ])
            ->assertJsonPath('interaction.id', $interaction->id)
            ->assertJsonPath('interaction.is_pending', true)
            ->assertJsonPath('interaction.question_type', 'select');
    });

    test('returns interaction details for responded interaction', function () {
        $interaction = InteractionRequest::factory()
            ->responded()
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson(route('api.interactions.show', $interaction));

        $response->assertOk()
            ->assertJsonPath('interaction.is_responded', true)
            ->assertJsonPath('interaction.is_pending', false);
    });

    test('returns interaction details for expired interaction', function () {
        $interaction = InteractionRequest::factory()
            ->expired()
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson(route('api.interactions.show', $interaction));

        $response->assertOk()
            ->assertJsonPath('interaction.is_expired', true)
            ->assertJsonPath('interaction.is_pending', false);
    });

    test('requires authentication', function () {
        $interaction = InteractionRequest::factory()->create();

        $response = $this->getJson(route('api.interactions.show', $interaction));

        $response->assertUnauthorized();
    });

    test('returns 404 for non-existent interaction', function () {
        $response = $this->actingAs($this->user)
            ->getJson(route('api.interactions.show', 99999));

        $response->assertNotFound();
    });
});

describe('pending endpoint', function () {
    test('returns only pending interactions ordered by expiration', function () {
        // Create in random order
        $pending1 = InteractionRequest::factory()->pending()->create([
            'expires_at' => now()->addMinutes(10),
        ]);
        $pending2 = InteractionRequest::factory()->pending()->create([
            'expires_at' => now()->addMinutes(5),
        ]);
        $pending3 = InteractionRequest::factory()->pending()->create([
            'expires_at' => now()->addMinutes(15),
        ]);
        InteractionRequest::factory()->responded()->create();
        InteractionRequest::factory()->expired()->create();

        $response = $this->actingAs($this->user)
            ->getJson(route('api.interactions.pending'));

        $response->assertOk()
            ->assertJsonCount(3, 'interactions');

        // Should be ordered by expires_at ascending (soonest first)
        $ids = collect($response->json('interactions'))->pluck('id')->toArray();
        expect($ids[0])->toBe($pending2->id)
            ->and($ids[1])->toBe($pending1->id)
            ->and($ids[2])->toBe($pending3->id);
    });

    test('limits results to 10 interactions', function () {
        InteractionRequest::factory()->pending()->count(15)->create();

        $response = $this->actingAs($this->user)
            ->getJson(route('api.interactions.pending'));

        $response->assertOk()
            ->assertJsonCount(10, 'interactions');
    });

    test('returns empty array when no pending interactions', function () {
        InteractionRequest::factory()->responded()->count(3)->create();
        InteractionRequest::factory()->expired()->count(2)->create();

        $response = $this->actingAs($this->user)
            ->getJson(route('api.interactions.pending'));

        $response->assertOk()
            ->assertJsonCount(0, 'interactions');
    });

    test('requires authentication', function () {
        $response = $this->getJson(route('api.interactions.pending'));

        $response->assertUnauthorized();
    });

    test('includes agent information in response', function () {
        $interaction = InteractionRequest::factory()->pending()->create();

        $response = $this->actingAs($this->user)
            ->getJson(route('api.interactions.pending'));

        $response->assertOk()
            ->assertJsonStructure([
                'interactions' => [
                    '*' => [
                        'id',
                        'run_id',
                        'agent_id',
                        'agent_name',
                        'question_type',
                        'question',
                    ],
                ],
            ]);
    });
});
