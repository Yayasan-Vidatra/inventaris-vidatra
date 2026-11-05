<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `PUT /api/me/password` — self-service password change for every signed-in
 * role. Only ever changes the caller's own password; the new value is stored
 * hashed; on success the current session is regenerated and (database session
 * driver) the user's other sessions are deleted.
 */
class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'current-password-123';

    private const NEW = 'brand-new-password-456';

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'pengguna@vidatra.test',
            'password' => Hash::make(self::CURRENT),
            'role' => UserRole::Viewer,
            'is_active' => true,
        ], $overrides));
    }

    private function change(array $payload)
    {
        return $this->putJson('/api/me/password', $payload);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ], $overrides);
    }

    public function test_requires_authentication(): void
    {
        $this->change($this->validPayload())->assertStatus(401);
    }

    public function test_inactive_user_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser(['is_active' => false]));

        $this->change($this->validPayload())->assertStatus(403);
    }

    public function test_correct_current_password_changes_it_and_stores_a_hash(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->change($this->validPayload())
            ->assertOk()
            ->assertJsonPath('message', 'Kata sandi berhasil diubah.');

        $stored = $user->fresh()->password;
        $this->assertNotSame(self::NEW, $stored, 'password must never be stored in plaintext');
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertTrue(Hash::check(self::NEW, $stored));
        $this->assertFalse(Hash::check(self::CURRENT, $stored));
    }

    public function test_wrong_current_password_is_rejected_and_nothing_changes(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $before = $user->password;

        $this->change($this->validPayload(['current_password' => 'not-the-password']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password'])
            ->assertJsonPath('errors.current_password.0', 'Kata sandi saat ini tidak sesuai.');

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_confirmation_mismatch_is_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->change($this->validPayload(['password_confirmation' => 'something-else-789']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password'])
            ->assertJsonPath('errors.password.0', 'Konfirmasi kata sandi baru tidak cocok.');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_new_password_equal_to_current_is_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->change($this->validPayload(['password' => self::CURRENT, 'password_confirmation' => self::CURRENT]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password'])
            ->assertJsonPath('errors.password.0', 'Kata sandi baru harus berbeda dari kata sandi saat ini.');
    }

    public function test_new_password_shorter_than_twelve_characters_is_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->change($this->validPayload(['password' => 'short-pass', 'password_confirmation' => 'short-pass']))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Kata sandi baru minimal 12 karakter.');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_request_cannot_target_another_account(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser(['email' => 'lain@vidatra.test', 'password' => Hash::make('other-password-000')]);
        $otherHash = $other->password;
        Sanctum::actingAs($user);

        $this->change($this->validPayload(['user_id' => $other->id, 'id' => $other->id, 'email' => $other->email]))
            ->assertOk();

        $this->assertSame($otherHash, $other->fresh()->password, 'another account must never change');
        $this->assertSame('lain@vidatra.test', $other->fresh()->email);
        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
    }

    public function test_every_role_can_change_its_own_password(): void
    {
        Location::query()->firstOrCreate(['code' => '02'], ['name' => 'Lokasi 02', 'is_active' => true]);

        foreach (UserRole::cases() as $i => $role) {
            $user = $this->makeUser([
                'email' => "role{$i}@vidatra.test",
                'role' => $role,
                'location_code' => $role === UserRole::UnitAdmin ? '02' : null,
            ]);
            Sanctum::actingAs($user);

            $this->change($this->validPayload())->assertOk();
            $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password), "role {$role->value}");
        }
    }

    public function test_new_password_works_for_login_and_old_one_does_not(): void
    {
        $this->makeUser();
        $stateful = fn () => $this->withHeader('Origin', 'http://localhost');

        $stateful()->postJson('/api/login', ['email' => 'pengguna@vidatra.test', 'password' => self::CURRENT])->assertOk();
        $stateful()->putJson('/api/me/password', $this->validPayload())->assertOk();
        $stateful()->postJson('/api/logout')->assertOk();

        $stateful()->postJson('/api/login', ['email' => 'pengguna@vidatra.test', 'password' => self::CURRENT])
            ->assertStatus(422);
        $stateful()->postJson('/api/login', ['email' => 'pengguna@vidatra.test', 'password' => self::NEW])
            ->assertOk();
    }

    public function test_other_sessions_of_the_user_are_deleted_with_the_database_driver(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();

        $user = $this->makeUser();
        $other = $this->makeUser(['email' => 'lain@vidatra.test']);
        $this->actingAs($user, 'web');

        // another device of the same user, and a session of a different user
        DB::table('sessions')->insert([
            ['id' => 'other-device-session', 'user_id' => $user->id, 'ip_address' => '203.0.113.5', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
            ['id' => 'different-user-session', 'user_id' => $other->id, 'ip_address' => '203.0.113.6', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
        ]);

        $this->withHeader('Origin', 'http://localhost')
            ->putJson('/api/me/password', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('other_sessions_ended', 1);

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'different-user-session']);
    }

    public function test_password_change_is_rate_limited_per_user(): void
    {
        Sanctum::actingAs($this->makeUser());

        for ($i = 0; $i < 5; $i++) {
            $this->change($this->validPayload(['current_password' => 'wrong-'.$i]))->assertStatus(422);
        }

        $this->change($this->validPayload())->assertStatus(429);
    }
}
