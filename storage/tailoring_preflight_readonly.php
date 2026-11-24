<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$source = file_get_contents(__DIR__.'/preflight_tailoring_create.php');
$source = preg_replace('/\/\*.*?\*\//s', '', $source);
$source = preg_replace('/^use .*;$/m', '', $source);
$source = str_replace('ArabicEncoding::', 'App\Support\ArabicEncoding::', $source);
$source = str_replace('DB::', 'Illuminate\Support\Facades\DB::', $source);
eval('?>'.$source);
