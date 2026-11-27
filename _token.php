<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::query()->where('IS_ADMIN', 1)->first();
if (! $user) { fwrite(STDERR, "no admin\n"); exit(1); }
$token = $user->createToken('production-smoke')->plainTextToken;
echo $token, "\n";
