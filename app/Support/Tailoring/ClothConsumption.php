<?php

namespace App\Support\Tailoring;

use App\Support\ArabicEncoding;
use Illuminate\Support\Facades\DB;

/**
 * حساب استهلاك القماش — نقل حرفي لإجراء النموذج القديم CONSUMED_HINSH_PROC
 * Cloth consumption — a literal port of the legacy form's CONSUMED_HINSH_PROC.
 *
 * ── قواعد لا تُكسر (من الخطة §9.1 والمصدر الحرفي):
 *    1. الفروع بنفس ترتيب الأصل؛ أول فرع مطابق يفوز ولا يُعاد الترتيب أبداً.
 *    2. ROUND وليس TRUNC ولا تقريباً مزدوجاً — أوراكل ROUND نصف بعيداً عن الصفر مثل PHP.
 *    3. PRO_GROUP2/4 تصل جاهزين من المتحكم (مقروءين من PRO_DTYPE)، ولا يُخمَّنان من النص.
 *    4. QTY يُضرب بعد اختيار الفرع لا قبله.
 *    5. YARDS = HINSH / 36 بلا تقريب إضافي؛ العمود NUMBER(6,2) يخزّن منزلتين.
 *    6. HINSH1 و SHARKH='20شرخ' في فروع الشرخ فقط، ولا يُكتبان في غيرها.
 *    7. الاحتياط الإلزامي على CALC_BASE_MEASURES(+DETAILES): أول بحث بطول دقيق ثم نطاق،
 *       وإذا لم يوجد — NULL ولا رقم يُخترع (متضمناً دلالة TOO_MANY_ROWS: تعدد النتائج ⇒ لا ملء).
 * ── Rules (plan §9.1 + the literal source): branch order is sacred, ROUND not TRUNC, the
 *    PRO_GROUPs arrive pre-read from PRO_DTYPE, QTY multiplies after the branch, YARDS =
 *    HINSH/36 with no extra rounding, HINSH1/SHARKH only in sharkh branches, and the
 *    mandatory fallback keeps the legacy TOO_MANY_ROWS semantics (multiple results ⇒ null).
 *
 * ── الفرعان المستقلان 310 و312 يسبقان كتلة 301/302 كما في الأصل — قيم TYPENO لا تتقاطع
 *    بين الكتل فلا تعارض، والبنية تُحفظ حرفياً.
 * ── The independent 310/312 blocks precede the 301/302 block exactly as in the source.
 */
final class ClothConsumption
{
    /** القيمة الحرفية للشرخ كما يكتبها النظام القديم / the literal sharkh value the legacy writes */
    public const SHARKH_LITERAL = '20شرخ';

    /** عرض القماش في الاحتساب الاحتياطي — DECODE(WIDTH_TYPE,1,'عرضين',…) يُستبدل بالمفتاح الرقمي
     *  The fallback width code — replaces the legacy DECODE with the numeric key directly. */
    private const WIDTH_CODES = [
        'عرضين' => 1,
        'عرض' => 2,
        'عرض44' => 3,
        'عرض68' => 4,
    ];

