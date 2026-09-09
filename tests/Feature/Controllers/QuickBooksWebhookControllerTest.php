<?php

use App\Jobs\SyncQuickBooksJob;
use App\Models\QboInvoice;
use App\Models\QboTransaction;
use App\Models\QuickBooksConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.quickbooks.webhook_verifier_token' => 'test-verifier-token']);
});

test('rejects webhook with invalid signature', function () {
    $payload = json_encode([
        'eventNotifications' => [],
    ]);

    $response = $this->postJson('/webhooks/quickbooks', json_decode($payload, true), [
        'intuit-signature' => 'invalid-signature',
    ]);

    $response->assertStatus(401);
    $response->assertJson(['error' => 'Invalid signature']);
});

test('accepts webhook with valid signature', function () {
    $payload = json_encode([
        'eventNotifications' => [],
    ]);

    $expectedSignature = base64_encode(hash_hmac('sha256', $payload, config('services.quickbooks.webhook_verifier_token'), true));

    $response = $this->postJson('/webhooks/quickbooks', json_decode($payload, true), [
        'intuit-signature' => $expectedSignature,
    ]);

    $response->assertStatus(200);
    $response->assertJson(['status' => 'ok']);
});

test('skips verification when no verifier token configured', function () {
    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [],
    ]);

    $response->assertStatus(200);
});

test('handles invoice created notification', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Invoice',
                            'id' => 'INV-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class);
});

test('handles invoice updated notification', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Invoice',
                            'id' => 'INV-001',
                            'operation' => 'Update',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class);
});

test('handles invoice deleted notification', function () {
    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    $invoice = QboInvoice::factory()->create([
        'quickbooks_connection_id' => $connection->id,
        'qbo_invoice_id' => 'INV-001',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    expect(QboInvoice::where('qbo_invoice_id', 'INV-001')->exists())->toBeTrue();

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Invoice',
                            'id' => 'INV-001',
                            'operation' => 'Delete',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    expect(QboInvoice::where('qbo_invoice_id', 'INV-001')->exists())->toBeFalse();
});

test('handles payment created notification', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Payment',
                            'id' => 'PMT-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class);
});

test('handles payment updated notification', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Payment',
                            'id' => 'PMT-001',
                            'operation' => 'Update',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class);
});

test('handles customer created notification', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Customer',
                            'id' => 'CUST-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class);
});

test('handles purchase created notification', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Purchase',
                            'id' => 'PURCH-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class);
});

test('handles purchase deleted notification', function () {
    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    $transaction = QboTransaction::factory()->create([
        'quickbooks_connection_id' => $connection->id,
        'qbo_transaction_id' => 'PURCH-001',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    expect(QboTransaction::where('qbo_transaction_id', 'PURCH-001')->exists())->toBeTrue();

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Purchase',
                            'id' => 'PURCH-001',
                            'operation' => 'Delete',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    expect(QboTransaction::where('qbo_transaction_id', 'PURCH-001')->exists())->toBeFalse();
});

test('handles bill created notification', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Bill',
                            'id' => 'BILL-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class);
});

test('handles multiple notifications in single request', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Invoice',
                            'id' => 'INV-001',
                            'operation' => 'Create',
                        ],
                        [
                            'name' => 'Payment',
                            'id' => 'PMT-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class, 2);
});

test('ignores notifications for unknown realm', function () {
    Queue::fake();

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '999999999',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Invoice',
                            'id' => 'INV-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertNotPushed(SyncQuickBooksJob::class);
});

test('handles unhandled entity types gracefully', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'UnknownEntity',
                            'id' => 'UNK-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
});

test('handles notification with missing realm id', function () {
    Queue::fake();

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Invoice',
                            'id' => 'INV-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertNotPushed(SyncQuickBooksJob::class);
});

test('handles notification with missing data change event', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertNotPushed(SyncQuickBooksJob::class);
});

test('handles entity with missing id', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Invoice',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
});

test('dispatches sync job to integrations queue', function () {
    Queue::fake();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'user_id' => $user->id,
        'realm_id' => '123456789',
    ]);

    config(['services.quickbooks.webhook_verifier_token' => null]);

    $response = $this->postJson('/webhooks/quickbooks', [
        'eventNotifications' => [
            [
                'realmId' => '123456789',
                'dataChangeEvent' => [
                    'entities' => [
                        [
                            'name' => 'Invoice',
                            'id' => 'INV-001',
                            'operation' => 'Create',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(200);
    Queue::assertPushed(SyncQuickBooksJob::class, function ($job) {
        return $job->queue === 'integrations';
    });
});
