<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_rejects_missing_key(): void
    {
        $this->getJson('/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_rejects_unknown_key(): void
    {
        $this->withKey('osp_invalid')->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_accepts_bearer_and_x_api_key(): void
    {
        $this->withKey()->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->client->id);

        $this->withHeader('X-Api-Key', $this->apiKey)->getJson('/v1/me')->assertOk();
    }

    public function test_rejects_revoked_key_and_inactive_client(): void
    {
        ApiKey::query()->update(['revoked_at' => now()]);
        $this->withKey()->getJson('/v1/me')->assertUnauthorized();

        [$client, $key] = $this->createClient();
        $client->update(['active' => false]);
        $this->withKey($key)->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_only_hash_of_key_is_stored(): void
    {
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $this->apiKey]);
        $this->assertDatabaseHas('api_keys', ['key_hash' => hash('sha256', $this->apiKey)]);
    }

    public function test_client_updates_own_webhook_url(): void
    {
        $this->withKey()->patchJson('/v1/me', ['webhook_url' => 'http://insecure.test'])->assertUnprocessable();

        $this->withKey()->patchJson('/v1/me', ['webhook_url' => 'https://novo.test/hook'])
            ->assertOk()
            ->assertJsonPath('data.webhook_url', 'https://novo.test/hook');
    }
}
