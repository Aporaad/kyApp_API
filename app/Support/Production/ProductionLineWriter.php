<?php

namespace App\Support\Production;

use App\Support\ActivityLogger;
use App\Support\ArabicEncoding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * كاتب مستندات خط الإنتاج — كل كتابة تمر من هنا
 * The production-line document writer — every write passes through here.
 *
 * ── لماذا كاتب واحد؟ لأن العملية الواحدة تلمس ثلاثة أماكن: رأس سند الحركة، تفاصيله،
 *    وصف القطعة في MULTI_CLOTHS. أي كتابة موزّعة تترك نصف عملية عند أول خطأ.
 * ── One writer because one operation touches three places: the movement header, its
 *    details, and the piece row. Split writes leave half an operation behind on failure.
 *
 * ── الترقيم: MAX+1 تحت قفل جدول CUTING_TRANSM، لأن الجدول بلا identity وبلا قيد فريد
 *    على TR_INV_D_ID. القفل يمنع تطبيقنا وشاشات Forms القديمة من أخذ الرقم نفسه.
 * ── Numbering: MAX+1 under a table lock, because there is no identity and no unique
 *    constraint on TR_INV_D_ID. The lock serialises our app and the legacy Forms screens.
 */
final class ProductionLineWriter
{
    public function __construct(private readonly ActivityLogger $audit) {}

