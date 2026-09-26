<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /**
     * The merchant (table `merchants`) this sign-in belongs to: own phone, name, verification.
     * Staff and admin users have none.
     */
    public function merchantAccount(): HasOne
    {
        return $this->hasOne(MerchantAccount::class);
    }

    /**
     * Where a merchant stands in first-time onboarding (docs/merchant-onboarding.md):
     * verify its own phone, then create its first shop. Null once it has a shop, and for staff.
     */
    public function onboardingNextStep(): ?string
    {
        if ($this->isEmployee() || $this->accessibleShops()->isNotEmpty()) {
            return null;
        }

        return $this->merchantAccount?->isPhoneVerified() ? 'create_shop' : 'verify_phone';
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

        // Staff hold the permissions of their staff record in the current shop.
        $employee = $this->isEmployee() ? $this->actingEmployee() : null;

        if (! $employee) {
            return [];
        }

        return $employee->permissions()->with('permission')->get()
            ->pluck('permission')->filter()
            ->map->permission_key->values()->all();
    }

    /**
     * Every staff record of this person: one per shop they work (or worked) in.
     */
    public function employments(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * This person's staff record in the current shop (null for owners and outside a shop).
     */
    public function actingEmployee(): ?Employee
    {
        if (! $this->isEmployee()) {
            return null;
        }

        $shopId = $this->actingMerchant()?->id;

        return $shopId === null ? null : $this->employments
            ->first(fn (Employee $employee) => $employee->merchant_id === $shopId && $employee->status === 'active');
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

        // Staff: their first shop. Owners: their first shop (legacy one-shop relation).
        if ($this->isEmployee()) {
            return $this->accessibleShops()->first();
        }

        $merchant = $this->merchant;

        return $merchant?->exists ? $merchant : null;
    }

    /**
     * Every open shop this user can act for: the shops they own, or the shops they work in.
     *
     * @return Collection<int, Merchant>
     */
    public function accessibleShops(): Collection
    {
        if ($this->accessibleShopsCache !== null) {
            return $this->accessibleShopsCache;
        }

        if ($this->isEmployee()) {
            $shopIds = $this->employments->where('status', 'active')->pluck('merchant_id');

            return $this->accessibleShopsCache = Shop::whereIn('id', $shopIds)->orderBy('id')->get();
        }

        return $this->accessibleShopsCache = $this->ownedShops()->where('is_approved', true)->orderBy('id')->get();
    }

    /**
     * Call after opening or closing a shop within the same request.
     */
    public function forgetAccessibleShops(): void
    {
        $this->accessibleShopsCache = null;
        $this->unsetRelation('employments');
    }

    public function accessibleShop(int $shopId): ?Merchant
    {
        return $this->accessibleShops()->firstWhere('id', $shopId);
    }

    /**
     * The shops this merchant account owns (open ones; closed shops are soft-deleted).
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class, 'user_id');
    }

    public function ownedShops(): HasMany
    {
        return $this->shops();
    }

    /**
     * A merchant account: the person who owns shops, as opposed to staff or admin users.
     */
    public function isMerchantAccount(): bool
    {
        return $this->user_type === 'merchant';
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
