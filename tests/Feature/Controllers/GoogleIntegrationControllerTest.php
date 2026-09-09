<?php

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\Google\CalendarService;
use App\Services\Google\DriveService;
use App\Services\Google\GmailService;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->oauthService = Mockery::mock(GoogleOAuthService::class);
    $this->gmailService = Mockery::mock(GmailService::class);
    $this->calendarService = Mockery::mock(CalendarService::class);
    $this->driveService = Mockery::mock(DriveService::class);

    $this->app->instance(GoogleOAuthService::class, $this->oauthService);
    $this->app->instance(GmailService::class, $this->gmailService);
    $this->app->instance(CalendarService::class, $this->calendarService);
    $this->app->instance(DriveService::class, $this->driveService);
});

// Status tests
test('status returns disconnected when no credential', function () {
    $response = $this->getJson('/api/integrations/google/status');

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => false,
        'email' => null,
        'scopes' => [],
    ]);
});

test('status returns connected with credential details', function () {
    $credential = GoogleCredential::factory()
        ->for($this->user)
        ->create([
            'email' => 'test@example.com',
            'scopes' => ['gmail', 'calendar'],
            'watch_expiration' => now()->addDays(7),
            'calendar_watch_expiration' => now()->addDays(7),
        ]);

    $response = $this->getJson('/api/integrations/google/status');

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => true,
        'email' => 'test@example.com',
        'scopes' => ['gmail', 'calendar'],
        'gmail_watch_active' => true,
        'calendar_watch_active' => true,
    ]);
});

test('status requires authentication', function () {
    auth()->logout();

    $response = $this->getJson('/api/integrations/google/status');

    $response->assertStatus(401);
});

// OAuth redirect tests
test('redirect generates oauth url with state', function () {
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?client_id=123';

    $this->oauthService->shouldReceive('getAuthUrl')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn($authUrl);

    $response = $this->get('/integrations/google/redirect');

    $response->assertRedirect($authUrl);
    expect(session()->has('google_oauth_state'))->toBeTrue();
});

// OAuth callback tests
test('callback validates state parameter', function () {
    session(['google_oauth_state' => 'valid-state']);

    $response = $this->get('/integrations/google/callback?state=invalid-state&code=auth-code');

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error');
});

test('callback handles oauth error', function () {
    session(['google_oauth_state' => 'valid-state']);

    $response = $this->get('/integrations/google/callback?state=valid-state&error=access_denied');

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('access_denied');
});

test('callback exchanges code for tokens and stores credentials', function () {
    $state = 'valid-state';
    session(['google_oauth_state' => $state]);

    $tokens = [
        'access_token' => 'ya29.test',
        'refresh_token' => '1//test',
        'expires_in' => 3600,
    ];

    $this->oauthService->shouldReceive('exchangeCodeForTokens')
        ->once()
        ->with('auth-code')
        ->andReturn($tokens);

    $this->oauthService->shouldReceive('storeCredentials')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $this->user->id), $tokens);

    $this->gmailService->shouldReceive('watchInbox')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $this->user->id));

    $this->calendarService->shouldReceive('watchCalendar')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $this->user->id));

    $response = $this->get("/integrations/google/callback?state={$state}&code=auth-code");

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('success');
    expect(session()->has('google_oauth_state'))->toBeFalse();
});

test('callback handles token exchange failure', function () {
    $state = 'valid-state';
    session(['google_oauth_state' => $state]);

    $this->oauthService->shouldReceive('exchangeCodeForTokens')
        ->once()
        ->andThrow(new Exception('Invalid code'));

    $response = $this->get("/integrations/google/callback?state={$state}&code=invalid-code");

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error');
});

// Disconnect tests
test('disconnect stops watch and revokes access', function () {
    $credential = GoogleCredential::factory()
        ->for($this->user)
        ->create();

    $this->gmailService->shouldReceive('stopWatch')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $this->user->id));

    $this->oauthService->shouldReceive('revokeAccess')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $credential->id));

    $response = $this->delete('/integrations/google/disconnect');

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('success');
});

test('disconnect handles missing credential', function () {
    $response = $this->delete('/integrations/google/disconnect');

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('success');
});

test('disconnect ignores watch stop errors', function () {
    $credential = GoogleCredential::factory()
        ->for($this->user)
        ->create();

    $this->gmailService->shouldReceive('stopWatch')
        ->once()
        ->andThrow(new Exception('Watch not found'));

    $this->oauthService->shouldReceive('revokeAccess')
        ->once();

    $response = $this->delete('/integrations/google/disconnect');

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('success');
});

// Sync emails tests
test('sync emails requires valid credentials', function () {
    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $this->user->id))
        ->andReturn(false);

    $response = $this->postJson('/api/integrations/google/sync-emails');

    $response->assertStatus(401);
    $response->assertJsonPath('error', 'Google account not connected');
});

test('sync emails fetches and stores messages', function () {
    GoogleCredential::factory()->for($this->user)->create();

    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(true);

    $messages = [
        'messages' => [
            ['id' => 'msg1'],
            ['id' => 'msg2'],
            ['id' => 'msg3'],
        ],
    ];

    $this->gmailService->shouldReceive('listMessages')
        ->once()
        ->andReturn($messages);

    $this->gmailService->shouldReceive('syncAndStoreEmail')
        ->times(3);

    $response = $this->postJson('/api/integrations/google/sync-emails');

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'synced' => 3,
    ]);
});

