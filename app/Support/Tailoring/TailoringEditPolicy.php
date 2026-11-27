<?php

namespace App\Support\Tailoring;

/**
 * سياسة تعديل فاتورة التفصيل — التعديل الكامل قبل «تسليم للقص» فقط.
 * The tailoring edit policy — full content edits stop at cutting handover.
 *
 * ── `ORDSID = 2` هو «تسليم للقص» في ORDSTATE، ومصدر FMB الأصلي يحذف القطع
 *    فقط عندما `ORDSDID < 2`. المال لا يُقيَّد بهذه المرحلة أبداً.
 * ── ORDSID 2 is "cutting handover"; the legacy FMB deletes pieces only below it.
 *    Financial fields are never restricted by this policy.
 */
final class TailoringEditPolicy
{
    public const CUTTING_HANDOVER_ORDSID = 2;

    /**
     * تقييم قدرات التعديل من لقطة جاهزة.
     * Evaluate edit capabilities from an already-built snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function evaluate(array $snapshot): array
    {
        $stage = (int) ($snapshot['stage']['max_ordsid'] ?? 0);
        $hasCuttingEvidence = (bool) ($snapshot['stage']['cutting_evidence'] ?? false);
        $reached = $stage >= self::CUTTING_HANDOVER_ORDSID || $hasCuttingEvidence;
        $reasons = [];

        if ($reached) {
            $reasons[] = 'cutting_handover_reached';
        }

        return [
            'mode' => $reached ? 'financial_safe' : 'full',
            'cutting_handover_reached' => $reached,
            'financial_editable' => true,
            'reasons' => $reasons,
            'locked_groups' => $reached ? [
                'customer',
                'worker',
                'models',
                'measurements',
                'specs',
                'fabric_identity',
                'piece_structure',
            ] : [],
        ];
    }

    /**
     * هل يمكن تعديل المحتوى الكامل؟
     * May the invoice content be fully edited?
     */
    public static function canEditContent(array $snapshot): bool
    {
        return self::evaluate($snapshot)['mode'] === 'full';
    }
}
