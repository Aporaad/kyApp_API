<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * حراس المزودين والخدمات — الأقنعة، الرفض، وقيود القاعدة برسائل 409 لا 500
 * Provider/service guards — masks, refusals, and database constraints answered with 409, not 500.
 *
 * لا كتابة هنا: كل مسار يُختبر إمّا للقراءة أو للرفض قبل أي `INSERT/UPDATE/DELETE`. كشف الأسرار
 * الناجح يُختبَر يدوياً لأنه يكتب سطر تدقيق في `APP_USER_ACTIVITY`.
 * Nothing is written here: every path is either a read or a refusal before any write. A successful
 * secret reveal is verified manually because it appends an audit row.
 */
function catalogAdminUser(): User
{
    $user = User::query()->where('IS_ADMIN', 1)->where('IS_ACTIVATED', 1)->first();

    if (! $user) {
        test()->markTestSkipped('اختبار يتطلب قاعدة oracle الحية: لا يوجد مستخدم مدير.');

        return new User;
    }

    return $user;
}

function catalogUngrantedUser(): User
{
    $user = new User;
    $user->setAttribute('USER_ID', 999999);
    $user->setAttribute('IS_ADMIN', 0);
    $user->setAttribute('IS_ACTIVATED', 1);

    return $user;
}

test('revealing subscription credentials needs the dedicated grant', function () {
    Sanctum::actingAs(catalogUngrantedUser(), ['*']);

    $this->getJson('/api/providers/1/subscriptions/1/credentials')
        ->assertForbidden()
        ->assertJsonPath('errors.permission', ['providers.reveal_credentials']);
});

test('a subscription detail is masked for everyone without the reveal route', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    $response = $this->getJson('/api/providers/1/subscriptions/1');

    $response->assertOk()->assertJsonPath('success', true);

    $data = $response->json('data');

    // حتى المدير لا يرى السرّ من مسار التفاصيل — الكشف حدث صريح له مسار وصلاحية وتدقيق
    // Even the administrator never sees the secret from the detail route.
    expect($data)->toHaveKey('service_name')
        ->and($data['password'])->toBe('***')
        ->and($data['has_session'])->toBeTrue();
});

test('a provider detail is masked too', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    $data = $this->getJson('/api/providers/1')->assertOk()->json('data');

    // القيمة إما فارغة أصلاً أو القناع — ولا تكون سرّاً حقيقياً في أي حال
    // The value is either empty to begin with or the mask — never a real secret.
    expect($data)->toHaveKey('password')
        ->and(in_array($data['password'], [null, '***'], true))->toBeTrue()
        ->and($data)->toHaveKey('subscriptions_count')
        ->and($data['subscriptions_count'])->toBeInt();
});

test('probing a missing subscription is a 404 before any network call', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    $this->postJson('/api/provider-subscriptions/987654321/test')->assertNotFound();
});

test('a provider that still owns subscriptions cannot be deleted', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    $this->deleteJson('/api/providers/1')
        ->assertStatus(409)
        ->assertJsonPath('success', false);
});

test('a service still used by a subscription cannot be deleted', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    $this->deleteJson('/api/services/1')
        ->assertStatus(409)
        ->assertJsonPath('success', false);
});

test('a provider without a type is rejected before the NOT NULL column is hit', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    $this->postJson('/api/providers', ['provider_name' => 'مزود بلا نوع'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('a service type outside the engine vocabulary is rejected', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    $this->postJson('/api/services', ['service_name' => 'خدمة', 'service_type' => 'TELEGRAM'])
        ->assertStatus(422);

    $this->postJson('/api/services', ['service_name' => 'خدمة بلا نوع'])
        ->assertStatus(422);

    $this->putJson('/api/services/1', ['service_name' => 'اسم', 'service_type' => 'EMAIL'])
        ->assertStatus(422);
});

test('corporate settings refuse a payload missing a NOT NULL field', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    // `CORP_NAME/CORP_ADDR/SANF_TYPE` أعمدة NOT NULL: الحفظ الناقص كان يعطي ORA-01400 و500
    // These are NOT NULL columns: a partial save used to raise ORA-01400 and a 500.
    $this->putJson('/api/service-config/corp', ['corp_name' => 'اسم'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('the services catalog reports how many subscriptions use each service', function () {
    Sanctum::actingAs(catalogAdminUser(), ['*']);

    $response = $this->getJson('/api/service-config')->assertOk();

    foreach ($response->json('services') as $service) {
        expect($service)->toHaveKey('usage_count')
            ->and($service['usage_count'])->toBeInt();
    }
});
