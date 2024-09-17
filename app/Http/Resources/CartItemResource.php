<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'cart_type' => $this->whenLoaded('cart', function () {
                return $this->cart->cart_type;
            }),
            'merchant_id' => $this->whenLoaded('cart', function () {
                return $this->cart->merchant_id;
            }),
             'merchant' => $this->whenLoaded('cart', function () {
                return new MerchantResource($this->cart->merchant);
            }),
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
