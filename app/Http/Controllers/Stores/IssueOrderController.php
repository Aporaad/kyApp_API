<?php

namespace App\Http\Controllers\Stores;

use App\Support\ArabicEncoding;
use App\Support\StorePolicy;

/**
 * أوامر الصرف المخزني — INVENTORY_SARF + INVENTORY_SARF_DETAILES
 * Inventory issue (sarf) orders — writes the legacy issue pair atomically.
 *
 * ── فرق جوهري عن التوريد: `SANF_DNO` **إلزامي** في جدول سطور الصرف، وهو نفس الحقل
 *    الذي تربط به الـ VIEW السطر بالصنف الفرعي. أي سطر بلا صنف فرعي يُرفض قبل الوصول
 *    إلى القاعدة برسالة عربية واضحة بدل خطأ ORA-01400.
 * ── Key difference from receiving: SANF_DNO is NOT NULL on the issue lines, and it is the
 *    same column the movement view joins on. A line without a sub item is refused early
 *    with a clear Arabic message instead of an ORA-01400.
 */
class IssueOrderController extends StoreOrderController
{
    protected function kind(): string
    {
        return StorePolicy::KIND_ISSUE;
    }

    protected function permissionBase(): string
    {
        return 'issuing';
    }

    protected function partySelect(): string
    {
        return 'm.CUST_NO AS PARTY_NO, m.CUST_NAME AS PARTY_NAME';
    }

    protected function partyNoColumn(): string
    {
        return 'CUST_NO';
    }

    protected function partyNameColumn(): string
    {
        return 'CUST_NAME';
    }

    /**
     * طرف الصرف: القسم/الجهة المستلمة. الرقم اختياري (FK إلى CUST) والاسم إلزامي
     * The issue party is the receiving department/entity. The number is optional (FK to CUST)
     * while the name is mandatory.
     *
     * @param  array<string, mixed>  $payload
     * @return array{no: mixed, name: mixed}
     */
    protected function partyBindings(array $payload): array
    {
        $name = trim((string) ($payload['party_name'] ?? ''));

        return [
            'no' => isset($payload['party_no']) && $payload['party_no'] !== null
                ? (int) $payload['party_no']
                : null,
            'name' => $this->clip(ArabicEncoding::toOracleSearchChars($name) ?? $name, 80),
        ];
    }

    protected function documentLabel(): string
    {
        return 'صرف مخزني';
    }
}
