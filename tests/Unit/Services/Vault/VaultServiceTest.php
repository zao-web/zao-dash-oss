<?php

namespace Tests\Unit\Services\Vault;

use App\Models\User;
use App\Models\VaultAccessLog;
use App\Models\VaultSecret;
use App\Services\Vault\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class VaultServiceTest extends TestCase
{
    use RefreshDatabase;

    protected VaultService $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = new VaultService;
    }

    /** @test */
    public function it_stores_secret()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $secret = $this->vault->store(
            'test.api.key',
            'secret-value',
            'Test API Key',
            ['category' => 'api', 'description' => 'Test secret']
        );

        $this->assertDatabaseHas('vault_secrets', [
            'key' => 'test.api.key',
            'name' => 'Test API Key',
            'category' => 'api',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $this->assertEquals('secret-value', Crypt::decryptString($secret->encrypted_value));
    }

    /** @test */
    public function it_retrieves_secret()
    {
        $secret = VaultSecret::factory()->create([
            'key' => 'test.key',
            'encrypted_value' => Crypt::encryptString('my-secret'),
            'is_active' => true,
        ]);

        $value = $this->vault->get('test.key');

        $this->assertEquals('my-secret', $value);
    }

    /** @test */
    public function it_returns_null_for_nonexistent_secret()
    {
        $value = $this->vault->get('nonexistent.key');

        $this->assertNull($value);
    }

    /** @test */
    public function it_returns_null_for_inactive_secret()
    {
        VaultSecret::factory()->create([
            'key' => 'inactive.key',
            'encrypted_value' => Crypt::encryptString('value'),
            'is_active' => false,
        ]);

        $value = $this->vault->get('inactive.key');

        $this->assertNull($value);
    }

    /** @test */
    public function it_returns_null_for_expired_secret()
    {
        VaultSecret::factory()->create([
            'key' => 'expired.key',
            'encrypted_value' => Crypt::encryptString('value'),
            'expires_at' => now()->subDay(),
        ]);

        $value = $this->vault->get('expired.key');

        $this->assertNull($value);
    }

    /** @test */
    public function it_checks_user_access()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $secret = VaultSecret::factory()->create([
            'key' => 'restricted.key',
            'encrypted_value' => Crypt::encryptString('value'),
            'allowed_users' => [$user1->id],
        ]);

        // User 1 has access
        $value = $this->vault->get('restricted.key', $user1);
        $this->assertEquals('value', $value);

        // User 2 doesn't have access
        $value = $this->vault->get('restricted.key', $user2);
        $this->assertNull($value);
    }

    /** @test */
    public function it_checks_agent_access()
    {
        $secret = VaultSecret::factory()->create([
            'key' => 'agent.key',
            'encrypted_value' => Crypt::encryptString('value'),
            'allowed_agents' => ['deploy-agent'],
        ]);

        // Allowed agent
        $value = $this->vault->get('agent.key', null, 'deploy-agent');
        $this->assertEquals('value', $value);

        // Disallowed agent
        $value = $this->vault->get('agent.key', null, 'other-agent');
        $this->assertNull($value);
    }

    /** @test */
    public function it_logs_access_attempts()
    {
        $user = User::factory()->create();
        $secret = VaultSecret::factory()->create([
            'key' => 'logged.key',
            'encrypted_value' => Crypt::encryptString('value'),
        ]);

        $this->vault->get('logged.key', $user);

        $this->assertDatabaseHas('vault_access_logs', [
            'secret_id' => $secret->id,
            'action' => VaultAccessLog::ACTION_READ,
            'accessor_type' => VaultAccessLog::TYPE_USER,
            'accessor_id' => $user->id,
            'success' => true,
        ]);
    }

    /** @test */
    public function it_logs_denied_access()
    {
        $user = User::factory()->create();
        $secret = VaultSecret::factory()->create([
            'key' => 'denied.key',
            'encrypted_value' => Crypt::encryptString('value'),
            'allowed_users' => [999],
        ]);

        $this->vault->get('denied.key', $user);

        $this->assertDatabaseHas('vault_access_logs', [
            'secret_id' => $secret->id,
            'success' => false,
            'failure_reason' => 'Access denied',
        ]);
    }

    /** @test */
    public function it_updates_access_stats()
    {
        $secret = VaultSecret::factory()->create([
            'key' => 'stats.key',
            'encrypted_value' => Crypt::encryptString('value'),
            'access_count' => 0,
        ]);

        $this->vault->get('stats.key');
        $this->vault->get('stats.key');

        $secret->refresh();
        $this->assertEquals(2, $secret->access_count);
        $this->assertNotNull($secret->last_accessed_at);
    }

    /** @test */
    public function it_gets_multiple_secrets()
    {
        VaultSecret::factory()->create([
            'key' => 'key1',
            'encrypted_value' => Crypt::encryptString('value1'),
        ]);

        VaultSecret::factory()->create([
            'key' => 'key2',
            'encrypted_value' => Crypt::encryptString('value2'),
        ]);

        $values = $this->vault->getMany(['key1', 'key2']);

        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $values);
    }

    /** @test */
    public function it_updates_secret_value()
    {
        $user = User::factory()->create();
        $secret = VaultSecret::factory()->create([
            'key' => 'update.key',
            'encrypted_value' => Crypt::encryptString('old-value'),
        ]);

        $this->vault->update($secret, 'new-value', $user);

        $secret->refresh();
        $this->assertEquals('new-value', Crypt::decryptString($secret->encrypted_value));
        $this->assertEquals($user->id, $secret->updated_by);
    }

    /** @test */
    public function it_prevents_unauthorized_updates()
    {
        $user = User::factory()->create();
        $secret = VaultSecret::factory()->create([
            'key' => 'protected.key',
            'encrypted_value' => Crypt::encryptString('value'),
            'allowed_users' => [999],
        ]);

        $result = $this->vault->update($secret, 'new-value', $user);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_rotates_secret()
    {
        $user = User::factory()->create();
        $secret = VaultSecret::factory()->create([
            'key' => 'rotate.key',
            'encrypted_value' => Crypt::encryptString('old-key'),
        ]);

        $this->vault->rotate($secret, 'new-key', $user);

        $this->assertDatabaseHas('vault_access_logs', [
            'secret_id' => $secret->id,
            'action' => VaultAccessLog::ACTION_ROTATE,
            'success' => true,
        ]);

        $secret->refresh();
        $this->assertEquals('new-key', Crypt::decryptString($secret->encrypted_value));
    }

    /** @test */
    public function it_deletes_secret()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $secret = VaultSecret::factory()->create(['key' => 'delete.key']);

        $result = $this->vault->delete($secret, $admin);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('vault_secrets', ['id' => $secret->id]);
    }

    /** @test */
    public function it_prevents_non_admin_deletion()
    {
        $user = User::factory()->create(['is_admin' => false]);
        $secret = VaultSecret::factory()->create(['key' => 'protected.key']);

        $result = $this->vault->delete($secret, $user);

        $this->assertFalse($result);
        $this->assertDatabaseHas('vault_secrets', ['id' => $secret->id]);
    }

    /** @test */
    public function it_gets_agent_secrets()
    {
        VaultSecret::factory()->create([
            'key' => 'global.key',
            'encrypted_value' => Crypt::encryptString('global-value'),
            'project_id' => null,
            'client_id' => null,
            'allowed_agents' => ['test-agent'],
        ]);

        VaultSecret::factory()->create([
            'key' => 'project.key',
            'encrypted_value' => Crypt::encryptString('project-value'),
            'project_id' => 1,
            'allowed_agents' => ['test-agent'],
        ]);

        VaultSecret::factory()->create([
            'key' => 'other.key',
            'encrypted_value' => Crypt::encryptString('other-value'),
            'allowed_agents' => ['other-agent'],
        ]);

        $secrets = $this->vault->getAgentSecrets('test-agent', 1, null);

        $this->assertArrayHasKey('global.key', $secrets);
        $this->assertArrayHasKey('project.key', $secrets);
        $this->assertArrayNotHasKey('other.key', $secrets);
    }

    /** @test */
    public function it_checks_if_secret_exists()
    {
        VaultSecret::factory()->create([
            'key' => 'exists.key',
            'encrypted_value' => Crypt::encryptString('value'),
        ]);

        $this->assertTrue($this->vault->exists('exists.key'));
        $this->assertFalse($this->vault->exists('nonexistent.key'));
    }

    /** @test */
    public function it_lists_accessible_secrets()
    {
        $user = User::factory()->create(['is_admin' => false]);

        VaultSecret::factory()->create([
            'key' => 'public.key',
            'name' => 'Public Key',
            'allowed_users' => null,
        ]);

        VaultSecret::factory()->create([
            'key' => 'user.key',
            'name' => 'User Key',
            'allowed_users' => [$user->id],
        ]);

        VaultSecret::factory()->create([
            'key' => 'other.key',
            'name' => 'Other Key',
            'allowed_users' => [999],
        ]);

        $secrets = $this->vault->list($user);

        $this->assertCount(2, $secrets);
    }

    /** @test */
    public function it_lists_all_secrets_for_admins()
    {
        $admin = User::factory()->create(['is_admin' => true]);

        VaultSecret::factory()->count(5)->create();

        $secrets = $this->vault->list($admin);

        $this->assertCount(5, $secrets);
    }

    /** @test */
    public function it_filters_list_by_category()
    {
        VaultSecret::factory()->create(['category' => 'api']);
        VaultSecret::factory()->create(['category' => 'api']);
        VaultSecret::factory()->create(['category' => 'database']);

        $secrets = $this->vault->list(null, 'api');

        $this->assertCount(2, $secrets);
    }

    /** @test */
    public function it_returns_metadata_only_in_list()
    {
        VaultSecret::factory()->create([
            'key' => 'list.key',
            'name' => 'List Key',
            'category' => 'api',
        ]);

        $secrets = $this->vault->list();

        $this->assertArrayHasKey('key', $secrets[0]);
        $this->assertArrayHasKey('name', $secrets[0]);
        $this->assertArrayHasKey('category', $secrets[0]);
        $this->assertArrayNotHasKey('encrypted_value', $secrets[0]);
    }
}
