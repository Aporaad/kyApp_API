<?php

namespace App\Http\Controllers\Stores;

use App\Support\ArabicEncoding;
use App\Support\StorePolicy;

/**
 * أوامر التوريد المخزني — INVENTORY_RECEIVE + INVENTORY_RECEIVE_DETAILES
 * Inventory receive orders — writes the legacy receive pair atomically.
 *
 * ── بيانات إلزامية في المخطط القديم (NOT NULL) نسدّها هنا:
 *    BILL_TYPE · DAT · TOTAL · DETAILES · CUR_NO · CUR_PRICE · SANF_NUMS · PROV_NAME · SELECT1
 * ── Required legacy columns filled by this controller are listed above.
 */
class ReceiveOrderController extends StoreOrderController
{
    protected function kind(): string
    {
        return StorePolicy::KIND_RECEIVE;
    }

    protected function permissionBase(): string
    {
        return 'receiving';
    }

    protected function partySelect(): string
    {
        return 'm.PROV_NO AS PARTY_NO, m.PROV_NAME AS PARTY_NAME';
    }

    protected function partyNoColumn(): string
    {
        return 'PROV_NO';
    }

    protected function partyNameColumn(): string
    {
        return 'PROV_NAME';
    }

    /**
     * طرف التوريد هو المورد: رقمه من جدول PROV واسمه نصّي كما في المستند القديم
     * The receive party is the supplier: its number comes from PROV and the name is kept
     * as free text, exactly like the legacy document.
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
        return 'توريد مخزني';
    }
}
