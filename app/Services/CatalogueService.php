<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\API\V1\ProductResource;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\InventoryHistory;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Barcode;
use App\Support\Idempotency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CatalogueService
{
    /**
     * @param  array<string, mixed>  $filters  validated list query
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>, sync_cursor: string}
     */
    public function paginate(Merchant $merchant, array $filters): array
    {
        $cursor = now();
        $type = $filters['type'] ?? null;
        $since = $this->databaseTime($filters['updated_since'] ?? null);

        $query = Product::query()
            ->where('merchant_id', $merchant->id)
            ->with(['category', 'inventories'])
            ->withSum('orderItems', 'quantity');

        if ($since || ($filters['include_deleted'] ?? false)) {
            $query->withTrashed();
        }

        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['q'])) {
            $term = $filters['q'];
            $query->where(fn (Builder $inner) => $inner->where('product_name', 'like', "%$term%")->orWhere('bar_code', 'like', "%$term%"));
        }

        if ($type) {
            $query->whereHas('inventories', function (Builder $inventory) use ($type, $filters) {
                $inventory->where('type', $type);

                if ($filters['in_stock_only'] ?? false) {
                    $inventory->where('quantity', '>', 0);
                }
            });
        } elseif ($filters['in_stock_only'] ?? false) {
            $query->whereHas('inventories', fn (Builder $inventory) => $inventory->where('quantity', '>', 0));
        }

        $perPage = min((int) ($filters['per_page'] ?? 50), 200);
        $page = $query->orderBy('id')->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));
        $rate = $merchant->effectiveExchangeRate();

        return [
            'items' => $page->getCollection()->map(fn (Product $product) => (new ProductResource($product, $rate))->resolve())->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => $page->lastPage(),
                'has_more' => $page->hasMorePages(),
            ],
            'sync_cursor' => ApiResponse::iso($cursor),
        ];
    }

    public function show(Merchant $merchant, int $id): array
    {
        return $this->present($merchant, $this->find($merchant, $id));
    }

    public function lookup(Merchant $merchant, string $barcode, string $type): array
    {
        $query = Product::where('merchant_id', $merchant->id)->with(['category', 'inventories']);
        Barcode::match($query, $barcode);
        $product = $query->first();

        if (! $product || ! $product->inventories->contains('type', $type)) {
            throw new ApiException('product.barcode_unknown', 'No product with that barcode', 404, ['barcode' => $barcode, 'normalised' => Barcode::normalise($barcode)]);
        }

        return (new ProductResource($product, $merchant->effectiveExchangeRate(), ProductResource::LOOKUP))->resolve();
    }

    /**
     * @param  array<string, mixed>  $data  validated product fields
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function create(User $actor, Merchant $merchant, array $data): array
    {
        return DB::transaction(function () use ($actor, $merchant, $data) {
            $existing = Product::where('merchant_id', $merchant->id)->where('client_uuid', $data['client_uuid'])->withTrashed()->first();

            if ($existing) {
                return ['data' => $this->present($merchant, $existing), 'message' => 'Product already added', 'status' => 200];
            }

            $this->assertBarcodeFree($merchant, $data['bar_code'] ?? null);
            $this->assertCategory($merchant, $data['category_id'] ?? null);

            $vatRate = $data['vat_rate'] ?? $merchant->vat_rate;
            $usd = $this->usdMajor($merchant, $data['price']);

            $product = Product::create([
                'merchant_id' => $merchant->id,
                'client_uuid' => $data['client_uuid'],
                'product_name' => $data['product_name'],
                'bar_code' => $data['bar_code'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'price' => $usd,
                'price_sls' => round($usd * $merchant->effectiveExchangeRate(), 2),
                'exchange_rate' => $merchant->effectiveExchangeRate(),
                'vat' => (int) round($vatRate * 100),
                'total_price' => round($usd * (1 + $vatRate), 2),
                'stock_limit' => $data['limits']['stock_limit'] ?? 0,
                'alarm_limit' => $data['limits']['alarm_limit'] ?? 0,
                'version' => 1,
            ]);

            if (isset($data['created_at'])) {
                $product->forceFill(['created_at' => $data['created_at']])->save();
            }

            ProductInventory::create(['product_id' => $product->id, 'type' => $data['type'], 'quantity' => $data['quantity']]);

            InventoryHistory::create([
                'product_id' => $product->id, 'quantity' => $data['quantity'], 'from_location' => $data['type'], 'to_location' => $data['type'],
                'user_id' => $actor->id, 'kind' => 'opening',
            ]);

            return ['data' => $this->present($merchant, $product->refresh()), 'message' => 'Product added', 'status' => 201];
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated subset of product fields
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function update(Merchant $merchant, int $id, array $data, ?string $ifMatch): array
    {
        return DB::transaction(function () use ($merchant, $id, $data, $ifMatch) {
            $product = Product::where('merchant_id', $merchant->id)->lockForUpdate()->find($id)
                ?? throw new ApiException('product.not_found', 'We could not find that product', 404);

            if ($ifMatch !== null && (int) $ifMatch !== $product->version) {
                throw new ApiException('resource.version_conflict', 'This product was changed on another device', 409, ['current' => $this->present($merchant, $product)]);
            }

            if (array_key_exists('bar_code', $data)) {
                $this->assertBarcodeFree($merchant, $data['bar_code'], $product->id);
            }

            if (array_key_exists('category_id', $data)) {
                $this->assertCategory($merchant, $data['category_id']);
            }

            $product->fill(array_intersect_key($data, array_flip(['product_name', 'bar_code', 'category_id'])));

            if (isset($data['limits']['stock_limit'])) {
                $product->stock_limit = $data['limits']['stock_limit'];
            }

            if (isset($data['limits']['alarm_limit'])) {
                $product->alarm_limit = $data['limits']['alarm_limit'];
            }

            $priceOrVatChanged = isset($data['price']) || isset($data['vat_rate']);
            if ($priceOrVatChanged) {
                $usd = isset($data['price']) ? $this->usdMajor($merchant, $data['price']) : (float) $product->price;
                $vatRate = $data['vat_rate'] ?? $product->vat / 100;

                $product->price = $usd;
                $product->price_sls = round($usd * $merchant->effectiveExchangeRate(), 2);
                $product->exchange_rate = $merchant->effectiveExchangeRate();
                $product->vat = (int) round($vatRate * 100);
                $product->total_price = round($usd * (1 + $vatRate), 2);
            }

            if ($product->isDirty()) {
                $product->version++;
                $product->save();
            }

            return ['data' => $this->present($merchant, $product->refresh()), 'message' => 'Product saved', 'status' => 200];
        });
    }

    public function delete(Merchant $merchant, int $id): array
    {
        $product = $this->find($merchant, $id);

        $cartIds = CartItem::where('product_id', $product->id)->pluck('cart_id')->unique()->values()->all();
        if ($cartIds) {
            throw new ApiException('product.in_active_cart', 'This product is on an open ticket', 409, ['cart_ids' => $cartIds]);
        }

        $product->forceFill(['version' => $product->version + 1])->save();
        $product->delete();

        return ['id' => $product->id, 'deleted_at' => ApiResponse::iso($product->deleted_at)];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function categories(Merchant $merchant, array $filters): array
    {
        $since = $this->databaseTime($filters['updated_since'] ?? null);

        $query = Category::where('merchant_id', $merchant->id)->orderBy('name');

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($filters['q'])) {
            $query->where('name', 'like', '%'.$filters['q'].'%');
        }

        $withCounts = (bool) ($filters['with_counts'] ?? false);
        if ($withCounts) {
            $query->withCount('products');
        }

        return $query->get()->map(function (Category $category) use ($withCounts) {
            $row = ['id' => $category->id, 'name' => $category->name];

            if ($withCounts) {
                $row['product_count'] = $category->products_count;
            }

            $row['updated_at'] = ApiResponse::iso($category->updated_at);

            if ($category->trashed()) {
                $row['deleted_at'] = ApiResponse::iso($category->deleted_at);
            }

            return $row;
        })->all();
    }

    /**
     * @return array{data: array<string, mixed>, message: string, status: int}
     */
    public function createCategory(Merchant $merchant, string $name, ?string $idempotencyKey): array
    {
        return Idempotency::run("m{$merchant->id}:category", $idempotencyKey, ['name' => $name], function () use ($merchant, $name) {
            $existing = Category::where('merchant_id', $merchant->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            if ($existing) {
                throw new ApiException('category.name_taken', 'That category already exists', 409, ['category' => ['id' => $existing->id, 'name' => $existing->name]]);
            }

            $category = Category::create(['merchant_id' => $merchant->id, 'name' => $name]);

            return ['data' => ['id' => $category->id, 'name' => $category->name, 'product_count' => 0], 'message' => 'Category created', 'status' => 201];
        });
    }

    public function find(Merchant $merchant, int $id): Product
    {
        return Product::where('merchant_id', $merchant->id)
            ->with(['category', 'inventories'])
            ->withSum('orderItems', 'quantity')
            ->find($id)
            ?? throw new ApiException('product.not_found', 'We could not find that product', 404);
    }

    /**
     * A client timestamp such as 2026-09-20T07:00:00Z, as the database stores it.
     */
    private function databaseTime(?string $timestamp): ?string
    {
        return $timestamp ? Carbon::parse($timestamp)->setTimezone(config('app.timezone'))->toDateTimeString() : null;
    }

    private function present(Merchant $merchant, Product $product): array
    {
        $product->loadMissing(['category', 'inventories']);
        $product->loadSum('orderItems', 'quantity');

        return (new ProductResource($product, $merchant->effectiveExchangeRate()))->resolve();
    }

    private function assertBarcodeFree(Merchant $merchant, ?string $barcode, ?int $exceptId = null): void
    {
        if ($barcode === null || $barcode === '') {
            return;
        }

        $query = Product::where('merchant_id', $merchant->id)->when($exceptId, fn (Builder $q) => $q->where('id', '!=', $exceptId));
        Barcode::match($query, $barcode);

        if ($existing = $query->first()) {
            throw new ApiException('product.barcode_taken', 'That barcode is already on another product', 409, ['existing_product_id' => $existing->id], 'bar_code');
        }
    }

    private function assertCategory(Merchant $merchant, ?int $categoryId): void
    {
        if ($categoryId !== null && ! Category::where('merchant_id', $merchant->id)->whereKey($categoryId)->exists()) {
            throw new ApiException('validation.failed', 'Please check the form', 422, ['category_id' => ['Choose one of your categories']], 'category_id');
        }
    }

    /**
     * The catalogue prices in USD; an SLSH price is converted at the shop's own rate.
     */
    private function usdMajor(Merchant $merchant, array $price): float
    {
        if ($price['currency'] === 'USD') {
            return $price['amount'] / 100;
        }

        return round($price['amount'] / $merchant->effectiveExchangeRate(), 2);
    }
}
