<?php

/**
 * فحص قراءة فقط: أحجام جداول القيود والسندات كما هي الآن
 * Read-only check: the current row counts of the entries and voucher tables.
 */
$tables = [
    'ENTRY_MAIN',
    'ENTRY_SUB',
    'ENTRY_MAIN2',
    'ENTRY_MAIN3',
    'ENTRY_MAIN_PRIVILAGE',
    'CASH_RECEIVE',
    'CASH_SARF',
    'BANK_RECEIVE',
    'BANK_SARF',
];

foreach ($tables as $table) {
    $row = (array) DB::connection('oracle')->selectOne("SELECT COUNT(*) AS C FROM ACCOUNTS.{$table}");
    echo $table.'='.(int) reset($row).PHP_EOL;
}

$multi = (array) DB::connection('oracle')->selectOne(
    'SELECT COUNT(*) AS C FROM (
        SELECT ENTRY_NO FROM ACCOUNTS.ENTRY_SUB GROUP BY ENTRY_NO HAVING COUNT(*) > 2
     )'
);
echo 'MULTI_ENTRIES='.(int) reset($multi).PHP_EOL;
