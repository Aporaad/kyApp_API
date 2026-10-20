<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The database connection that should be used by the model.
     *
     * @var string
     */
    protected $connection = 'oracle';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'APP_USERS';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    /**
     * The primary key associated with the table.
     * Note: Oracle PDO returns column attributes in lowercase.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'USER_NAME_AR',
        'USER_NAME_EN',
        'USER_PASSWORD',
        'IS_ACTIVATED',
        'NOTE',
        'BRANCH_NO',
        'CREATED_ON',
        'CREATED_BY',
        'LAST_UPDATED_ON',
        'LAST_UPDATED_BY',
        'CASH_ACC_NO',
        'USER_TYPE',
        'ALLOW_BANK_ACC',
        'IS_ADMIN',
        // حقول المقاييس الحيوية
        'DEVICE_TOKEN',
        'DEVICE_NAME',
        'PLATFORM',
        'BIOMETRIC_ENABLED',
        'BIOMETRIC_REGISTERED_AT',
        'BIOMETRIC_LAST_USED',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'USER_PASSWORD',
        'user_password',
        'remember_token',
        'DEVICE_TOKEN',
        'device_token',
    ];

    /**
     * أنواع الأعمدة — أوراكل يعيد الأرقام كنصوص، والتحويل يوحّد المقارنات
     * Column casts — Oracle hands numbers back as strings; casting keeps comparisons honest.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_activated' => 'integer',
            'is_admin' => 'integer',
            'user_type' => 'integer',
            'branch_no' => 'integer',
            'biometric_enabled' => 'integer',
        ];
    }

    /**
     * هل المستخدم مدير نظام؟ الصفة تُخزَّن خارج جدول المنح، لذلك لا يمكن إلغاؤها
     * بخطأ في تحرير صلاحيات المستخدم — وهذا هو ضمان عدم الإقفال (D1).
     * Is this user a system administrator? The flag lives outside the grant table, so a bad
     * grant edit can never revoke it — that is the anti-lockout guarantee (D1).
     */
    public function isAdmin(): bool
    {
        return (int) $this->getAttribute('IS_ADMIN') === 1;
    }

    /**
     * Case-insensitive attribute getter for Oracle column compatibility.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        if ($value !== null) {
            return $value;
        }

        $lowerKey = strtolower($key);
        if ($lowerKey !== $key) {
            $value = parent::getAttribute($lowerKey);
            if ($value !== null) {
                return $value;
            }
        }

        $upperKey = strtoupper($key);
        if ($upperKey !== $key) {
            $value = parent::getAttribute($upperKey);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): ?string
    {
        return $this->getAttribute('user_password');
    }

    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return 'user_id';
    }
}
