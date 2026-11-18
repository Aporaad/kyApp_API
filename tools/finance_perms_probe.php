<?php

/**
 * فحص قراءة فقط: عدد أكواد القيود والسندات في APP_PERMISSIONS
 * Read-only check: how many finance codes live in APP_PERMISSIONS.
 */
$row = DB::connection('oracle')->selectOne(
    "SELECT COUNT(*) AS C FROM ACCOUNTS.APP_PERMISSIONS
      WHERE REGEXP_LIKE(PERMISSION_CODE, '^(journal|journal_temp|receipts|payments|fin_search|wages)\\.')"
);

$data = (array) $row;

echo 'FINANCE_PERMS='.(int) reset($data).PHP_EOL;
