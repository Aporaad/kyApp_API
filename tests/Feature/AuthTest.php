<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('login requires username and password', function () {
    $response = $this->postJson('/api/auth/login', []);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ])
        ->assertJsonValidationErrors(['username', 'password']);
});

test('login rejects invalid credentials', function () {
    $response = $this->postJson('/api/auth/login', [
        'username' => 'non_existent_user_99999',
        'password' => 'wrong_password',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
        ]);
});

test('protected routes reject unauthenticated requests', function () {
    $response = $this->getJson('/api/auth/me');
    $response->assertStatus(401);

    $logoutResponse = $this->postJson('/api/auth/logout');
    $logoutResponse->assertStatus(401);
});

// قراءة فقط: التحقق يفشل قبل أي كتابة على APP_USERS، فيبقى الاختبار آمناً على المخطط الحقيقي.
// Read-only: validation fails before any APP_USERS write, so the live schema stays untouched.
test('biometric registration rejects an unsupported platform before touching the user row', function () {
    $user = User::query()->first();

    if (! $user) {
        $this->markTestSkipped('No APP_USERS row is available on this database.');
    }

    Sanctum::actingAs($user);

    $this->postJson('/api/auth/biometric/register', [
        'device_token' => 'test-device-token',
        'device_name' => 'test device',
        'platform' => 'windows',
    ])
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});
