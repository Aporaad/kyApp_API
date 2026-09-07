<?php

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
