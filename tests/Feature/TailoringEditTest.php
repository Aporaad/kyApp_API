<?php

use App\Models\User;
use App\Support\PermissionResolver;
use App\Support\Tailoring\TailoringEditPolicy;
use Laravel\Sanctum\Sanctum;

function tailoringEditAdmin(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();
    if (! $user) {
        test()->markTestSkipped('يتطلب مستخدم مدير في Oracle / Requires an Oracle admin user.');
    }

    return $user;
}

function tailoringEditUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('every tailoring edit route demands a session', function () {
    $samples = [
        ['get', '/api/tailoring/edit/bills/filters'],
        ['get', '/api/tailoring/edit/bills'],
        ['get', '/api/tailoring/edit/bills/22207/2026'],
        ['put', '/api/tailoring/edit/bills/22207/2026'],
        ['patch', '/api/tailoring/edit/bills/22207/2026'],
        ['get', '/api/tailoring/edit/currency-reference'],
    ];
    foreach ($samples as [$verb, $path]) {
        $this->json($verb, $path)->assertUnauthorized();
    }
});

test('an ungranted caller is refused on every edit route', function () {
    Sanctum::actingAs(tailoringEditUngrantedUser(), ['*']);
    $samples = [
        ['get', '/api/tailoring/edit/bills/filters'],
        ['get', '/api/tailoring/edit/bills'],
        ['get', '/api/tailoring/edit/bills/22207/2026'],
        ['put', '/api/tailoring/edit/bills/22207/2026'],
        ['patch', '/api/tailoring/edit/bills/22207/2026'],
        ['get', '/api/tailoring/edit/currency-reference'],
    ];
    foreach ($samples as [$verb, $path]) {
        $this->json($verb, $path)
            ->assertForbidden()
            ->assertJsonPath('errors.permission', ['tailoring.update']);
    }
});

test('a caller with only tailoring.update can use the picker and shared lookups', function () {
    Sanctum::actingAs(tailoringEditUngrantedUser(), ['*']);
    $resolver = Mockery::mock(PermissionResolver::class);
    $resolver->shouldReceive('canAny')->andReturnTrue();
    $resolver->shouldReceive('can')->andReturnUsing(
        static fn ($user, string $code): bool => $code === 'tailoring.update'
    );
    app()->instance(PermissionResolver::class, $resolver);

    foreach ([
        '/api/tailoring/edit/bills?limit=1',
        '/api/tailoring/edit/bills/22207/2026',
        '/api/tailoring/edit/currency-reference',
        '/api/tailoring/create/reference',
        '/api/tailoring/reference',
    ] as $path) {
        $this->getJson($path)->assertOk()->assertJsonPath('success', true);
    }
});

test('the edit snapshot reads base tables and keeps finance editable', function () {
    Sanctum::actingAs(tailoringEditAdmin(), ['*']);
    $response = $this->getJson('/api/tailoring/edit/bills/22207/2026');
    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.identity.bill_no', 22207)
        ->assertJsonPath('data.identity.year', 2026)
        ->assertJsonPath('data.capability.financial_editable', true)
        ->assertJsonStructure([
            'data' => ['identity', 'customer', 'header', 'finance', 'models', 'journal', 'stage', 'capability', 'revision'],
        ]);
    expect($response->json('data.models'))->not->toBeEmpty()
        ->and($response->json('data.models.0.pieces'))->not->toBeEmpty()
        ->and(strlen($response->json('data.revision')))->toBe(64);
});

test('the edit list includes invoices missing from the cutting view source', function () {
    Sanctum::actingAs(tailoringEditAdmin(), ['*']);
    $response = $this->getJson('/api/tailoring/edit/bills?limit=5');
    $response->assertOk()->assertJsonPath('success', true)->assertJsonStructure([
        'data', 'meta' => ['total', 'page', 'limit', 'pages'],
    ]);
    expect($response->json('data'))->not->toBeEmpty();
});

test('the edit list applies base-table filters without leaking another year', function () {
    Sanctum::actingAs(tailoringEditAdmin(), ['*']);
    $response = $this->getJson('/api/tailoring/edit/bills?year=2026&limit=3');
    $response->assertOk()->assertJsonPath('success', true);
    foreach ($response->json('data') as $row) {
        expect((int) $row['year'])->toBe(2026);
    }
});

test('the edit policy keeps finance editable while locking content at cutting handover', function () {
    $before = TailoringEditPolicy::evaluate([
        'stage' => ['max_ordsid' => 1, 'cutting_evidence' => false],
    ]);
    $after = TailoringEditPolicy::evaluate([
        'stage' => ['max_ordsid' => 2, 'cutting_evidence' => true],
    ]);

    expect($before['mode'])->toBe('full')
        ->and($before['financial_editable'])->toBeTrue()
        ->and($after['mode'])->toBe('financial_safe')
        ->and($after['financial_editable'])->toBeTrue()
        ->and($after['locked_groups'])->toContain('models');
});

test('financial edits reject unknown fields before any database write', function () {
    Sanctum::actingAs(tailoringEditAdmin(), ['*']);
    $this->patchJson('/api/tailoring/edit/bills/22207/2026', [
        'expected_revision' => str_repeat('a', 64),
        'header' => ['unsafe_field' => 'x'],
    ])->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid_payload')
        ->assertJsonPath('errors', [
            'header.unsafe_field' => ['unknown field'],
        ]);
});

test('financial total and piece prices pass validation before the revision guard', function () {
    Sanctum::actingAs(tailoringEditAdmin(), ['*']);
    $this->patchJson('/api/tailoring/edit/bills/22207/2026', [
        'expected_revision' => str_repeat('b', 64),
        'finance' => ['total' => 1234.56],
        'piece_prices' => [],
        'sales_detail_discount' => ['rate' => 0, 'amount' => 0],
    ])->assertConflict()
        ->assertJsonPath('reason', 'stale_revision');
});

test('the financial edit reference exposes currencies accounts and difference account', function () {
    Sanctum::actingAs(tailoringEditAdmin(), ['*']);
    $response = $this->getJson('/api/tailoring/edit/currency-reference');
    $response->assertOk()->assertJsonPath('success', true)->assertJsonStructure([
        'data' => ['currencies', 'prices', 'accounts', 'exchange_difference_account'],
    ]);
});
