<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Stores\StoreReportController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$admin = User::where('IS_ADMIN', 1)->first();

function testMovement($query)
{
    global $admin;
    $req = Request::create('/api/stores/reports/movement', 'GET', $query);
    $req->setUserResolver(fn () => $admin);
    $controller = app(StoreReportController::class);
    $res = $controller->movement($req);
    $data = json_decode($res->getContent(), true);
    echo 'QUERY: '.json_encode($query).PHP_EOL;
    echo 'STATUS: '.$res->getStatusCode().PHP_EOL;
    echo 'SUCCESS: '.($data['success'] ?? 'null').PHP_EOL;
    if (! ($data['success'] ?? false)) {
        echo 'MESSAGE: '.($data['message'] ?? '').PHP_EOL;
        echo 'ERROR: '.($data['error'] ?? '').PHP_EOL;
    } else {
        echo 'SCOPE: '.($data['meta']['scope'] ?? '').PHP_EOL;
        echo 'SECTIONS COUNT: '.count($data['meta']['sections'] ?? []).PHP_EOL;
        echo 'ROWS COUNT: '.count($data['data'] ?? []).PHP_EOL;
        echo 'TOTALS: '.json_encode($data['meta']['totals'] ?? []).PHP_EOL;
        if (! empty($data['meta']['sections'])) {
            echo 'FIRST SECTION: '.json_encode($data['meta']['sections'][0]).PHP_EOL;
        }
        if (! empty($data['data'])) {
            echo 'FIRST ROW: '.json_encode($data['data'][0]).PHP_EOL;
        }
    }
    echo '----------------------------------------'.PHP_EOL;
}

echo "=== 1. Branch Statement ===\n";
testMovement(['scope' => 'branch', 'sanf_no' => 561, 'sanf_dno' => 2010100875]);

echo "=== 2. Main Item Statement ===\n";
testMovement(['scope' => 'item', 'sanf_no' => 561]);
