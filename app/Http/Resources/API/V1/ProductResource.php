<?php

namespace App\Http\Resources\API\V1;

use App\Models\File;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @property Product $resource
 */
class ProductResource extends JsonResource
{
    public const FULL = 'full';

    public const LOOKUP = 'lookup';

    public function __construct(Product $product, private readonly int $exchangeRate, private readonly string $mode = self::FULL)
    {
        parent::__construct($product);
    }

    public function toArray(Request $request): array
    {
        $product = $this->resource;

        if ($product->trashed()) {
            return ['id' => $product->id, 'version' => $product->version, 'deleted_at' => ApiResponse::iso($product->deleted_at)];
        }

        $cents = Money::toMinor((float) $product->price, 'USD');
        $quantities = $this->quantities($product);

        $data = [
            'id' => $product->id,
            'version' => $product->version,
            'product_name' => $product->product_name,
            'bar_code' => $product->bar_code,
            'price' => Money::usd($cents),
            'price_alt' => Money::of((int) round($cents * $this->exchangeRate / 100), config('exelo.alt_currency')),
            'vat_rate' => round($product->vat / 100, 4),
            'category' => $product->category ? ['id' => $product->category->id, 'name' => $product->category->name] : null,
        ];

        $file = $this->imageFile($product);

        if ($this->mode === self::LOOKUP) {
            return $data + [
                'quantities' => ['in_shop' => $quantities['in_shop'], 'in_stock' => $quantities['in_stock']],
                'image' => ['thumb_url' => $file ? ($file->thumbUrl() ?? $file->url()) : $this->legacyImageUrl($product)],
            ];
        }

        return $data + [
            'client_uuid' => $product->client_uuid,
            'quantities' => $quantities,
            'limits' => ['stock_limit' => $product->stock_limit, 'alarm_limit' => $product->alarm_limit],
            'image' => $this->imageBlock($product, $file),
            'total_sold' => (int) ($product->order_items_sum_quantity ?? 0),
            'created_at' => ApiResponse::iso($product->created_at),
            'updated_at' => ApiResponse::iso($product->updated_at),
            'deleted_at' => null,
        ];
    }

    /**
     * @return array{in_shop: int, in_stock: int, in_transportation: int}
     */
    private function quantities(Product $product): array
    {
        $byType = $product->inventories->pluck('quantity', 'type');

        return [
            'in_shop' => (int) ($byType['shop'] ?? 0),
            'in_stock' => (int) ($byType['stock'] ?? 0),
            'in_transportation' => (int) ($byType['transportation'] ?? 0),
        ];
    }

    private function imageFile(Product $product): ?File
    {
        return $product->image_file_id ? File::where('public_id', $product->image_file_id)->first() : null;
    }

    /**
     * @return array{id: ?string, url: ?string, thumb_url: ?string}|null
     */
    private function imageBlock(Product $product, ?File $file): ?array
    {
        if ($file) {
            return ['id' => $file->public_id, 'url' => $file->url(), 'thumb_url' => $file->thumbUrl()];
        }

        if ($product->image) {
            return ['id' => null, 'url' => $this->legacyImageUrl($product), 'thumb_url' => $this->legacyImageUrl($product)];
        }

        return null;
    }

    private function legacyImageUrl(Product $product): ?string
    {
        return $product->image ? Storage::disk('public')->url($product->image) : null;
    }
}