test('sync emails handles api errors', function () {
    GoogleCredential::factory()->for($this->user)->create();

    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(true);

    $this->gmailService->shouldReceive('listMessages')
        ->once()
        ->andThrow(new Exception('Gmail API error'));

    $response = $this->postJson('/api/integrations/google/sync-emails');

    $response->assertStatus(500);
});

// Sync calendar tests
test('sync calendar requires valid credentials', function () {
    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(false);

    $response = $this->postJson('/api/integrations/google/sync-calendar');

    $response->assertStatus(401);
});

test('sync calendar fetches and stores events', function () {
    GoogleCredential::factory()->for($this->user)->create();

    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(true);

    $this->calendarService->shouldReceive('syncEvents')
        ->once()
        ->andReturn(5);

    $response = $this->postJson('/api/integrations/google/sync-calendar');

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 5);
});

// Discover documents tests
test('discover documents requires valid credentials', function () {
    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(false);

    $response = $this->postJson('/api/integrations/google/discover-documents');

    $response->assertStatus(401);
});

test('discover documents indexes drive files', function () {
    GoogleCredential::factory()->for($this->user)->create();

    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(true);

    $files = [
        ['id' => 'file1', 'name' => 'Doc 1'],
        ['id' => 'file2', 'name' => 'Doc 2'],
    ];

    $this->driveService->shouldReceive('discoverDocuments')
        ->once()
        ->andReturn($files);

    $this->driveService->shouldReceive('indexDocument')
        ->twice();

    $response = $this->postJson('/api/integrations/google/discover-documents');

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'discovered' => 2,
        'indexed' => 2,
    ]);
});

// Search drive tests
test('search drive requires valid credentials', function () {
    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(false);

    $response = $this->getJson('/api/integrations/google/drive/search?q=test');

    $response->assertStatus(401);
});

test('search drive returns empty for empty query', function () {
    GoogleCredential::factory()->for($this->user)->create();

    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(true);

    $response = $this->getJson('/api/integrations/google/drive/search?q=');

    $response->assertStatus(200);
    $response->assertJson(['files' => []]);
});

test('search drive queries google drive api', function () {
    GoogleCredential::factory()->for($this->user)->create();

    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(true);

    $this->driveService->shouldReceive('searchFiles')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $this->user->id), "name contains 'test'")
        ->andReturn(['files' => [['id' => 'file1', 'name' => 'test.pdf']]]);

    $response = $this->getJson('/api/integrations/google/drive/search?q=test');

    $response->assertStatus(200);
    $response->assertJsonCount(1, 'files');
});

// Upcoming meetings tests
test('upcoming meetings requires valid credentials', function () {
    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(false);

    $response = $this->getJson('/api/integrations/google/upcoming-meetings');

    $response->assertStatus(401);
});

test('upcoming meetings returns client meetings', function () {
    GoogleCredential::factory()->for($this->user)->create();

    $this->oauthService->shouldReceive('hasValidCredentials')
        ->once()
        ->andReturn(true);

    $meetings = [
        ['id' => 'event1', 'summary' => 'Client Meeting'],
    ];

    $this->calendarService->shouldReceive('getUpcomingClientMeetings')
        ->once()
        ->andReturn($meetings);

    $response = $this->getJson('/api/integrations/google/upcoming-meetings');

    $response->assertStatus(200);
    $response->assertJson(['meetings' => $meetings]);
});

// Renew watches tests
test('renew watches requires credential', function () {
    $response = $this->postJson('/api/integrations/google/renew-watches');

    $response->assertStatus(401);
});

test('renew watches renews expired gmail watch', function () {
    $credential = GoogleCredential::factory()
        ->for($this->user)
        ->needsWatchRenewal()
        ->create();

    $this->gmailService->shouldReceive('watchInbox')
        ->once();

    $this->calendarService->shouldReceive('watchCalendar')
        ->never();

    $response = $this->postJson('/api/integrations/google/renew-watches');

    $response->assertStatus(200);
    $response->assertJsonPath('renewed', ['gmail']);
});

test('renew watches renews both when needed', function () {
    $credential = GoogleCredential::factory()
        ->for($this->user)
        ->state([
            'watch_expiration' => now()->subDay(),
            'calendar_watch_expiration' => now()->subDay(),
        ])
        ->create();

    $this->gmailService->shouldReceive('watchInbox')
        ->once();

    $this->calendarService->shouldReceive('watchCalendar')
        ->once();

    $response = $this->postJson('/api/integrations/google/renew-watches');

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'renewed');
});

test('renew watches handles errors', function () {
    GoogleCredential::factory()
        ->for($this->user)
        ->needsWatchRenewal()
        ->create();

    $this->gmailService->shouldReceive('watchInbox')
        ->once()
        ->andThrow(new Exception('Watch setup failed'));

    $response = $this->postJson('/api/integrations/google/renew-watches');

    $response->assertStatus(500);
});
