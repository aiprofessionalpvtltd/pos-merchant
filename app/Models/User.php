<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles,Notifiable , SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'pin',
        'user_type',
        'pin_failed_attempts',
        'locked_until',
        'pin_set_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',

    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'pin_set_at' => 'datetime',
            //            'password' => 'hashed',
        ];
    }

    public function hasPin(): bool
    {
        return $this->pin_set_at !== null || $this->pin !== null;
    }

    public function isEmployee(): bool
    {
        return $this->user_type === 'employee';
    }

    /**
     * The merchant this user acts for: their own, or their employer's.
     */
    public function actingMerchant(): ?Merchant
    {
        $merchant = $this->isEmployee() ? $this->employee->merchant : $this->merchant;

        return $merchant?->exists ? $merchant : null;
    }

    public function merchant()
    {
        return $this->hasOne(Merchant::class)->withDefault();
    }

    public function employee()
    {
        return $this->hasOne(Employee::class)->withDefault();
    }

    public function order()
    {
        return $this->hasOne(Order::class)->withDefault();
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class, 'user_id');
    }
}