    /**
     * حساب الاستهلاك لقطعة واحدة (QTY = 1 دائماً في مسار الإنشاء)
     * Calculate the consumption of one piece (QTY = 1 in the creation path).
     *
     * @param  array{typeno: int|null, cloth_type: string|null, l1: float|null, l2: float|null,
     *               l3: float|null, l5: float|null, l8: float|null, pro_group2: int|null,
     *               pro_group4: int|null, qty: float|int|null}  $in
     * @return array{hinsh: float|int|null, hinsh1: float|int|null, sharkh: string|null, yards: float|int|null}
     */
    public static function calculate(array $in): array
    {
        $typeno = $in['typeno'] === null ? null : (int) $in['typeno'];
        $clothType = $in['cloth_type'] === null ? null : (string) $in['cloth_type'];
        $l1 = $in['l1'];
        $l2 = $in['l2'];
        $l3 = $in['l3'];
        $l5 = $in['l5'];
        $l8 = $in['l8'];
        $group2 = $in['pro_group2'] === null ? null : (int) $in['pro_group2'];
        $group4 = $in['pro_group4'] === null ? null : (int) $in['pro_group4'];
        $qty = $in['qty'] ?? null;

        $hinsh = null;
        $hinsh1 = null;
        $sharkh = null;

        // ── الكتلة المستقلة 310: دائماً عند تطابق النوع والعرض ──
        // ── The independent 310 block ──
        if ($typeno === 310 && $clothType === 'عرضين' && $l1 !== null) {
            $hinsh = (self::r($l1) + 7) * 2;
        }

        // ── الكتلة المستقلة 312 مع مجموعة الكم 22 ──
        // ── The independent 312 block with sleeve group 22 ──
        if ($typeno === 312 && $clothType === 'عرضين' && $group2 === 22 && $l1 !== null && $l3 !== null) {
            if ($l5 !== null && $l5 < 22) {
                $hinsh = self::r($l1) + 4 + self::r($l3) + 75;
            } else {
                $hinsh = (self::r($l1) + 4) * 2 + self::r($l3) + 75;
            }
        }

        // ── الكتلة الرئيسية 301 / 302 ──
        // ── The main 301 / 302 block ──
        if ($typeno === 301) {
            $result = self::family301($clothType, $l1, $l2, $l3, $l5, $l8, $group2, $group4);
            $hinsh = $result['hinsh'];
            $hinsh1 = $result['hinsh1'];
            $sharkh = $result['sharkh'];
        } elseif ($typeno === 302) {
            $hinsh = self::family302($clothType, $l1, $l5);
        }

        // ── الاحتياط الأول: طول دقيق في CALC_BASE_MEASURES ──
        // ── First fallback: exact LENGTH ──
        if ($hinsh === null) {
            $hinsh = self::fallbackExact($typeno, $clothType, $l1, $l5);
        }

        // ── الاحتياط الثاني: نطاق الطول ──
        // ── Second fallback: the LENGTH range ──
        if ($hinsh === null) {
            $hinsh = self::fallbackRange($typeno, $clothType, $l1, $l5);
        }

        // ── الضرب بالكمية بعد الفرع، والياردة بلا تقريب إضافي ──
        // ── Multiply by QTY after the branch; yards with no extra rounding ──
        if ($qty !== null) {
            $hinsh = $hinsh === null ? null : $hinsh * (float) $qty;
        }

        $yards = $hinsh === null ? null : $hinsh / 36;

        return [
            'hinsh' => $hinsh,
            'hinsh1' => $hinsh1,
            'sharkh' => $sharkh,
            'yards' => $yards,
        ];
    }

