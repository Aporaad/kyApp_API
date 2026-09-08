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
    protected $table = 'NEW_USERS';

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
        'USER_NO',
        'CREATED_ON',
        'CREATED_BY',
        'LAST_UPDATED_ON',
        'LAST_UPDATED_BY',
        'CASH_ACC_NO',
        'USER_TYPE',
        'ALLOW_BANK_ACC',
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
    ];

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
