<?php

use App\Models\Budget;
use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('budget page loads successfully', function () {
    $response = $this->get('/life/budget');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Life/Budget')
            ->has('budgetStatus')
            ->has('spendingBreakdown')
            ->has('budgets')
            ->has('categories')
            ->has('currentMonth')
        );
});

test('budget page accepts month parameter', function () {
    $response = $this->get('/life/budget?month=2025-06');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('currentMonth', '2025-06')
        );
});

test('store budget creates a new budget', function () {
    $category = TransactionCategory::factory()->expense()->create();

    $response = $this->post('/life/budget', [
        'category_id' => $category->id,
        'amount' => 500.00,
        'period_type' => 'monthly',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('budgets', [
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 500.00,
        'period_type' => 'monthly',
    ]);
});

test('store budget updates existing budget for same category', function () {
    $category = TransactionCategory::factory()->expense()->create();

    Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 300.00,
    ]);

    $response = $this->post('/life/budget', [
        'category_id' => $category->id,
        'amount' => 750.00,
        'period_type' => 'monthly',
    ]);

    $response->assertRedirect();

    expect(Budget::where('user_id', $this->user->id)->where('category_id', $category->id)->count())->toBe(1);

    $this->assertDatabaseHas('budgets', [
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => 750.00,
    ]);
});

test('store budget validates required fields', function () {
    $response = $this->post('/life/budget', []);

    $response->assertSessionHasErrors(['category_id', 'amount']);
});

test('store budget validates amount is numeric', function () {
    $category = TransactionCategory::factory()->expense()->create();

    $response = $this->post('/life/budget', [
        'category_id' => $category->id,
        'amount' => 'not-a-number',
    ]);

    $response->assertSessionHasErrors(['amount']);
});

test('store budget validates period_type enum', function () {
    $category = TransactionCategory::factory()->expense()->create();

    $response = $this->post('/life/budget', [
        'category_id' => $category->id,
        'amount' => 500,
        'period_type' => 'invalid',
    ]);

    $response->assertSessionHasErrors(['period_type']);
});

test('auto categorize endpoint works', function () {
    $response = $this->post('/life/auto-categorize');

    $response->assertRedirect()
        ->assertSessionHas('success');
});

test('auto categorize categorizes matching transactions', function () {
    $account = PersonalAccount::factory()->create(['user_id' => $this->user->id]);
    TransactionCategory::factory()->create(['name' => 'Groceries', 'type' => 'expense']);

    PersonalTransaction::factory()->create([
        'personal_account_id' => $account->id,
        'merchant_name' => 'Walmart',
        'category_id' => null,
    ]);

    $response = $this->post('/life/auto-categorize');

    $response->assertRedirect()
        ->assertSessionHas('success');

    $uncategorized = PersonalTransaction::whereHas('account', fn ($q) => $q->where('user_id', $this->user->id))
        ->whereNull('category_id')
        ->count();

    expect($uncategorized)->toBe(0);
});

test('budget page requires authentication', function () {
    auth()->logout();

    $this->get('/life/budget')->assertRedirect('/login');
});
