<?php

use App\Http\Controllers\API\MerchantConfirmationController;
use App\Http\Controllers\API\MerchantController;
use App\Http\Controllers\API\MerchantVerificationController;
use App\Http\Controllers\API\PassportAuthController;
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


});
