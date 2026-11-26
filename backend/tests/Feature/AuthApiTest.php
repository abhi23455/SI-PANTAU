<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_pending_account_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ayu Pratama',
            'email' => 'ayu@example.com',
            'password' => 'password123',
            'phone' => '08123456789',
            'address' => 'Semarang',
            'institution' => 'Universitas Contoh',
            'major' => 'Statistika',
            'role' => 'pemagang',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.full_name', 'Ayu Pratama')
            ->assertJsonPath('profile.status', 'pending')
            ->assertJsonPath('profile.role', 'pemagang')
            ->assertJsonStructure(['access_token', 'token_type']);

        $this->assertDatabaseHas('users', [
            'email' => 'ayu@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_registration_does_not_allow_admin_role(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Unauthorized Admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'phone' => '08123456789',
            'address' => 'Semarang',
            'institution' => 'BPS',
            'role' => 'admin',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_login_returns_a_token_and_incorrect_password_is_rejected(): void
    {
        User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'role' => 'pemagang',
            'status' => 'active',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'budi@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['user', 'profile', 'access_token']);

        $this->postJson('/api/auth/login', [
            'email' => 'budi@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_authenticated_user_can_update_profile_but_not_role_or_email(): void
    {
        $user = User::create([
            'name' => 'Citra Lama',
            'email' => 'citra@example.com',
            'password' => 'password123',
            'role' => 'pemagang',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->patchJson('/api/profile', [
            'full_name' => 'Citra Baru',
            'phone' => '08123456789',
            'email' => 'changed@example.com',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('profile.full_name', 'Citra Baru')
            ->assertJsonPath('profile.email', 'citra@example.com')
            ->assertJsonPath('profile.role', 'pemagang');

        $this->patchJson('/api/profile', ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::create([
            'name' => 'Dewi',
            'email' => 'dewi@example.com',
            'password' => 'password123',
            'role' => 'pemagang',
            'status' => 'active',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => hash('sha256', $token),
        ]);
    }
}