    /**
     * اعتماد قطع — 0 ⇒ 1، ثم إعادة حساب حالة الفاتورة
     * Approve pieces — 0 ⇒ 1, then recompute the invoice status.
     *
     * @param  list<array{ohid: int, ohdids?: list<int>}>  $items
     * @param  array<int|string, array<string, mixed>>  $flags
     * @return array{pieces: int, invoices: int}
     */
    public function approve(
        array $items,
        array $flags,
        ?int $userId,
        ?string $userName,
        ?Request $request
    ): array {
        $conn = DB::connection('oracle');
        $conn->beginTransaction();

        try {
            $pieces = $this->resolvePieces(ProductionPolicy::SECTION_APPROVAL, $items);
            $invoiceIds = [];

            foreach ($pieces as $piece) {
                $ohdid = (int) $this->value($piece, 'OHDID');
                $ohid = (int) $this->value($piece, 'OHID');
                $invoiceIds[$ohid] = true;

                $flag = $flags[$ohdid] ?? $flags[(string) $ohdid] ?? null;
                $binds = [
                    'ohdid' => $ohdid,
                    'user_name' => $this->oracleText($userName, 20),
                ];
                $set = 'ORDSDID = 1, UPDATED_BY_NAME = :user_name';

                if (is_array($flag)) {
                    $set .= ', GABZOR_HAND = :gabzor, GABZOR_HAND_AMT = :gabzor_amt,
                              TATRYZ_IN = :tatryz, TATRYZ_AMT = :tatryz_amt';
                    $binds['gabzor'] = ! empty($flag['gabzor_hand']) ? 1 : 0;
                    $binds['gabzor_amt'] = $this->numeric($flag['gabzor_hand_amt'] ?? null);
                    $binds['tatryz'] = ! empty($flag['tatryz_in']) ? 1 : 0;
                    $binds['tatryz_amt'] = $this->numeric($flag['tatryz_amt'] ?? null);
                }

                $affected = $conn->update(
                    'UPDATE ACCOUNTS.MULTI_CLOTHS
                        SET '.$set.'
                      WHERE OHDID = :ohdid AND ORDSDID = 0',
                    $binds
                );

                if ($affected !== 1) {
                    throw new ProductionConflictException(
                        'تغيّرت حالة القطعة قبل الاعتماد، حدّث القائمة.',
                        ['ohdid' => $ohdid, 'expected' => ProductionPolicy::STATUS_NEW]
                    );
                }
            }

            foreach (array_keys($invoiceIds) as $ohid) {
                $this->recomputeInvoiceStatus((int) $ohid);
            }

            $this->audit->success('production.approve', [
                'user_id' => $userId,
                'module_code' => 'prod_approval',
                'entity_type' => 'sales_bill',
                'entity_id' => implode(',', array_slice(array_keys($invoiceIds), 0, 10)),
                'description_ar' => $this->clip(
                    'اعتماد '.count($pieces).' قطعة من '.count($invoiceIds).' فاتورة',
                    480
                ),
            ], $request);

            $conn->commit();

            return ['pieces' => count($pieces), 'invoices' => count($invoiceIds)];
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }

    /**
     * إلغاء اعتماد قطع — 1 ⇒ 0، بشرط ألا تكون خرجت من المحطة
     * Unapprove pieces — 1 ⇒ 0, only while they never left the station.
     *
     * @param  list<array{ohid: int, ohdids?: list<int>}>  $items
     * @return array{pieces: int, invoices: int}
     */
    public function unapprove(
        array $items,
        ?int $userId,
        ?string $userName,
        ?Request $request
    ): array {
        $conn = DB::connection('oracle');
        $conn->beginTransaction();

        try {
            $pieces = $this->resolvePieces(ProductionPolicy::SECTION_TO_CUT, $items);
            $invoiceIds = [];

            foreach ($pieces as $piece) {
                $ohdid = (int) $this->value($piece, 'OHDID');
                $ohid = (int) $this->value($piece, 'OHID');
                $invoiceIds[$ohid] = true;

                $movements = (array) $conn->selectOne(
                    'SELECT COUNT(*) AS C FROM ACCOUNTS.CUTING_TRANSD
                      WHERE OHDID = :ohdid AND ORDSID >= 2 AND NVL(CANCEL, 0) <> 1',
                    ['ohdid' => $ohdid]
                );
                $count = (int) ($movements['C'] ?? $movements['c'] ?? 0);

                if ($count > 0) {
                    throw new ProductionConflictException(
                        'لا يمكن إلغاء اعتماد قطعة بدأت رحلتها إلى القص.',
                        ['ohdid' => $ohdid, 'movements' => $count]
                    );
                }

                $affected = $conn->update(
                    'UPDATE ACCOUNTS.MULTI_CLOTHS
                        SET ORDSDID = 0, UPDATED_BY_NAME = :user_name
                      WHERE OHDID = :ohdid AND ORDSDID = 1',
                    ['ohdid' => $ohdid, 'user_name' => $this->oracleText($userName, 20)]
                );

                if ($affected !== 1) {
                    throw new ProductionConflictException(
                        'تغيّرت حالة القطعة قبل إلغاء الاعتماد، حدّث القائمة.',
                        ['ohdid' => $ohdid, 'expected' => ProductionPolicy::STATUS_APPROVED]
                    );
                }
            }

            foreach (array_keys($invoiceIds) as $ohid) {
                $this->recomputeInvoiceStatus((int) $ohid);
            }

            $this->audit->success('production.unapprove', [
                'user_id' => $userId,
                'module_code' => 'prod_approval',
                'entity_type' => 'sales_bill',
                'entity_id' => implode(',', array_slice(array_keys($invoiceIds), 0, 10)),
                'description_ar' => 'إلغاء اعتماد '.count($pieces).' قطعة',
            ], $request);

            $conn->commit();

            return ['pieces' => count($pieces), 'invoices' => count($invoiceIds)];
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }

    /**
     * تسليم قطع إلى قصاص — 1 ⇒ 2، رأس واحد + تفصيل لكل قطعة
     * Deliver pieces to a cutter — 1 ⇒ 2, one header plus one detail per piece.
     *
     * @param  list<array{ohid: int, ohdids?: list<int>}>  $items
     * @return array{document_no: int, pieces: int, invoices: int}
     */
    public function deliver(
        int $cutterNo,
        ?string $date,
        array $items,
        ?int $userId,
        ?string $userName,
        ?Request $request
    ): array {
        $cutter = $this->cutter($cutterNo);
        $date = $this->dateOrToday($date);
        $conn = DB::connection('oracle');

        $conn->beginTransaction();

        try {
            $this->lockNumbering();

            $pieces = $this->resolvePieces(ProductionPolicy::SECTION_TO_CUT, $items);
            $masterId = $this->nextMasterId();
            $first = $pieces[0];

            $this->insertMaster([
                'mid' => $masterId,
                'bill_no' => (int) $this->value($first, 'BILL_NO'),
                'year' => (int) $this->value($first, 'YEAR'),
                'dat' => $date,
                'dept' => $this->dept('cutting'),
                'ordsid' => ProductionPolicy::STATUS_AT_CUTTER,
                'cutter_no' => $cutterNo,
                'pieces' => count($pieces),
                'details' => $this->arabic('تسليم للقص   '.$cutter, 200),
                'user_id' => $userId,
                'user_name' => $userName,
            ]);

            foreach ($pieces as $piece) {
                $ohdid = (int) $this->value($piece, 'OHDID');

                $this->insertDetail([
                    'did' => $this->nextDetailId(),
                    'mid' => $masterId,
                    'bill_no' => (int) $this->value($piece, 'BILL_NO'),
                    'year' => (int) $this->value($piece, 'YEAR'),
                    'type' => $this->type('in'),
                    'dat' => $date,
                    'dept' => $this->dept('cutting'),
                    'detail' => $this->arabic('توزيع للقص   '.$cutter, 100),
                    'sanf_no' => $this->numeric($this->value($piece, 'SANF_NO')),
                    'sanf_dno' => $this->numeric($this->value($piece, 'SANF_DNO')),
                    'ohid' => (int) $this->value($piece, 'OHID'),
                    'ohdid' => $ohdid,
                    'ordsid' => ProductionPolicy::STATUS_AT_CUTTER,
                    'cutter_no' => $cutterNo,
                    'user_id' => $userId,
                    'user_name' => $userName,
                ]);

                $affected = $conn->update(
                    'UPDATE ACCOUNTS.MULTI_CLOTHS
                        SET ORDSDID = 2,
                            CUTTING_DONE = 1,
                            CUTTING_DATE = TO_DATE(:dat, \'YYYY-MM-DD\'),
                            TIME1 = TO_CHAR(SYSDATE, \'HH24:MI\'),
                            CUTTER_NO1 = :cutter_id,
                            CUT_QTY = QTY,
                            CUTTER_PCS_PRICE1 = NVL(CUTTER_PCS_PRICE1,
                                (SELECT MAX(cd.PIECE_PRICE)
                                   FROM ACCOUNTS.CUTTER_DETAILS cd, ACCOUNTS.SALES_BILL sb
                                  WHERE cd.CUTTER_NO = :cutter_price
                                    AND cd.STYLE_NO = MULTI_CLOTHS.SHAPE_STYLE_NO
                                    AND cd.CUST_GROUP_ID = sb.GROUP_NO
                                    AND sb.OHID = MULTI_CLOTHS.OHID)),
                            UPDATED_BY_NAME = :user_name
                      WHERE OHDID = :ohdid AND ORDSDID = 1',
                    [
                        'dat' => $date,
                        'cutter_id' => $cutterNo,
                        'cutter_price' => $cutterNo,
                        'ohdid' => $ohdid,
                        'user_name' => $this->oracleText($userName, 20),
                    ]
                );

                if ($affected !== 1) {
                    throw new ProductionConflictException(
                        'تغيّرت حالة إحدى القطع قبل التسليم، حدّث القائمة.',
                        ['ohdid' => $ohdid, 'expected' => ProductionPolicy::STATUS_APPROVED]
                    );
                }
            }

            $invoiceIds = $this->invoiceIds($pieces);
            foreach ($invoiceIds as $ohid) {
                $this->recomputeInvoiceStatus($ohid);
            }

            $this->audit->success('production.deliver_cut', [
                'user_id' => $userId,
                'module_code' => 'prod_to_cut',
                'entity_type' => 'production_document',
                'entity_id' => $masterId,
                'description_ar' => $this->clip(
                    'تسليم '.count($pieces).' قطعة إلى القصاص '.$cutter.' — السند '.$masterId,
                    480
                ),
            ], $request);

            $conn->commit();

            return [
                'document_no' => $masterId,
                'pieces' => count($pieces),
                'invoices' => count($invoiceIds),
            ];
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }

    /**
     * استلام قطع من قصاص — 2 ⇒ 4، مع حركتي صرف وتوريد لكل قطعة
     * Receive pieces from a cutter — 2 ⇒ 4, with an issue/receipt pair per piece.
     *
     * @param  list<array{ohid: int, ohdids?: list<int>}>  $items
     * @return array{document_no: int, pieces: int, invoices: int}
     */
    public function receive(
        int $cutterNo,
        string $date,
        array $items,
        ?int $userId,
        ?string $userName,
        ?Request $request
    ): array {
        $cutter = $this->cutter($cutterNo);
        $conn = DB::connection('oracle');

        $conn->beginTransaction();

        try {
            $this->lockNumbering();

            $pieces = $this->resolvePieces(
                ProductionPolicy::SECTION_FROM_CUT,
                $items,
                $cutterNo
            );
            $masterId = $this->nextMasterId();
            $first = $pieces[0];

            $this->insertMaster([
                'mid' => $masterId,
                'bill_no' => (int) $this->value($first, 'BILL_NO'),
                'year' => (int) $this->value($first, 'YEAR'),
                'dat' => $date,
                'dept' => $this->dept('main'),
                'ordsid' => ProductionPolicy::STATUS_RECEIVED_FROM_CUT,
                'cutter_no' => $cutterNo,
                'pieces' => count($pieces),
                'details' => $this->arabic('استلام من القص   '.$cutter, 200),
                'user_id' => $userId,
                'user_name' => $userName,
            ]);

            foreach ($pieces as $piece) {
                $ohdid = (int) $this->value($piece, 'OHDID');
                $ohid = (int) $this->value($piece, 'OHID');
                $deliveredAt = (string) ($this->value($piece, 'CUTTING_DATE_ISO') ?? '');

                if ($deliveredAt !== '' && $date < $deliveredAt) {
                    throw new ProductionConflictException(
                        'تاريخ الاستلام لا يسبق تاريخ التسليم إلى القص.',
                        ['ohdid' => $ohdid, 'delivered_at' => $deliveredAt, 'date' => $date]
                    );
                }

                // 1) صرف من مخزن القص
                $this->insertDetail([
                    'did' => $this->nextDetailId(),
                    'mid' => $masterId,
                    'bill_no' => (int) $this->value($piece, 'BILL_NO'),
                    'year' => (int) $this->value($piece, 'YEAR'),
                    'type' => $this->type('out'),
                    'dat' => $date,
                    'dept' => $this->dept('cutting'),
                    'detail' => $this->arabic('صرف من القص   '.$cutter, 100),
                    'sanf_no' => $this->numeric($this->value($piece, 'SANF_NO')),
                    'sanf_dno' => $this->numeric($this->value($piece, 'SANF_DNO')),
                    'ohid' => $ohid,
                    'ohdid' => $ohdid,
                    'ordsid' => ProductionPolicy::STATUS_CUT_DONE,
                    'cutter_no' => $cutterNo,
                    'user_id' => $userId,
                    'user_name' => $userName,
                ]);

                // 2) توريد إلى المخزن الرئيسي
                $this->insertDetail([
                    'did' => $this->nextDetailId(),
                    'mid' => $masterId,
                    'bill_no' => (int) $this->value($piece, 'BILL_NO'),
                    'year' => (int) $this->value($piece, 'YEAR'),
                    'type' => $this->type('in'),
                    'dat' => $date,
                    'dept' => $this->dept('main'),
                    'detail' => $this->arabic('استلام من القص   '.$cutter, 100),
                    'sanf_no' => $this->numeric($this->value($piece, 'SANF_NO')),
                    'sanf_dno' => $this->numeric($this->value($piece, 'SANF_DNO')),
                    'ohid' => $ohid,
                    'ohdid' => $ohdid,
                    'ordsid' => ProductionPolicy::STATUS_RECEIVED_FROM_CUT,
                    'cutter_no' => $cutterNo,
                    'user_id' => $userId,
                    'user_name' => $userName,
                ]);

                $affected = $conn->update(
                    'UPDATE ACCOUNTS.MULTI_CLOTHS
                        SET ORDSDID = 4,
                            CUTTING_DONE = 1,
                            CUTTING_DATE = TO_DATE(:dat, \'YYYY-MM-DD\'),
                            TIME1 = TO_CHAR(SYSDATE, \'HH24:MI\'),
                            CUTTER_PCS_PRICE1 = NVL(CUTTER_PCS_PRICE1,
                                (SELECT MAX(cd.PIECE_PRICE)
                                   FROM ACCOUNTS.CUTTER_DETAILS cd, ACCOUNTS.SALES_BILL sb
                                  WHERE cd.CUTTER_NO = :cutter_price
                                    AND cd.STYLE_NO = MULTI_CLOTHS.SHAPE_STYLE_NO
                                    AND cd.CUST_GROUP_ID = sb.GROUP_NO
                                    AND sb.OHID = MULTI_CLOTHS.OHID)),
                            UPDATED_BY_NAME = :user_name
                      WHERE OHDID = :ohdid AND ORDSDID = 2',
                    [
                        'dat' => $date,
                        'cutter_id' => $cutterNo,
                        'cutter_price' => $cutterNo,
                        'ohdid' => $ohdid,
                        'user_name' => $this->oracleText($userName, 20),
                    ]
                );

                if ($affected !== 1) {
                    throw new ProductionConflictException(
                        'تغيّرت حالة إحدى القطع قبل الاستلام، حدّث القائمة.',
                        ['ohdid' => $ohdid, 'expected' => ProductionPolicy::STATUS_AT_CUTTER]
                    );
                }
            }

            $invoiceIds = $this->invoiceIds($pieces);
            foreach ($invoiceIds as $ohid) {
                $this->recomputeInvoiceStatus($ohid);
            }

            $this->audit->success('production.receive_cut', [
                'user_id' => $userId,
                'module_code' => 'prod_from_cut',
                'entity_type' => 'production_document',
                'entity_id' => $masterId,
                'description_ar' => $this->clip(
                    'استلام '.count($pieces).' قطعة من القصاص '.$cutter.' — السند '.$masterId,
                    480
                ),
            ], $request);

            $conn->commit();

            return [
                'document_no' => $masterId,
                'pieces' => count($pieces),
                'invoices' => count($invoiceIds),
            ];
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }

    /**
     * تراجع عن تسليم القص — 2 ⇒ 1، بشرط عدم وجود استلام لاحق
     * Undo a cut delivery — 2 ⇒ 1, only before any receipt movement exists.
     *
     * @param  list<array{ohid: int, ohdids?: list<int>}>  $items
     * @return array{pieces: int, invoices: int}
     */
    public function undoDelivery(
        array $items,
        ?string $reason,
        ?int $userId,
        ?string $userName,
        ?Request $request
    ): array {
        $conn = DB::connection('oracle');
        $conn->beginTransaction();

        try {
            $pieces = $this->resolvePieces(ProductionPolicy::SECTION_FROM_CUT, $items);
            $invoiceIds = [];

            foreach ($pieces as $piece) {
                $ohdid = (int) $this->value($piece, 'OHDID');
                $ohid = (int) $this->value($piece, 'OHID');
                $invoiceIds[$ohid] = true;

                $later = (array) $conn->selectOne(
                    'SELECT COUNT(*) AS C FROM ACCOUNTS.CUTING_TRANSD
                      WHERE OHDID = :ohdid AND ORDSID IN (3, 4) AND NVL(CANCEL, 0) <> 1',
                    ['ohdid' => $ohdid]
                );
                $count = (int) ($later['C'] ?? $later['c'] ?? 0);

                if ($count > 0) {
                    throw new ProductionConflictException(
                        'لا يمكن التراجع عن التسليم بعد الاستلام من القص.',
                        ['ohdid' => $ohdid, 'later_movements' => $count]
                    );
                }

                $conn->update(
                    'UPDATE ACCOUNTS.CUTING_TRANSD
                        SET CANCEL = 1, CANCEL_NOTE = :reason, CANCEL_DATE = SYSDATE,
                            UPDATED_BY_NAME = :user_name
                      WHERE OHDID = :ohdid AND ORDSID = 2 AND NVL(CANCEL, 0) <> 1',
                    [
                        'ohdid' => $ohdid,
                        'reason' => $this->oracleText($reason, 50),
                        'user_name' => $this->oracleText($userName, 20),
                    ]
                );

                $affected = $conn->update(
                    'UPDATE ACCOUNTS.MULTI_CLOTHS
                        SET ORDSDID = 1, CUTTING_DONE = NULL, CUTTING_DATE = NULL,
                            TIME1 = NULL, CUTTER_NO1 = NULL, CUT_QTY = NULL,
                            UPDATED_BY_NAME = :user_name
                      WHERE OHDID = :ohdid AND ORDSDID = 2',
                    ['ohdid' => $ohdid, 'user_name' => $this->oracleText($userName, 20)]
                );

                if ($affected !== 1) {
                    throw new ProductionConflictException(
                        'تغيّرت حالة إحدى القطع قبل التراجع، حدّث القائمة.',
                        ['ohdid' => $ohdid, 'expected' => ProductionPolicy::STATUS_AT_CUTTER]
                    );
                }
            }

            foreach (array_keys($invoiceIds) as $ohid) {
                $this->recomputeInvoiceStatus((int) $ohid);
            }

            $this->audit->success('production.undo_delivery', [
                'user_id' => $userId,
                'module_code' => 'prod_to_cut',
                'entity_type' => 'sales_bill',
                'entity_id' => implode(',', array_slice(array_keys($invoiceIds), 0, 10)),
                'description_ar' => 'تراجع عن تسليم '.count($pieces).' قطعة إلى القص',
            ], $request);

            $conn->commit();

            return ['pieces' => count($pieces), 'invoices' => count($invoiceIds)];
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }

    /**
     * تراجع عن استلام القص — 4 ⇒ 2، وإعادة فتح حركة التسليم
     * Undo a cut receipt — 4 ⇒ 2, reopening the delivery movement.
     *
     * @param  list<array{ohid: int, ohdids?: list<int>}>  $items
     * @return array{pieces: int, invoices: int}
     */
    public function undoReceipt(
        array $items,
        ?string $reason,
        ?int $userId,
        ?string $userName,
        ?Request $request
    ): array {
        $conn = DB::connection('oracle');
        $conn->beginTransaction();

        try {
            $pieces = $this->resolvePieces(ProductionPolicy::SECTION_TO_TAILOR, $items);
            $invoiceIds = [];

            foreach ($pieces as $piece) {
                $ohdid = (int) $this->value($piece, 'OHDID');
                $ohid = (int) $this->value($piece, 'OHID');
                $invoiceIds[$ohid] = true;

                $later = (array) $conn->selectOne(
                    'SELECT COUNT(*) AS C FROM ACCOUNTS.CUTING_TRANSD
                      WHERE OHDID = :ohdid AND ORDSID >= 5 AND NVL(CANCEL, 0) <> 1',
                    ['ohdid' => $ohdid]
                );
                $count = (int) ($later['C'] ?? $later['c'] ?? 0);

                if ($count > 0) {
                    throw new ProductionConflictException(
                        'لا يمكن التراجع عن الاستلام بعد تسليم القطعة إلى الخياط.',
                        ['ohdid' => $ohdid, 'later_movements' => $count]
                    );
                }

                $conn->update(
                    'UPDATE ACCOUNTS.CUTING_TRANSD
                        SET CANCEL = 1, CANCEL_NOTE = :reason, CANCEL_DATE = SYSDATE,
                            UPDATED_BY_NAME = :user_name
                      WHERE OHDID = :ohdid AND ORDSID IN (3, 4) AND NVL(CANCEL, 0) <> 1',
                    [
                        'ohdid' => $ohdid,
                        'reason' => $this->oracleText($reason, 50),
                        'user_name' => $this->oracleText($userName, 20),
                    ]
                );

                $conn->update(
                    'UPDATE ACCOUNTS.CUTING_TRANSD
                        SET POST = 0, UPDATED_BY_NAME = :user_name
                      WHERE OHDID = :ohdid AND ORDSID = 2 AND NVL(CANCEL, 0) <> 1',
                    ['ohdid' => $ohdid, 'user_name' => $this->oracleText($userName, 20)]
                );

                $affected = $conn->update(
                    'UPDATE ACCOUNTS.MULTI_CLOTHS
                        SET ORDSDID = 2, CUTTING_DONE = NULL, CUTTING_DATE = NULL,
                            UPDATED_BY_NAME = :user_name
                      WHERE OHDID = :ohdid AND ORDSDID = 4',
                    ['ohdid' => $ohdid, 'user_name' => $this->oracleText($userName, 20)]
                );

                if ($affected !== 1) {
                    throw new ProductionConflictException(
                        'تغيّرت حالة إحدى القطع قبل التراجع، حدّث القائمة.',
                        ['ohdid' => $ohdid, 'expected' => ProductionPolicy::STATUS_RECEIVED_FROM_CUT]
                    );
                }
            }

            foreach (array_keys($invoiceIds) as $ohid) {
                $this->recomputeInvoiceStatus((int) $ohid);
            }

            $this->audit->success('production.undo_receipt', [
                'user_id' => $userId,
                'module_code' => 'prod_from_cut',
                'entity_type' => 'sales_bill',
                'entity_id' => implode(',', array_slice(array_keys($invoiceIds), 0, 10)),
                'description_ar' => 'تراجع عن استلام '.count($pieces).' قطعة من القص',
            ], $request);

            $conn->commit();

            return ['pieces' => count($pieces), 'invoices' => count($invoiceIds)];
        } catch (Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }

    /**
     * تحديد القطع المطلوبة والتحقق من حالتها المتوقعة
     * Resolve the requested pieces and assert their expected state.
     *
     * @param  list<array{ohid: int, ohdids?: list<int>}>  $items
     * @param  bool  $allowAnyStatus  للقراءة فقط في مسارات التراجع
     * @return list<array<string, mixed>>
     */
    private function resolvePieces(
        string $section,
        array $items,
        ?int $cutterNo = null,
        bool $allowAnyStatus = false
    ): array {
        $expected = ProductionPolicy::SECTIONS[$section]['from'];
        $rows = [];
        $seen = [];
        $requestedTotal = 0;

        foreach ($items as $item) {
            $ohid = (int) ($item['ohid'] ?? 0);
            if ($ohid <= 0) {
                throw new \InvalidArgumentException('رقم الفاتورة غير صالح.');
            }

            $requested = array_values(array_unique(array_map(
                'intval',
                is_array($item['ohdids'] ?? null) ? $item['ohdids'] : []
            )));
            $requested = array_values(array_filter($requested, static fn (int $id): bool => $id > 0));

            $binds = ['ohid' => $ohid];
            $sql = 'SELECT mc.OHDID, mc.OHID, mc.BILL_NO, mc.YEAR, mc.SANF_NO, mc.SANF_DNO,
                           mc.SHAPE_STYLE_NO, mc.CUTTER_PCS_PRICE1, mc.ORDSDID,
                           TO_CHAR(mc.CUTTING_DATE, \'YYYY-MM-DD\') AS CUTTING_DATE_ISO,
                           NVL(mc.F_CANCEL, 0) AS F_CANCEL,
                           NVL(mc.CUTTING_DONE, 0) AS CUTTING_DONE,
                           NVL(sb.BILLS_KINDS, 1) AS BILLS_KINDS,
                           NVL(sb.HANDING_DONE, 0) AS HANDING_DONE,
                           mc.CUTTER_NO1
                      FROM ACCOUNTS.MULTI_CLOTHS mc
                      JOIN ACCOUNTS.SALES_BILL sb ON sb.OHID = mc.OHID
                     WHERE mc.OHID = :ohid AND NVL(mc.F_CANCEL, 0) = 0';

            if (! $allowAnyStatus) {
                $sql .= ' AND mc.ORDSDID = :status';
                $binds['status'] = $expected;
            }

            if ($cutterNo !== null) {
                $sql .= ' AND mc.CUTTER_NO1 = :cutter_no';
                $binds['cutter_no'] = $cutterNo;
            }

            if ($requested !== []) {
                $placeholders = [];
                foreach ($requested as $index => $ohdid) {
                    $key = 'ohdid'.$index;
                    $placeholders[] = ':'.$key;
                    $binds[$key] = $ohdid;
                }
                $sql .= ' AND mc.OHDID IN ('.implode(',', $placeholders).')';
            }

            $found = DB::connection('oracle')->select($sql, $binds);

            if ($requested !== [] && count($found) !== count($requested)) {
                throw new ProductionConflictException(
                    'بعض القطع لم تعد في الحالة المتوقعة، حدّث القائمة.',
                    [
                        'ohid' => $ohid,
                        'requested' => count($requested),
                        'found' => count($found),
                        'expected' => $expected,
                    ]
                );
            }

            if ($found === []) {
                throw new ProductionConflictException(
                    'لا توجد قطع مؤهلة في هذه الفاتورة، ربما عالجها مستخدم آخر.',
                    ['ohid' => $ohid, 'expected' => $expected]
                );
            }

            foreach ($found as $row) {
                $candidate = (array) $row;
                $this->assertInvoiceRules($section, $candidate);
                $key = (int) $this->value($candidate, 'OHDID');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = $candidate;
            }

            $requestedTotal += count($found);
            if ($requestedTotal > (int) config('production.limits.max_pieces', 200)) {
                throw new \InvalidArgumentException('عدد القطع في العملية يتجاوز الحد المسموح.');
            }
        }

        return $rows;
    }

    /**
     * شروط الفاتورة التي لا تكفي حالة القطعة وحدها
     * Invoice-level rules that the piece status alone cannot express.
     *
     * @param  array<string, mixed>  $row
     */
    private function assertInvoiceRules(string $section, array $row): void
    {
        $kinds = (int) $this->value($row, 'BILLS_KINDS');
        $handing = (int) $this->value($row, 'HANDING_DONE');
        $cuttingDone = (int) $this->value($row, 'CUTTING_DONE');

        if ($section === ProductionPolicy::SECTION_APPROVAL) {
            if ($kinds !== 1) {
                throw new ProductionConflictException(
                    'لا يمكن اعتماد قطعة من فاتورة موقوفة أو ملغية أو معلقة.',
                    ['ohid' => (int) $this->value($row, 'OHID'), 'bills_kinds' => $kinds]
                );
            }

            if ($handing !== 0 || $cuttingDone !== 0) {
                throw new ProductionConflictException(
                    'الفاتورة سبق تسليمها أو قصّها، لا يمكن اعتماد قطعها.',
                    ['ohid' => (int) $this->value($row, 'OHID')]
                );
            }
        }

        if ($section !== ProductionPolicy::SECTION_APPROVAL && in_array($kinds, [2, 3, 4], true)) {
            throw new ProductionConflictException(
                'الفاتورة موقوفة أو ملغية أو معلقة، لا يمكن تنفيذ العملية على قطعها.',
                ['ohid' => (int) $this->value($row, 'OHID'), 'bills_kinds' => $kinds]
            );
        }
    }

    /**
     * إعادة حساب حالة الفاتورة = أدنى حالة قطعة نشطة (أضعف قطعة)
     * Recompute the invoice status as the minimum active piece status (the weakest piece).
     */
    private function recomputeInvoiceStatus(int $ohid): void
    {
        DB::connection('oracle')->update(
            'UPDATE ACCOUNTS.SALES_BILL sb
                SET ORDSID = COALESCE((
                    SELECT MIN(mc.ORDSDID)
                      FROM ACCOUNTS.MULTI_CLOTHS mc
                     WHERE mc.OHID = sb.OHID
                       AND NVL(mc.F_CANCEL, 0) = 0
                       AND mc.ORDSDID IN (0, 1, 2, 4, 6, 8, 10, 11)
                ), sb.ORDSID)
              WHERE sb.OHID = :ohid AND sb.BILLS_KINDS = 1',
            ['ohid' => $ohid]
        );
    }

    /**
     * قفل جدول الترقيم — يمنع تطبيقنا وشاشات Forms من أخذ الرقم نفسه
     * Lock the numbering table so our app and the legacy Forms cannot take the same id.
     */
    private function lockNumbering(): void
    {
        $wait = (int) config('production.lock_wait_seconds', 5);

        try {
            DB::connection('oracle')->statement(
                'LOCK TABLE ACCOUNTS.CUTING_TRANSM IN SHARE ROW EXCLUSIVE MODE WAIT '.$wait
            );
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'ORA-00054')) {
                throw new ProductionLockException(
                    'النظام القديم ينفّذ عملية على نفس الجداول، أعد المحاولة بعد لحظات.'
                );
            }

            throw $e;
        }
    }

    private function nextMasterId(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(TR_INV_M_ID), 0) + 1 AS N FROM ACCOUNTS.CUTING_TRANSM'
        );

        return (int) ($row['N'] ?? $row['n'] ?? 1);
    }

    private function nextDetailId(): int
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT NVL(MAX(TR_INV_D_ID), 0) + 1 AS N FROM ACCOUNTS.CUTING_TRANSD'
        );

        return (int) ($row['N'] ?? $row['n'] ?? 1);
    }

    /**
     * إدراج رأس سند الحركة — كل القيم المقيسة من العينة الحقيقية
     * Insert the movement header — values measured from a real legacy row.
     *
     * @param  array<string, mixed>  $data
     */
    private function insertMaster(array $data): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.CUTING_TRANSM
                (TR_INV_M_ID, BILL_NO, YEAR, BILL_TYPE, TYPENO, DAT, GOV_NO, STORE_NO,
                 DETAILES, COSTCN_ID, TOTAL, SANF_NUMS, CUR_NO, CUR_PRICE, NET,
                 CUR_NO1, CUR_PRICE1, SELECT1, ACC_NO, ACC_NO1, SEQ_BILL, PROCESS_NO,
                 FLAG_POST, FLAG_CANCEL, RCV_DONE, CREATE_DATE, CREATED_ON, BRANCH_NO,
                 ORDSID, DEPTID, CUTTER_NO)
             VALUES
                (:mid, :bill_no, :year, 1, 4, TO_DATE(:dat, \'YYYY-MM-DD\'), 1, :dept_store,
                 :details, 1, 0, :pieces, 1, 1, 0,
                 1, 1, 2, 12310000, 41210001, :mid_seq, :mid_proc,
                 0, 0, 0, TO_CHAR(SYSDATE, \'DD-MON-YY\', \'NLS_DATE_LANGUAGE=ENGLISH\'), SYSDATE, NULL,
                 :ordsid, :dept_id, :cutter_no)',
            [
                'mid' => $data['mid'],
                'bill_no' => $data['bill_no'],
                'year' => $data['year'],
                'dat' => $data['date'],
                'dept_store' => $data['dept'],
                'details' => $data['details'],
                'pieces' => $data['pieces'],
                'mid_seq' => $data['mid'],
                'mid_proc' => $data['mid'],
                'ordsid' => $data['ordsid'],
                'dept_id' => $data['dept'],
                'cutter_no' => $data['cutter_no'],
            ]
        );
    }

    /**
     * إدراج تفصيل حركة — POST=1 سارية، CANCEL=0 غير ملغاة
     * Insert a movement detail — POST=1 active, CANCEL=0 not cancelled.
     *
     * @param  array<string, mixed>  $data
     */
    private function insertDetail(array $data): void
    {
        DB::connection('oracle')->insert(
            'INSERT INTO ACCOUNTS.CUTING_TRANSD
                (TR_INV_D_ID, TR_INV_M_ID, BILL_NO, YEAR, TYPENO, DDATE, DEPTID,
                 STORENO, DETAILE, SANF_NO, SANF_DNO, QTY, PCS, OHID, OHDID,
                 ORDSID, CUTTER_NO, POST, CANCEL, CREATEBY, CREATEDATE, CREATED_BY_NAME)
             VALUES
                (:did, :mid, :bill_no, :year, :typeno, TO_DATE(:dat, \'YYYY-MM-DD\'),
                 :dept_id, :dept_store, :detail, :sanf_no, :sanf_dno, NULL, 1, :ohid, :ohdid,
                 :ordsid, :cutter_no, 1, 0, :user_id, TO_CHAR(SYSDATE, \'DD-MON-YY\', \'NLS_DATE_LANGUAGE=ENGLISH\'), :user_name)',
            [
                'did' => $data['did'],
                'mid' => $data['mid'],
                'bill_no' => $data['bill_no'],
                'year' => $data['year'],
                'typeno' => $data['type'],
                'dat' => $data['date'],
                'dept_id' => $data['dept'],
                'dept_store' => $data['dept'],
                'detail' => $data['detail'],
                'sanf_no' => $data['sanf_no'],
                'sanf_dno' => $data['sanf_dno'],
                'ohid' => $data['ohid'],
                'ohdid' => $data['ohdid'],
                'ordsid' => $data['ordsid'],
                'cutter_no' => $data['cutter_no'],
                'user_id' => $data['user_id'],
                'user_name' => $this->oracleText($data['user_name'], 20),
            ]
        );
    }

    /**
     * القصاص موجود؟ الاسم يُستخدم في البيان والتدقيق
     * Does the cutter exist? The name feeds the details and the audit row.
     */
    private function cutter(int $cutterNo): string
    {
        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT CUTTER_NAME FROM ACCOUNTS.CUTTER WHERE CUTTER_NO = :no',
            ['no' => $cutterNo]
        );

        $name = $this->value($row, 'CUTTER_NAME');

        if ($name === null || $name === '') {
            throw new \InvalidArgumentException('القصاص غير موجود.');
        }

        return (string) $name;
    }

    /**
     * تاريخ اليوم من أوراكل لا من PHP — المنطقة الزمنية في PHP هي UTC (C13)
     * Today from Oracle, not PHP — the PHP timezone is UTC while the database is +03:00.
     */
    private function dateOrToday(?string $date): string
    {
        if ($date !== null && $date !== '') {
            return $date;
        }

        $row = (array) DB::connection('oracle')->selectOne(
            'SELECT TO_CHAR(SYSDATE, \'YYYY-MM-DD\') AS D FROM dual'
        );

        return (string) ($row['D'] ?? $row['d'] ?? date('Y-m-d'));
    }

    private function dept(string $key): int
    {
        return (int) config('production.departments.'.$key);
    }

    private function type(string $key): int
    {
        return (int) config('production.types.'.$key);
    }

    /**
     * @param  list<array<string, mixed>>  $pieces
     * @return list<int>
     */
    private function invoiceIds(array $pieces): array
    {
        $ids = [];

        foreach ($pieces as $piece) {
            $ids[(int) $this->value($piece, 'OHID')] = true;
        }

        return array_keys($ids);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function value(array $row, string $key): mixed
    {
        return $row[$key] ?? $row[strtolower($key)] ?? null;
    }

    private function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function arabic(string $text, int $limit): string
    {
        return (string) ArabicEncoding::toOracleSearchChars($this->clip($text, $limit));
    }

    /**
     * نص عربي إلى صيغة أوراكل مع قصّه — كل اسم مستخدم وسبب إلغاء يمر من هنا
     * Arabic text to the Oracle form, clipped — every user name and cancel reason goes here.
     */
    private function oracleText(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }

        $clipped = $this->clip($text, $limit);

        return preg_match('/[\x{0600}-\x{06FF}]/u', $clipped) === 1
            ? ArabicEncoding::toOracleSearchChars($clipped)
            : $clipped;
    }

    private function clip(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }
}
