<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryHistory;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductInventory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo catalogue for a shop: categories, products, opening stock and low-stock examples.
 *
 *   php artisan db:seed --class=PosCatalogueSeeder
 *   SEED_MERCHANT_ID=122 php artisan db:seed --class=PosCatalogueSeeder
 *
 * Seeds the shop in SEED_MERCHANT_ID, or the most recently created shop when unset.
 * Safe to run twice: rows are matched by category name and by a fixed client_uuid.
 */
class PosCatalogueSeeder extends Seeder
{
    /**
     * name => products as [name, barcode, price in USD cents, shelf quantity, back-room quantity, stock_limit, alarm_limit, vat percent]
     */
    private const CATALOGUE = [
        'Dry goods' => [
            ['Basmati Rice 5kg', '6001001000012', 1850, 24, 86, 10, 4, 5],
            ['White Sugar 1kg', '6001001000029', 120, 60, 200, 30, 10, 5],
            ['Wheat Flour 2kg', '6001001000036', 260, 35, 90, 20, 8, 5],
            ['Spaghetti 500g', '6001001000043', 110, 48, 120, 20, 8, 5],
            ['Red Lentils 1kg', '6001001000050', 210, 3, 40, 15, 5, 5],
        ],
        'Dairy' => [
            ['Fresh Milk 1L', '6001002000019', 150, 30, 60, 20, 10, 0],
            ['Butter 250g', '6001002000026', 320, 12, 24, 10, 4, 0],
            ['Cheddar Cheese 400g', '6001002000033', 540, 0, 10, 6, 3, 0],
        ],
        'Beverages' => [
            ['Bottled Water 12pk', '6001003000016', 410, 40, 100, 20, 8, 0],
            ['Orange Juice 1L', '6001003000023', 240, 22, 48, 12, 6, 5],
            ['Black Tea 100 bags', '6001003000030', 190, 28, 60, 15, 6, 5],
            ['Instant Coffee 200g', '6001003000047', 620, 2, 18, 8, 4, 5],
        ],
        'Produce' => [
            ['Bananas 1kg', '6001004000013', 130, 25, 0, 10, 5, 0],
            ['Tomatoes 1kg', '6001004000020', 160, 18, 0, 10, 5, 0],
            ['Onions 2kg', '6001004000037', 180, 30, 40, 15, 6, 0],
            ['Potatoes 5kg', '6001004000044', 350, 14, 30, 8, 4, 0],
        ],
        'Snacks' => [
            ['Salted Crisps 150g', '6001005000010', 140, 50, 120, 25, 10, 5],
            ['Chocolate Bar 100g', '6001005000027', 170, 44, 96, 20, 8, 5],
            ['Digestive Biscuits', '6001005000034', 130, 36, 72, 18, 8, 5],
        ],
        'Household' => [
            ['Laundry Powder 2kg', '6001006000017', 480, 16, 32, 8, 4, 5],
            ['Dish Soap 750ml', '6001006000024', 220, 20, 40, 10, 5, 5],
            ['Toilet Paper 10 rolls', '6001006000031', 390, 26, 52, 12, 6, 5],
        ],
        'Personal care' => [
            ['Bath Soap 3pk', '6001007000014', 260, 30, 60, 15, 6, 5],
            ['Toothpaste 100ml', '6001007000021', 210, 24, 48, 12, 5, 5],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('The demo catalogue is not seeded in production.');

            return;
        }

        $merchant = $this->merchant();

        if (! $merchant) {
            $this->command->error('No shop found. Register a merchant first, or set SEED_MERCHANT_ID.');

            return;
        }

        $rate = $merchant->effectiveExchangeRate();
        $categories = 0;
        $products = 0;

        foreach (self::CATALOGUE as $categoryName => $rows) {
            $category = Category::firstOrCreate(['merchant_id' => $merchant->id, 'name' => $categoryName]);
            $categories += $category->wasRecentlyCreated ? 1 : 0;

            foreach ($rows as [$name, $barcode, $cents, $shelf, $backRoom, $stockLimit, $alarmLimit, $vatPercent]) {
                $product = Product::firstOrCreate(
                    ['merchant_id' => $merchant->id, 'client_uuid' => 'seed-'.Str::slug($name)],
                    [
                        'category_id' => $category->id,
                        'product_name' => $name,
                        'bar_code' => $this->barcodeIsFree($merchant, $barcode) ? $barcode : null,
                        'price' => $cents / 100,
                        'price_sls' => round($cents * $rate / 100, 2),
                        'exchange_rate' => $rate,
                        'vat' => $vatPercent,
                        'total_price' => round($cents / 100 * (1 + $vatPercent / 100), 2),
                        'stock_limit' => $stockLimit,
                        'alarm_limit' => $alarmLimit,
                        'version' => 1,
                    ],
                );

                if (! $product->wasRecentlyCreated) {
                    continue;
                }

                $products++;

                foreach (['shop' => $shelf, 'stock' => $backRoom] as $location => $quantity) {
                    ProductInventory::create(['product_id' => $product->id, 'type' => $location, 'quantity' => $quantity]);

                    if ($quantity > 0) {
                        InventoryHistory::create([
                            'product_id' => $product->id, 'quantity' => $quantity, 'from_location' => $location, 'to_location' => $location, 'kind' => 'opening',
                        ]);
                    }
                }
            }
        }

        $this->command->info("Seeded shop #{$merchant->id} ({$merchant->business_name}): $categories new categories, $products new products.");
    }

    private function merchant(): ?Merchant
    {
        $id = env('SEED_MERCHANT_ID');

        return $id ? Merchant::find($id) : Merchant::latest('id')->first();
    }

    private function barcodeIsFree(Merchant $merchant, string $barcode): bool
    {
        return ! Product::withTrashed()->where('merchant_id', $merchant->id)->where('bar_code', $barcode)->exists();
    }
}
