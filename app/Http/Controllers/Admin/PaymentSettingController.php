<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePaymentSettingsRequest;
use App\Services\PaymentSettingsService;

/**
 * Registration and verification fees charged by GET /api/v1/registration/quote.
 */
class PaymentSettingController extends Controller
{
    public function __construct(private readonly PaymentSettingsService $settings)
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-setting'])->only('edit');
        $this->middleware(['permission:edit-setting'])->only('update');
    }

    public function edit()
    {
        return view('admin.settings.payment_fees', [
            'title' => 'Payment Fees',
            'fees' => $this->settings->all(),
            'currency' => config('exelo.alt_currency'),
        ]);
    }

    public function update(UpdatePaymentSettingsRequest $request)
    {
        try {
            $this->settings->update($request->validated('fees'), $request->user()->id);
        } catch (ApiException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.settings.payment-fees.edit')->with('success', 'Payment fees updated. New quotes use them straight away.');
    }
}
