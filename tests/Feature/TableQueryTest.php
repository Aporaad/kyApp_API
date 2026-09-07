<?php

test('selectFromTable returns data successfully for existing table', function () {
    $response = $this->getJson('/api/selectFromTable?table_name=NEW_USERS&limit=2');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'table',
            'count',
            'limit',
            'data',
        ]);
});

test('selectFromTable returns 404 for non-existent table', function () {
    $response = $this->getJson('/api/selectFromTable?table_name=NON_EXISTENT_TABLE_XYZ&limit=5');

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
        ]);
});

test('selectFromTable validates table name and prevents invalid characters', function () {
    $response = $this->getJson('/api/selectFromTable?table_name=USERS;DROP%20TABLE&limit=5');

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

test('selectFromTable validates limit constraints', function () {
    $response = $this->getJson('/api/selectFromTable?table_name=NEW_USERS&limit=99999');

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});
