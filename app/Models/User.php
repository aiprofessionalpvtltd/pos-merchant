<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles,Notifiable , SoftDeletes;

    /** Per-request memo for accessibleShops(). */
    private ?Collection $accessibleShopsCache = null;

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
     * Permission keys this user holds. Shop owners hold every key; staff hold the
     * ones they were given; anyone else holds none.
     *
     * @return array<int, string>
     */
    public function posPermissionKeys(): array
    {
        if ($this->user_type === 'merchant') {
            return POSPermission::all()->map->permission_key->all();
        }

        if (! $this->isEmployee()) {
            return [];
        }

        return $this->employee->permissions()->with('permission')->get()
            ->pluck('permission')->filter()
            ->map->permission_key->values()->all();
    }

    public function hasPosPermission(string $key): bool
    {
        return in_array($key, $this->posPermissionKeys(), true);
    }

    /**
     * The shop this user acts for. A token that carries a shop (multi-shop, see
     * docs/multiple-shop.md) acts for that shop while the user still owns it or works
     * there; otherwise it falls back to the user's own shop, or their employer's.
     */
    public function actingMerchant(): ?Merchant
    {
        $token = $this->currentAccessToken();
        $shopId = $token instanceof PersonalAccessToken ? $token->merchant_id : null;

        if ($shopId) {
            return $this->accessibleShop($shopId);
        }

        $merchant = $this->isEmployee() ? $this->employee->merchant : $this->merchant;

        return $merchant?->exists ? $merchant : null;
    }

    /**
     * Every open shop this user can act for: the shops they own, or the one they work in.
     *
     * @return Collection<int, Merchant>
     */
    public function accessibleShops(): Collection
    {
        if ($this->accessibleShopsCache !== null) {
            return $this->accessibleShopsCache;
        }

        if ($this->isEmployee()) {
            $employee = $this->employee;
            $merchant = $employee->exists && $employee->status === 'active' ? $employee->merchant : null;

            return $this->accessibleShopsCache = collect($merchant?->exists ? [$merchant] : []);
        }

        return $this->accessibleShopsCache = $this->ownedShops()->where('is_approved', true)->orderBy('id')->get();
    }

    /**
     * Call after opening or closing a shop within the same request.
     */
    public function forgetAccessibleShops(): void
    {
        $this->accessibleShopsCache = null;
    }

    public function accessibleShop(int $shopId): ?Merchant
    {
        return $this->accessibleShops()->firstWhere('id', $shopId);
    }

    public function ownedShops(): HasMany
    {
        return $this->hasMany(Merchant::class);
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