    /**
     * فرع العائلة 301 حرفياً — يُعيد الثلاثية (hinsh, hinsh1, sharkh)
     * The literal 301 family — returns the (hinsh, hinsh1, sharkh) triple.
     *
     * @return array{hinsh: float|int|null, hinsh1: float|int|null, sharkh: string|null}
     */
    private static function family301(
        ?string $clothType,
        float|int|null $l1,
        float|int|null $l2,
        float|int|null $l3,
        float|int|null $l5,
        float|int|null $l8,
        ?int $group2,
        ?int $group4
    ): array {
        $null = ['hinsh' => null, 'hinsh1' => null, 'sharkh' => null];

        if ($clothType === 'عرض') {
            // عرض: نطاقا L1 فقط، بلا جداول مجموعات
            if ($l1 !== null && $l1 >= 32 && $l1 <= 37.75 && $l5 !== null && $l5 <= 18) {
                return ['hinsh' => (self::r($l1) + 7) * 2, 'hinsh1' => null, 'sharkh' => null];
            }

            if ($l1 !== null && $l1 >= 38 && $l1 <= 48 && $l5 !== null && $l5 < 18) {
                return ['hinsh' => (self::r($l1) + 7) * 2, 'hinsh1' => null, 'sharkh' => null];
            }

            return $null;
        }

        if ($clothType === 'عرضين') {
            if ($l1 !== null && $l1 >= 32.25 && $l1 <= 43.75 && $l5 !== null && $l5 <= 16) {
                if ($l3 === null) {
                    return $null;
                }

                return ['hinsh' => self::r($l1) + 7 + self::r($l3), 'hinsh1' => null, 'sharkh' => null];
            }

            if ($l1 !== null && $l1 <= 32) {
                if ($l5 !== null && $l5 < 13.75) {
                    return ['hinsh' => self::r($l1) + 7 + 5, 'hinsh1' => null, 'sharkh' => null];
                }

                if ($l5 !== null && $l5 >= 13.75 && $l5 <= 16) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return ['hinsh' => self::r($l1) + 7 + self::r($l3), 'hinsh1' => null, 'sharkh' => null];
                }

                return $null;
            }

            if ($l1 !== null && $l1 >= 44 && $l1 <= 48.75) {
                return self::doubled49Table($l1, $l3, $l5, $group2, $group4, null);
            }

            if ($l1 !== null && $l1 >= 49) {
                return self::family301Wide($l1, $l2, $l3, $l5, $l8, $group2, $group4);
            }

            return $null;
        }

        if ($clothType === 'عرض44') {
            if ($l1 !== null && $l1 >= 49 && $l1 <= 70) {
                if ($l5 !== null && $l5 < 22) {
                    return ['hinsh' => (self::r($l1) + 7) * 2, 'hinsh1' => null, 'sharkh' => null];
                }

                if ($l5 !== null && $l5 >= 22) {
                    if ($l3 === null) {
                        return $null;
                    }

                    if ($group2 === 22) {
                        // هذا الفرع يستخدم L3 الخام كما في الأصل (بلا ROUND)
                        // This branch uses the raw L3 exactly as in the source (no ROUND).
                        return ['hinsh' => (self::r($l1) + 7) * 2 + $l3, 'hinsh1' => null, 'sharkh' => null];
                    }

                    if ($group2 === 21) {
                        return ['hinsh' => (self::r($l1) + 7) * 2 + $l3 + 5, 'hinsh1' => null, 'sharkh' => null];
                    }

                    if ($group2 === 23) {
                        return ['hinsh' => (self::r($l1) + 7) * 2 + $l3 + 10, 'hinsh1' => null, 'sharkh' => null];
                    }
                }
            }

            return $null;
        }

