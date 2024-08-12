<?php

use App\Http\Controllers\API\MerchantConfirmationController;
use App\Http\Controllers\API\MerchantController;
use App\Http\Controllers\API\MerchantSubscriptionController;
use App\Http\Controllers\API\MerchantTransactionController;
use App\Http\Controllers\API\MerchantVerificationController;
use App\Http\Controllers\API\PassportAuthController;
use App\Http\Controllers\API\SaleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('login', [PassportAuthController::class, 'login']);
Route::post('merchants', [MerchantController::class, 'store']);
Route::post('merchants/request-otp', [MerchantController::class, 'requestOtp']);
Route::post('merchants/verify-otp', [MerchantController::class, 'verifyOtp']);


Route::middleware('auth:api')->group(function () {
    Route::get('merchants', [MerchantController::class, 'index']);
    Route::get('merchants/{id}', [MerchantController::class, 'show']);

    Route::post('merchants/confirm', [MerchantConfirmationController::class, 'sendConfirmation']);

    Route::get('merchants/verify/{merchant_id}', [MerchantVerificationController::class, 'verifyMerchant']);
    Route::post('merchants/approve', [MerchantVerificationController::class, 'approveMerchant']);
    Route::post('merchants/store-pin', [MerchantVerificationController::class, 'storePin']);

    Route::get('merchants/subscriptions', [MerchantSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('merchants/subscriptions', [MerchantSubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::get('merchants/subscriptions/{id}', [MerchantSubscriptionController::class, 'show'])->name('subscriptions.show');
    Route::put('merchants/subscriptions/{id}', [MerchantSubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::delete('merchants/subscriptions/{id}', [MerchantSubscriptionController::class, 'destroy'])->name('subscriptions.destroy');
    Route::post('merchants/subscriptions/{id}/cancel', [MerchantSubscriptionController::class, 'cancel'])->name('subscriptions.cancel');

    Route::post('merchants/sales/process', [SaleController::class, 'processSale'])->name('sales.process');
    Route::post('merchants/sales/confirm-payment', [SaleController::class, 'confirmPayment'])->name('sales.confirm-payment');


    Route::post('/merchant/verify-transaction', [MerchantTransactionController::class, 'verifyTransaction']);



});
