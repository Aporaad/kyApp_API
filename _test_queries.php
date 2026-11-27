<?php

use App\Support\Production\ProductionQuery;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$q = $app->make(ProductionQuery::class);
echo "SUMMARY\n";
print_r($q->summary());
[$rows,$total] = $q->approvalInvoices(['search' => '', 'kind' => 'active'], 1, 2);
echo "APPROVAL total=$total rows=".count($rows)."\n";
if ($rows) {
    $r = (array) $rows[0];
    echo ' first keys: '.implode(',', array_slice(array_keys($r), 0, 8))."\n";
}
[$rows2,$t2] = $q->deliveryInvoices(['search' => ''], 1, 2);
echo "DELIVERY total=$t2 rows=".count($rows2)."\n";
$c = $q->cuttersForDelivery();
echo 'CUTTERS='.count($c)."\n";
$w = $q->cuttersWithWork();
echo 'CUTTERS_WORK='.count($w)."\n";
if ($w) {
    $x = (array) $w[0];
    echo ' work first: '.json_encode($x, JSON_UNESCAPED_UNICODE)."\n";
}
