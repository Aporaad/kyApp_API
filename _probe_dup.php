<?php

use Illuminate\Support\Facades\DB;

$db = DB::connection('oracle');

// هل ORDSID مكرر في ORDSTATE؟ (مصدر تضاعف صفوف الحركة)
$dup = $db->select(
    'SELECT ORDSID, COUNT(*) C FROM ACCOUNTS.ORDSTATE
      GROUP BY ORDSID HAVING COUNT(*) > 1 ORDER BY 2 DESC'
);
echo 'ORDSTATE_DUP_ORDSID_ROWS='.count($dup).PHP_EOL;
foreach (array_slice($dup, 0, 6) as $r) {
    $a = array_change_key_case((array) $r);
    echo '  ordsid='.$a['ordsid'].' count='.$a['c'].PHP_EOL;
}

// عيّنة: كم صفاً لكل ORDSID مكرر، وهل الاسم ثابت؟
if ($dup !== []) {
    $sid = (int) (array_change_key_case((array) $dup[0])['ordsid']);
    $rows = $db->select(
        'SELECT ORDSID, ORDSNAME, TYPE_NO, DEPTID, RTN_SEQ, ACTIVE
           FROM ACCOUNTS.ORDSTATE WHERE ORDSID = '.$sid
    );
    foreach ($rows as $r) {
        echo '  ROW='.json_encode(array_change_key_case((array) $r), JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}

// DEPTS: هل DEPTID فريد؟
$ddup = $db->select(
    'SELECT DEPTID, COUNT(*) C FROM ACCOUNTS.DEPTS GROUP BY DEPTID HAVING COUNT(*) > 1'
);
echo 'DEPTS_DUP_DEPTID_ROWS='.count($ddup).PHP_EOL;

// قياس فعلي: عدد صفوف الحركة الأساسية مقابل عدد صفوف الربط بـ ORDSTATE
$one = $db->select(
    "SELECT ct.OHDID, COUNT(*) BASE
       FROM ACCOUNTS.CUTING_TRANSD ct
      WHERE NVL(ct.CANCEL,0) <> 1 AND ROWNUM <= 1
      GROUP BY ct.OHDID"
);
if ($one !== []) {
    $ohd = (int) (array_change_key_case((array) $one[0])['ohdid']);
    $base = (int) (array_change_key_case((array) $one[0])['base']);
    $joined = $db->select(
        'SELECT COUNT(*) C FROM ACCOUNTS.CUTING_TRANSD ct
           JOIN ACCOUNTS.ORDSTATE os ON os.ORDSID = ct.ORDSID
          WHERE ct.OHDID = '.$ohd.' AND NVL(ct.CANCEL,0) <> 1'
    );
    echo 'OHD='.$ohd.' base='.$base.' joined_os='.json_encode(array_change_key_case((array) $joined[0])).PHP_EOL;
}
