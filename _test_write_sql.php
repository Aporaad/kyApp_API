<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Support\Production\ProductionLineWriter;

$conn = DB::connection('oracle');
$writer = $app->make(ProductionLineWriter::class);
$mrow = (array) $conn->selectOne('SELECT NVL(MAX(TR_INV_M_ID),0)+1000000 AS N FROM ACCOUNTS.CUTING_TRANSM');
$mid = (int) ($mrow['N'] ?? $mrow['n'] ?? 0);
$did = (int) (((array) $conn->selectOne('SELECT NVL(MAX(TR_INV_D_ID),0)+1000000 AS N FROM ACCOUNTS.CUTING_TRANSD'))['N'] ?? $row['n'] ?? 0);

$master = new ReflectionMethod($writer, 'insertMaster');
$master->setAccessible(true);
$detail = new ReflectionMethod($writer, 'insertDetail');
$detail->setAccessible(true);

$conn->beginTransaction();
try {
    $master->invoke($writer, [
        'mid' => $mid, 'bill_no' => 1, 'year' => 2026, 'date' => '2026-11-26',
        'dept' => 21, 'ordsid' => 2, 'cutter_no' => 6, 'pieces' => 1,
        'details' => 'TEST', 'user_id' => 1, 'user_name' => 'TEST',
    ]);
    $detail->invoke($writer, [
        'did' => $did, 'mid' => $mid, 'bill_no' => 1, 'year' => 2026, 'type' => 4,
        'date' => '2026-11-26', 'dept' => 21, 'detail' => 'TEST',
        'sanf_no' => 1, 'sanf_dno' => 1, 'ohid' => 1, 'ohdid' => 1,
        'ordsid' => 2, 'cutter_no' => 6, 'user_id' => 1, 'user_name' => 'TEST',
    ]);
    $row = (array) $conn->selectOne('SELECT COUNT(*) AS C FROM ACCOUNTS.CUTING_TRANSM WHERE TR_INV_M_ID = :id', ['id' => $mid]);
    $row2 = (array) $conn->selectOne('SELECT COUNT(*) AS C FROM ACCOUNTS.CUTING_TRANSD WHERE TR_INV_D_ID = :id', ['id' => $did]);
    echo 'inserted master='.(int)($row['C'] ?? $row['c'] ?? 0).' detail='.(int)($row2['C'] ?? $row2['c'] ?? 0)."\n";
} finally {
    $conn->rollBack();
    echo "rolled back\n";
}
