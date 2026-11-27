<?php

namespace App\Support\Production;

use RuntimeException;

/**
 * جدول الترقيم مقفول بعملية أخرى (شاشة قديمة أو مستخدم ثانٍ)
 * The numbering table is locked by another writer (legacy screen or second user).
 *
 * يُترجم إلى 409 «أعد المحاولة»؛ لا يجوز الاستمرار برقم محسوب قبل القفل.
 * The controller maps it to 409 "retry"; numbering must never continue before the lock.
 */
final class ProductionLockException extends RuntimeException {}
