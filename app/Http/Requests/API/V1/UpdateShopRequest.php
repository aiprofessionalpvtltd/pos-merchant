<?php

namespace App\Http\Requests\API\V1;

/**
 * PATCH /shops/{id}: the same fields and rules as PATCH /merchant, for shop {id}.
 */
class UpdateShopRequest extends UpdateMerchantProfileRequest
{
    protected function merchantId(): ?int
    {
        return (int) $this->route('id');
    }
}
