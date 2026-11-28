<?php

use App\Models\User;
use App\Support\PermissionResolver;
use App\Support\Tailoring\TailoringEditPolicy;
use App\Support\Tailoring\TailoringEditWriter;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    ])->assertConflict()
        ->assertJsonPath('reason', 'stale_revision');
});

test('shape update binds exactly the placeholders used by Oracle', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('update')->once()->withArgs(
        static function (string $query, array $bindings): bool {
            preg_match_all('/:([a-zA-Z0-9_]+)/', $query, $matches);
            $placeholders = array_values(array_unique($matches[1]));
            $bound = array_keys($bindings);
            sort($placeholders);
            sort($bound);

            expect($bound)->toBe($placeholders);

            return true;
        }
    );

    $manager = DB::getFacadeRoot();
    DB::shouldReceive('connection')->with('oracle')->andReturn($connection);

    try {
        $writer = app(TailoringEditWriter::class);
        $method = new ReflectionMethod($writer, 'updateShape');
        $method->setAccessible(true);
        $model = [
            'seq_no' => 86106,
            'ohdid' => 2621154631,
            'family' => 2,
            'styl_no' => 30251,
            'style_label' => 'قطري',
            'beneficiary' => null,
            'notes' => 'مريح',
            'specs' => [
                'button_type' => 'طقطق',
                'button_no' => '3',
                'buttons_num' => null,
                'com_cabak_type' => 'كبك قماش',
                'neck_type' => 'قلاب',
                'pocket_or_without' => 'جيوب',
                'gabzor_type' => 'باين حشوة',
                'mobile_pocket' => 'عادي+قلم',
                'pen_pocket' => null,
                'hegazi_type' => null,
                'tipper_type' => 'A',
            ],
            'measurements' => [
                'L1' => 51.0, 'L2' => 16.5, 'L3' => 24.0, 'L4' => 15.0, 'L5' => 18.0,
                'L6' => 9.0, 'L7' => 5.75, 'L8' => 28.0, 'L9' => 16.0, 'L10' => 8.5,
            ],
            'item' => ['sanf_no' => 2024, 'sanf_dno' => 10101055007],
            'gabzor_hand' => false,
        ];
        $old = ['identity' => ['bill_no' => 22213, 'year' => 2026, 'ohid' => 262115463]];
        $method->invoke($writer, $model, $old);
    } finally {
        DB::swap($manager);
    }
});

test('full edit preparation preserves removed fields and calculates total without a total input', function () {
    $writer = app(TailoringEditWriter::class);
    $method = new ReflectionMethod($writer, 'preserveRemovedEditFields');
    $method->setAccessible(true);
    $prepared = [
        'customer' => ['address' => 'customer master address'],
        'party_account' => 999999,
        'finance' => [
            'gross' => 1000.0,
            'paid' => 200.0,
            'acc_no' => 999999,
            'cur_no' => 2,
            'cur_price' => 2.0,
            'cur_no1' => 3,
            'cur_price1' => 3.0,
            'select1' => 2,
            'transfer_through' => 'changed',
            'line_discount' => 0.0,
        ],
        'total' => 1000.0,
    ];
    $old = [
        'header' => ['address' => 'stored invoice address'],
        'finance' => [
            'acc_no' => 12311783,
            'cur_no' => 1,
            'cur_price' => 1.0,
            'cur_no1' => 1,
            'cur_price1' => 1.0,
            'select1' => 1,
            'transfer_through' => 'stored transfer',
            'line_discount' => 50.0,
            'discount' => 100.0,
        ],
    ];
    $request = Request::create('/', 'PUT');
    $method->invokeArgs($writer, [&$prepared, $old, $request]);

    expect($prepared['customer']['address'])->toBe('stored invoice address')
        ->and($prepared['party_account'])->toBe(12311783)
        ->and($prepared['finance']['acc_no'])->toBe(12311783)
        ->and($prepared['finance']['cur_no'])->toBe(1)
        ->and($prepared['finance']['cur_price'])->toBe(1.0)
        ->and($prepared['finance']['cur_no1'])->toBe(1)
        ->and($prepared['finance']['cur_price1'])->toBe(1.0)
        ->and($prepared['finance']['select1'])->toBe(1)
        ->and($prepared['finance']['transfer_through'])->toBe('stored transfer')
        ->and($prepared['finance']['line_discount'])->toBe(50.0)
        ->and($prepared['finance']['discount'])->toBe(100.0)
        ->and($prepared['finance']['discount_rate'])->toBe(10.0)
        ->and($prepared['finance']['total'])->toBe(850.0)
        ->and($prepared['total'])->toBe(850.0);
});

test('full edit preparation keeps the stored total for a legacy invoice without pieces', function () {
    $writer = app(TailoringEditWriter::class);
    $method = new ReflectionMethod($writer, 'preserveRemovedEditFields');
    $method->setAccessible(true);
    $prepared = [
        'customer' => ['address' => null],
        'party_account' => 12311783,
        'finance' => ['gross' => 0.0, 'paid' => 200.0, 'line_discount' => 0.0],
        'total' => 0.0,
    ];
    $old = [
        'header' => ['address' => 'stored address'],
        'finance' => [
            'acc_no' => 12311783,
            'line_discount' => 0.0,
            'discount' => 0.0,
            'total' => 500.0,
        ],
    ];
    $method->invokeArgs($writer, [&$prepared, $old, Request::create('/', 'PUT')]);

    expect($prepared['finance']['total'])->toBe(500.0)
        ->and($prepared['total'])->toBe(500.0);
});

test('financial edits reject fields removed from the edit form', function () {
    Sanctum::actingAs(tailoringEditAdmin(), ['*']);
    $response = $this->patchJson('/api/tailoring/edit/bills/22207/2026', [
        'expected_revision' => str_repeat('c', 64),
        'customer' => ['address' => 'must not be edited'],
        'finance' => [
            'acc_no' => 12311783,
            'discount' => 10,
            'discount_rate' => 1,
            'cur_no' => 1,
            'cur_price' => 1,
            'cur_no1' => 1,
            'cur_price1' => 1,
            'select1' => 1,
            'transfer_through' => 'must not be edited',
        ],
        'sales_detail_discount' => ['rate' => 0, 'amount' => 0],
    ])->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid_payload');
    $errors = $response->json('errors');
    expect($errors['customer.address'] ?? null)->toBe(['unknown field'])
        ->and($errors['finance.acc_no'] ?? null)->toBe(['unknown field'])
        ->and($errors['finance.transfer_through'] ?? null)->toBe(['unknown field'])
        ->and($errors['sales_detail_discount'] ?? null)->toBe(['unknown field']);
});

test('the financial edit reference exposes currencies accounts and difference account', function () {
    Sanctum::actingAs(tailoringEditAdmin(), ['*']);
    $response = $this->getJson('/api/tailoring/edit/currency-reference');
    $response->assertOk()->assertJsonPath('success', true)->assertJsonStructure([
        'data' => ['currencies', 'prices', 'accounts', 'exchange_difference_account'],
    ]);
});
