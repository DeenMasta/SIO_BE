<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityTokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanctum_token_expiration_config_is_set(): void
    {
        $expirationMinutes = (int) env('SANCTUM_TOKEN_EXPIRATION', 480);
        $this->assertEquals(480, $expirationMinutes);
    }

    public function test_sanctum_config_has_expiration_value(): void
    {
        $configured = config('sanctum.expiration');
        $this->assertNotNull($configured);
        $this->assertEquals(480, $configured);
    }

    public function test_expired_token_is_rejected_on_protected_endpoint(): void
    {
        $user = User::factory()->create();

        // Create a token and manually set its expiration to the past
        $token = $user->createToken('test', ['staff-access'])->plainTextToken;
        $tokenId = $user->tokens()->first()->id;

        // Set token expiration to 1 hour ago
        DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->update(['expires_at' => now()->subHour()]);

        // Try to use the expired token
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }

    public function test_valid_non_expired_token_allows_access(): void
    {
        $user = User::factory()->create();

        // Create a token
        $token = $user->createToken('test', ['staff-access'])->plainTextToken;

        // Token should work when not expired
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_token_just_expired_is_rejected(): void
    {
        $user = User::factory()->create();

        // Create a token and set expiration to 1 second ago
        $token = $user->createToken('test', ['staff-access'])->plainTextToken;
        $tokenId = $user->tokens()->first()->id;

        DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->update(['expires_at' => now()->subSecond()]);

        // Token should be rejected
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_fresh_token_from_login_works(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        // Login to get a token
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);

        // Token should work when fresh
        $token = $response->json('data.access_token');
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_expired_token_cannot_be_used_for_sensitive_operations(): void
    {
        $user = User::factory()->admin()->create();

        // Create a token and expire it
        $token = $user->createToken('test', ['admin-access'])->plainTextToken;
        $tokenId = $user->tokens()->first()->id;

        DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->update(['expires_at' => now()->subHour()]);

        // Admin endpoint should reject expired token
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/admin/ping')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }

    public function test_user_must_re_authenticate_after_token_expiration(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        // Create and expire a token
        $token = $user->createToken('test', ['staff-access'])->plainTextToken;
        $tokenId = $user->tokens()->first()->id;

        DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->update(['expires_at' => now()->subHour()]);

        // Using expired token fails
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/me')
            ->assertUnauthorized();

        // Re-login to get a new token
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'new-session',
        ]);

        $response->assertOk();
        $newToken = $response->json('data.access_token');

        // New token should work
        $this->withHeaders(['Authorization' => "Bearer {$newToken}"])
            ->getJson('/api/me')
            ->assertOk();
    }

    public function test_logout_with_valid_token_responds_with_success(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        // Login to get a token
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);

        $token = $response->json('data.access_token');

        // Token should work before logout
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/me')
            ->assertOk();

        // Logout should succeed
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
