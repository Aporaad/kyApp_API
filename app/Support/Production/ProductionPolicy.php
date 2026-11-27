<?php

namespace App\Support\Production;

/**
 * سياسة خط الإنتاج — خريطة واحدة للحالات والأقسام والوجهات
 * Production-line policy — one map of statuses, sections and destinations.
 *
 * الأرقام مقيسة من النظام القديم: حالة القطعة زوجية دائماً (0,1,2,4,6,8,10)،
 * والأرقام الفردية (3,5,7,9) حركات صرف مخزني تُنشأ داخل نفس سند الاستلام/التسليم.
 * The numbers are measured from the legacy system: a piece status is always even, while
 * the odd numbers are stock-issue movements created inside the same document.
 */
final class ProductionPolicy
{
    public const STATUS_NEW = 0;

    public const STATUS_APPROVED = 1;

    public const STATUS_AT_CUTTER = 2;

    public const STATUS_CUT_DONE = 3;

    public const STATUS_RECEIVED_FROM_CUT = 4;

    public const STATUS_TO_TAILOR = 5;

    public const STATUS_AT_TAILOR = 6;

    public const STATUS_TAILOR_DONE = 7;

    public const STATUS_RECEIVED_FROM_TAILOR = 8;

    public const STATUS_FINISHING = 9;

    public const STATUS_READY = 10;

    public const STATUS_DELIVERED = 11;

    public const SECTION_APPROVAL = 'approval';

    public const SECTION_TO_CUT = 'to_cut';

    public const SECTION_FROM_CUT = 'from_cut';

    public const SECTION_CUT_TRANSFER = 'cut_transfer';

    public const SECTION_TO_TAILOR = 'to_tailor';

    public const SECTION_FROM_TAILOR = 'from_tailor';

    public const SECTION_TAILOR_TRANSFER = 'tailor_transfer';

    public const SECTION_READY = 'ready';

    /**
     * الأقسام الثمانية بترتيب النافذة، مع الشاشات المنفّذة في المرحلة الأولى.
     * The eight sections in picker order, with the first-phase implemented screens.
     *
     * @var array<string, array{
     *     title: string, subtitle: string, view: string, action: string,
     *     action_label: string, from: int, to: int, available: bool
     * }>
     */
    public const SECTIONS = [
        self::SECTION_APPROVAL => [
            'title' => 'اعتماد الطلبات',
            'subtitle' => 'طلبات جديدة بانتظار الاعتماد',
            'view' => 'prod_approval.view',
            'action' => 'prod_approval.approve',
            'action_label' => 'اعتماد المحدد',
            'from' => self::STATUS_NEW,
            'to' => self::STATUS_APPROVED,
            'available' => true,
        ],
        self::SECTION_TO_CUT => [
            'title' => 'التسليم إلى القص',
            'subtitle' => 'قطع معتمدة بانتظار قصّاص',
            'view' => 'prod_to_cut.view',
            'action' => 'prod_to_cut.deliver',
            'action_label' => 'تسليم المحدد',
            'from' => self::STATUS_APPROVED,
            'to' => self::STATUS_AT_CUTTER,
            'available' => true,
        ],
        self::SECTION_FROM_CUT => [
            'title' => 'استلام من القص',
            'subtitle' => 'قطع عند القصاصين',
            'view' => 'prod_from_cut.view',
            'action' => 'prod_from_cut.receive',
            'action_label' => 'استلام المحدد',
            'from' => self::STATUS_AT_CUTTER,
            'to' => self::STATUS_RECEIVED_FROM_CUT,
            'available' => true,
        ],
        self::SECTION_CUT_TRANSFER => [
            'title' => 'التحويل بين القصاصين',
            'subtitle' => 'نقل قطعة من قصّاص إلى آخر',
            'view' => 'prod_cut_transfer.view',
            'action' => 'prod_cut_transfer.transfer',
            'action_label' => 'تحويل المحدد',
            'from' => self::STATUS_AT_CUTTER,
            'to' => self::STATUS_AT_CUTTER,
            'available' => false,
        ],
        self::SECTION_TO_TAILOR => [
            'title' => 'التسليم إلى الخياطين',
            'subtitle' => 'قطع جاهزة للخياطة',
            'view' => 'prod_to_tailor.view',
            'action' => 'prod_to_tailor.deliver',
            'action_label' => 'تسليم المحدد',
            'from' => self::STATUS_RECEIVED_FROM_CUT,
            'to' => self::STATUS_AT_TAILOR,
            'available' => false,
        ],
        self::SECTION_FROM_TAILOR => [
            'title' => 'استلام من الخياط',
            'subtitle' => 'قطع عند الخياطين',
            'view' => 'prod_from_tailor.view',
            'action' => 'prod_from_tailor.receive',
            'action_label' => 'استلام المحدد',
            'from' => self::STATUS_AT_TAILOR,
            'to' => self::STATUS_RECEIVED_FROM_TAILOR,
            'available' => false,
        ],
        self::SECTION_TAILOR_TRANSFER => [
            'title' => 'التحويل بين الخياطين',
            'subtitle' => 'نقل قطعة من خياط إلى آخر',
            'view' => 'prod_tailor_transfer.view',
            'action' => 'prod_tailor_transfer.transfer',
            'action_label' => 'تحويل المحدد',
            'from' => self::STATUS_AT_TAILOR,
            'to' => self::STATUS_AT_TAILOR,
            'available' => false,
        ],
        self::SECTION_READY => [
            'title' => 'التجهيز النهائي',
            'subtitle' => 'قطع مستلمة بانتظار الكي والتجهيز',
            'view' => 'prod_ready.view',
            'action' => 'prod_ready.finish',
            'action_label' => 'إنهاء التجهيز',
            'from' => self::STATUS_RECEIVED_FROM_TAILOR,
            'to' => self::STATUS_READY,
            'available' => false,
        ],
    ];