        return $null;
    }

    /**
     * جدول المجموعات لـ عرضين عند L1 44..48.75 (يُعاد استخدامه في L1>=49 & L5<20)
     * The group table for عرضين at L1 44..48.75 (reused for L1>=49 & L5<20).
     *
     * @return array{hinsh: float|int|null, hinsh1: float|int|null, sharkh: string|null}
     */
    private static function doubled49Table(
        float|int|null $l1,
        float|int|null $l3,
        float|int|null $l5,
        ?int $group2,
        ?int $group4,
        float|int|null $l8
    ): array {
        $null = ['hinsh' => null, 'hinsh1' => null, 'sharkh' => null];

        if ($l1 === null || $l5 === null) {
            return $null;
        }

        // 41: مع الجيب (PRO_GROUP4=41) — الجداول مع مجموعة الكم
        // 41: pockets present — the tables keyed on the sleeve group
        if ($group4 === 41) {
            if ($group2 === 22) {
                if ($l8 === null) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 5, 'hinsh1' => null, 'sharkh' => null];
                }

                // فرع الشرخ: L8<=28 يولّد HINSH1 + SHARKH (فقط في مسار L1>=49)
                // The sharkh branch: L8<=28 produces HINSH1 + SHARKH (only in the L1>=49 path).
                if ($l8 <= 28) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return [
                        'hinsh' => self::r($l1) + 7 + self::r($l3) + 10,
                        'hinsh1' => self::r($l1) + 7 + self::r($l3),
                        'sharkh' => self::SHARKH_LITERAL,
                    ];
                }

                if ($l3 === null) {
                    return $null;
                }

                return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 20, 'hinsh1' => null, 'sharkh' => null];
            }

            if ($group2 === 21) {
                if ($l8 === null) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 8, 'hinsh1' => null, 'sharkh' => null];
                }

                if ($l3 === null) {
                    return $null;
                }

                return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 20, 'hinsh1' => null, 'sharkh' => null];
            }

            if ($group2 === 23) {
                if ($l8 === null) {
                    return ['hinsh' => self::r($l1) + 7 + 20, 'hinsh1' => null, 'sharkh' => null];
                }

                if ($l8 <= 28) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return [
                        'hinsh' => self::r($l1) + 7 + self::r($l3) + 10,
                        'hinsh1' => self::r($l1) + 7 + self::r($l3),
                        'sharkh' => self::SHARKH_LITERAL,
                    ];
                }

                return ['hinsh' => self::r($l1) + 7 + 40, 'hinsh1' => null, 'sharkh' => null];
            }

            return $null;
        }

        if ($group4 === 42) {
            if ($group2 === 22) {
                if ($l8 === null) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return ['hinsh' => self::r($l1) + 7 + self::r($l3), 'hinsh1' => null, 'sharkh' => null];
                }

                if ($l8 <= 28) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return [
                        'hinsh' => self::r($l1) + 7 + self::r($l3) + 10,
                        'hinsh1' => self::r($l1) + 7 + self::r($l3),
                        'sharkh' => self::SHARKH_LITERAL,
                    ];
                }

                if ($l3 === null) {
                    return $null;
                }

                return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 20, 'hinsh1' => null, 'sharkh' => null];
            }

            if ($group2 === 21) {
                if ($l8 === null) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 5, 'hinsh1' => null, 'sharkh' => null];
                }

                if ($l3 === null) {
                    return $null;
                }

                return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 5, 'hinsh1' => null, 'sharkh' => null];
            }

            if ($group2 === 23) {
                if ($l8 === null) {
                    return ['hinsh' => self::r($l1) + 7 + 20, 'hinsh1' => null, 'sharkh' => null];
                }

                if ($l8 <= 28) {
                    if ($l3 === null) {
                        return $null;
                    }

                    return [
                        'hinsh' => self::r($l1) + 7 + self::r($l3) + 10,
                        'hinsh1' => self::r($l1) + 7 + self::r($l3),
                        'sharkh' => self::SHARKH_LITERAL,
                    ];
                }

                return ['hinsh' => self::r($l1) + 7 + 40, 'hinsh1' => null, 'sharkh' => null];
            }
        }

        return $null;
    }

    /**
     * فرع 301 عند L1>=49 حرفياً — بجميع نطاقات L5 ومع فروع الشرخ
     * The literal 301 branch at L1>=49 — every L5 band with the sharkh branches.
     *
     * @return array{hinsh: float|int|null, hinsh1: float|int|null, sharkh: string|null}
     */
    private static function family301Wide(
        float|int|null $l1,
        float|int|null $l2,
        float|int|null $l3,
        float|int|null $l5,
        float|int|null $l8,
        ?int $group2,
        ?int $group4
    ): array {
        $null = ['hinsh' => null, 'hinsh1' => null, 'sharkh' => null];

        if ($l1 === null || $l5 === null) {
            return $null;
        }

        if ($l5 < 20) {
            return self::doubled49Table($l1, $l3, $l5, $group2, $group4, $l8);
        }

        if ($l5 >= 20 && $l5 <= 21.5) {
            // الفرع المركّب: L8>=31 يفوز أولاً، ثم قيود L2/المجموعات، وإلا +20
            // The composite branch: L8>=31 first, then the L2/group constraints, else +20.
            if ($l8 !== null && $l8 >= 31) {
                return ['hinsh' => (self::r($l1) + 7) * 2, 'hinsh1' => null, 'sharkh' => null];
            }

            if ($l5 <= 20.5 && $l2 !== null && $l2 <= 17 && $group4 === 42 && $group2 === 22) {
                if ($l3 === null) {
                    return $null;
                }

                return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 10, 'hinsh1' => null, 'sharkh' => null];
            }

            if ($l5 <= 20.5 && $l2 !== null && $l2 <= 17 && $group4 === 42 && $group2 === 21) {
                if ($l3 === null) {
                    return $null;
                }

                return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 15, 'hinsh1' => null, 'sharkh' => null];
            }

            if ($l3 === null) {
                return $null;
            }

            return ['hinsh' => self::r($l1) + 7 + self::r($l3) + 20, 'hinsh1' => null, 'sharkh' => null];
        }

        if ($l5 > 21.5) {
            return ['hinsh' => (self::r($l1) + 7) * 2, 'hinsh1' => null, 'sharkh' => null];
        }

        return $null;
    }

    /**
     * فرع العائلة 302 حرفياً — عرضين فقط
     * The literal 302 family — عرضين only.
     */
    private static function family302(
        ?string $clothType,
        float|int|null $l1,
        float|int|null $l5
    ): float|int|null {
        if ($clothType !== 'عرضين' || $l1 === null || $l5 === null) {
            return null;
        }

        if ($l1 >= 44 && $l1 <= 45.5) {
            return $l5 <= 19 ? 92 : null;
        }

        if ($l1 >= 45.75 && $l1 <= 47.5) {
            return $l5 <= 19 ? 95 : null;
        }

        if ($l1 >= 47.75 && $l1 <= 48.5) {
            return $l5 <= 19 ? 95 : null;
        }

        if ($l1 >= 48.75 && $l1 <= 55) {
            if ($l5 <= 19) {
                return (self::r($l1) - 5) * 2 + 20;
            }

            if ($l5 >= 19.25 && $l5 <= 22.75) {
                return (self::r($l1) + 7) * 2;
            }

            if ($l5 >= 23) {
                return (self::r($l1) + 7) * 2 + 7;
            }

            return null;
        }

        if ($l1 >= 55.25 && $l1 <= 70) {
            if ($l5 <= 19) {
                return (self::r($l1) - 7) * 2 + 20;
            }

            if ($l5 >= 19.25 && $l5 <= 22.75) {
                return (self::r($l1) + 7) * 2;
            }

            if ($l5 >= 23) {
                return (self::r($l1) + 7) * 2 + 7;
            }

            return null;
        }

        return null;
    }

    /**
     * الاحتياط الأول: طول دقيق — بندلالة TOO_MANY_ROWS الأصلية (تعدد النتائج ⇒ null)
     * First fallback: exact length — with the original TOO_MANY_ROWS semantics.
     */
    private static function fallbackExact(
        ?int $typeno,
        ?string $clothType,
        float|int|null $l1,
        float|int|null $l5
    ): float|int|null {
        if ($clothType === null || $l1 === null || $l5 === null) {
            return null;
        }

        $widthType = self::WIDTH_CODES[$clothType] ?? null;

        if ($widthType === null) {
            return null;
        }

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT DISTINCT d.EQUAL5 AS EQUAL5
                   FROM ACCOUNTS.CALC_BASE_MEASURES_DETAILES d, ACCOUNTS.CALC_BASE_MEASURES b
                  WHERE d.SEQ_BASE = b.SEQ
                    AND b.WIDTH_TYPE = :width_type
                    AND ROUND(:l5, 0) >= b.WIDTH_FRM AND ROUND(:l5, 0) <= b.WIDTH_TO
                    AND ROUND(:l1, 0) = d.LENGTH
                    AND b.TYPE_NO = NVL(:typeno, 301)',
                [
                    'width_type' => $widthType,
                    'l5' => $l5,
                    'l1' => $l1,
                    'typeno' => $typeno,
                ]
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        // النتيجة الواحدة فقط تُقبل؛ الصفر أو التعدد يعيد NULL كما في EXCEPTION الأصلية.
        // Exactly one distinct result is adopted; zero or many return NULL like the original.
        if (count($rows) !== 1) {
            return null;
        }

        $value = (array) $rows[0];
        $equal = reset($value);

        return is_numeric($equal) ? (float) $equal : null;
    }

    /**
     * الاحتياط الثاني: نطاق الطول مع EQUAL5 غير فارغ — بنفس دلالة التعدد
     * Second fallback: the length range with a non-null EQUAL5 — same multiplicity rule.
     */
    private static function fallbackRange(
        ?int $typeno,
        ?string $clothType,
        float|int|null $l1,
        float|int|null $l5
    ): float|int|null {
        if ($clothType === null || $l1 === null || $l5 === null) {
            return null;
        }

        $widthType = self::WIDTH_CODES[$clothType] ?? null;

        if ($widthType === null) {
            return null;
        }

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT DISTINCT d.EQUAL5 AS EQUAL5
                   FROM ACCOUNTS.CALC_BASE_MEASURES_DETAILES d, ACCOUNTS.CALC_BASE_MEASURES b
                  WHERE d.SEQ_BASE = b.SEQ
                    AND b.WIDTH_TYPE = :width_type
                    AND ROUND(:l5, 0) >= b.WIDTH_FRM AND ROUND(:l5, 0) <= b.WIDTH_TO
                    AND b.TYPE_NO = NVL(:typeno, 301)
                    AND ROUND(:l1, 0) >= b.LENGTH_FRM AND ROUND(:l1, 0) <= b.LENGTH_TO
                    AND d.EQUAL5 IS NOT NULL',
                [
                    'width_type' => $widthType,
                    'l5' => $l5,
                    'l1' => $l1,
                    'typeno' => $typeno,
                ]
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (count($rows) !== 1) {
            return null;
        }

        $value = (array) $rows[0];
        $equal = reset($value);

        return is_numeric($equal) ? (float) $equal : null;
    }

    /**
     * مجموعة المواصفة من PRO_DTYPE — كما يقرأها النموذج، بالصيغتين، وبتفضيل صف ب группу
     * The spec's PRO_GROUP from PRO_DTYPE — read as the form does, in both byte forms,
     * preferring a row that actually carries a group.
     */
    public static function proGroup(int $promid, ?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $oracle = ArabicEncoding::toOracleSearchChars($value);

        try {
            $rows = DB::connection('oracle')->select(
                'SELECT PRO_GROUP, PRODANE
                   FROM ACCOUNTS.PRO_DTYPE
                  WHERE PROMID = :promid
                    AND (PRODANE = :plain OR PRODANE = :oracle)
                    AND ACTIVE = 1
                  ORDER BY PRO_GROUP',
                [
                    'promid' => $promid,
                    'plain' => $value,
                    'oracle' => $oracle ?? $value,
                ]
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        foreach ($rows as $row) {
            $data = (array) $row;
            $group = null;

            foreach ($data as $k => $v) {
                if (strcasecmp($k, 'PRO_GROUP') === 0) {
                    $group = $v;
                    break;
                }
            }

            if ($group !== null && is_numeric($group)) {
                return (int) $group;
            }
        }

        return null;
    }

    /**
     * تقريب أوراكل ROUND(x,0) — نصف بعيداً عن الصفر، مثل PHP round()
     * Oracle ROUND(x,0) — half away from zero, same as PHP round().
     */
    private static function r(float|int $value): int
    {
        return (int) round((float) $value, 0, PHP_ROUND_HALF_UP);
    }
}
