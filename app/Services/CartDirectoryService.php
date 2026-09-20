<?php

namespace App\Services;

use App\Models\Cart;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the admin ticket pages show. Read only. Totals come from CartService, so the
 * portal shows exactly what the till showed.
 */
class CartDirectoryService
{
    public function __construct(private readonly CartService $carts) {}

    /**
     * Tickets with their lines loaded for the totals; empty ones only when asked for.
     */
    public function listQuery(bool $includeEmpty): Builder
    {
        return Cart::query()
            ->select('carts.*')
            ->with(['merchant', 'user.employee', 'items.product' => fn ($query) => $query->withTrashed()])
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->when(! $includeEmpty, fn (Builder $query) => $query->has('items'));
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(Cart $cart): array
    {
        return [
            'merchant' => $this->merchantName($cart),
            'cashier' => $this->cashier($cart),
            'device' => $cart->device_id ?? 'Legacy app',
            'type' => ucfirst($cart->cart_type),
            'lines' => (int) $cart->items_count,
            'units' => (int) $cart->items_sum_quantity,
            'total' => $this->carts->snapshot($cart, $cart->merchant)['totals']['total']['display'],
            'updated_at' => $cart->updated_at?->format('d M Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Cart $cart): array
    {
        $cart->loadMissing(['merchant', 'user.employee']);

        return [
            'merchant' => $this->merchantName($cart),
            'cashier' => $this->cashier($cart),
            'device' => $cart->device_id ?? 'Legacy app',
            'ticket' => $this->carts->snapshot($cart, $cart->merchant),
        ];
    }

    private function merchantName(Cart $cart): ?string
    {
        $merchant = $cart->merchant;

        return $merchant?->business_name ?: trim($merchant?->first_name.' '.$merchant?->last_name);
    }

    private function cashier(Cart $cart): ?string
    {
        $employee = $cart->user?->employee;

        return $employee?->exists ? trim($employee->first_name.' '.$employee->last_name).' (staff)' : $cart->user?->name;
    }
}