    /**
     * أكواد عرض الأقسام الثمانية — تُستخدم في حارس مسار العدادات.
     * The eight section view codes, used by the summary route guard.
     *
     * @return list<string>
     */
    public static function viewCodes(): array
    {
        return array_values(array_map(
            static fn (array $section): string => $section['view'],
            self::SECTIONS
        ));
    }

    /**
     * هل الشاشة منفّذة في المرحلة الحالية؟
     * Whether the screen is implemented in the current phase.
     */
    public static function isAvailable(string $section): bool
    {
        return self::SECTIONS[$section]['available'] ?? false;
    }

    /**
     * اسم الحالة العربي كما في ORDSTATE — للرسائل والتدقيق
     * Arabic status label, mirroring ORDSTATE — used in messages and audit rows.
     */
    public static function statusLabel(int $status): string
    {
        return match ($status) {
            self::STATUS_NEW => 'جديد',
            self::STATUS_APPROVED => 'معتمد',
            self::STATUS_AT_CUTTER => 'تسليم للقص',
            self::STATUS_CUT_DONE => 'تم القص',
            self::STATUS_RECEIVED_FROM_CUT => 'استلام من القص',
            self::STATUS_TO_TAILOR => 'تم التجهيز1',
            self::STATUS_AT_TAILOR => 'تسليم للخياط',
            self::STATUS_TAILOR_DONE => 'تم الخياط',
            self::STATUS_RECEIVED_FROM_TAILOR => 'استلام من الخياط',
            self::STATUS_FINISHING => 'تجهيز 2',
            self::STATUS_READY => 'انتظار التسليم',
            self::STATUS_DELIVERED => 'تم التسليم',
            default => 'حالة '.$status,
        };
    }

    /**
     * قصّ نص عربي إلى سعة العمود بلا كسر محرف
     * Clip an Arabic string to its column capacity without splitting a character.
     */
    public static function clip(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
    }
}
