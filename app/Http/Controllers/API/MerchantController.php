<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use Illuminate\Http\Request;

class MerchantController extends BaseController
{
    public function index()
    {
        $merchants = Merchant::all();
        return $this->sendResponse(MerchantResource::collection($merchants), 'Merchants retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = $this->validateRequest($request, [
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'phone_number' => 'required|string|max:15|unique:merchants,phone_number',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant = Merchant::create($request->all());
        return $this->sendResponse(new MerchantResource($merchant), 'Merchant registered successfully. Your request has been sent to the admin for approval.');
    }

    public function show(Merchant $merchant)
    {
        return $this->sendResponse(new MerchantResource($merchant), 'Merchant retrieved successfully.');
    }

    public function update(Request $request, Merchant $merchant)
    {
        $validator = $this->validateRequest($request, [
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:255',
            'country' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'state' => 'sometimes|required|string|max:255',
            'phone_number' => 'sometimes|required|string|max:15|unique:merchants,phone_number,'.$merchant->id,
            'user_id' => 'sometimes|required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant->update($request->all());
        return $this->sendResponse(new MerchantResource($merchant), 'Merchant updated successfully.');
    }

    public function destroy(Merchant $merchant)
    {
        $merchant->delete();
        return $this->sendResponse([], 'Merchant deleted successfully.');
    }
}
