<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
$conn = DB::connection('oracle');

$one = (array) $conn->selectOne('SELECT * FROM (SELECT OHDID, CUTTER_NO1, SHAPE_STYLE_NO, OHID FROM ACCOUNTS.MULTI_CLOTHS WHERE ORDSDID = 1 AND NVL(F_CANCEL,0)=0) WHERE ROWNUM <= 1');
$two = (array) $conn->selectOne('SELECT * FROM (SELECT OHDID, CUTTER_NO1, SHAPE_STYLE_NO, OHID FROM ACCOUNTS.MULTI_CLOTHS WHERE ORDSDID = 2 AND NVL(F_CANCEL,0)=0) WHERE ROWNUM <= 1');

$conn->beginTransaction();
try {
    $a = $conn->update(
        'UPDATE ACCOUNTS.MULTI_CLOTHS
            SET ORDSDID = 2, CUTTING_DONE = 1,
                CUTTING_DATE = TO_DATE(:dat, \'YYYY-MM-DD\'),
                TIME1 = TO_CHAR(SYSDATE, \'HH24:MI\'),
                CUTTER_NO1 = :cutter_id, CUT_QTY = QTY,
                CUTTER_PCS_PRICE1 = NVL(CUTTER_PCS_PRICE1,
                    (SELECT MAX(cd.PIECE_PRICE) FROM ACCOUNTS.CUTTER_DETAILS cd, ACCOUNTS.SALES_BILL sb
                      WHERE cd.CUTTER_NO = :cutter_price AND cd.STYLE_NO = MULTI_CLOTHS.SHAPE_STYLE_NO
                        AND cd.CUST_GROUP_ID = sb.GROUP_NO AND sb.OHID = MULTI_CLOTHS.OHID)),
                UPDATED_BY_NAME = :user_name
          WHERE OHDID = :ohdid AND ORDSDID = 1',
        ['dat' => '2026-11-26', 'cutter_id' => 6, 'cutter_price' => 6, 'user_name' => 'TEST', 'ohdid' => $one['OHDID'] ?? $one['ohdid'] ?? 0]
    );
    $b = $conn->update(
        'UPDATE ACCOUNTS.MULTI_CLOTHS
            SET ORDSDID = 4, CUTTING_DONE = 1,
                CUTTING_DATE = TO_DATE(:dat, \'YYYY-MM-DD\'),
                TIME1 = TO_CHAR(SYSDATE, \'HH24:MI\'),
                CUTTER_PCS_PRICE1 = NVL(CUTTER_PCS_PRICE1,
                    (SELECT MAX(cd.PIECE_PRICE) FROM ACCOUNTS.CUTTER_DETAILS cd, ACCOUNTS.SALES_BILL sb
                      WHERE cd.CUTTER_NO = :cutter_price AND cd.STYLE_NO = MULTI_CLOTHS.SHAPE_STYLE_NO
                        AND cd.CUST_GROUP_ID = sb.GROUP_NO AND sb.OHID = MULTI_CLOTHS.OHID)),
                UPDATED_BY_NAME = :user_name
          WHERE OHDID = :ohdid AND ORDSDID = 2',
        ['dat' => '2026-11-26', 'cutter_price' => $two['CUTTER_NO1'] ?? $two['cutter_no1'] ?? 6, 'user_name' => 'TEST', 'ohdid' => $two['OHDID'] ?? $two['ohdid'] ?? 0]
    );
    echo "delivery_update=$a receipt_update=$b\n";
} finally {
    $conn->rollBack();
    echo "rolled back\n";
}
