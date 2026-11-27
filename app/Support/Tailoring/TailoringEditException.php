<?php

namespace App\Support\Tailoring;

use RuntimeException;

/**
 * خطأ تعديل فاتورة التفصيل مع رمز HTTP وسبب آلي.
 * A tailoring edit error with an HTTP status and machine-readable reason.
 */
final class TailoringEditException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $reason = 'invalid_payload',
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }
}
