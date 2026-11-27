<?php

namespace App\Support\Production;

use RuntimeException;

/**
 * تعارض حالة قطعة/فاتورة بين العرض والتنفيذ
 * A piece/invoice state conflict between display time and execution time.
 *
 * يُترجم إلى 409 في المتحكم، ويحمل الحالة المتوقعة والفعلية حتى تُحدّث الواجهة صفّها
 * بدل أن تعرض رسالة مبهمة.
 * The controller maps it to 409 with expected/actual states so the UI can refresh the row.
 */
final class ProductionConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
