<?php

use App\Http\Controllers\API\MerchantConfirmationController;
use App\Http\Controllers\API\MerchantController;
use App\Http\Controllers\API\PassportAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('login', [PassportAuthController::class, 'login']);


//Route::get('/user', function (Request $request) {
//    return $request->user();
//})->middleware('auth:api');

//Route::resource('merchants', MerchantController::class);

Route::post('merchants', [MerchantController::class, 'store']);

Route::middleware('auth:api')->group(function () {
    Route::get('merchants', [MerchantController::class, 'index']);
    Route::get('merchants/{id}', [MerchantController::class, 'show']);

    Route::post('merchants/confirm', [MerchantConfirmationController::class, 'sendConfirmation']);

});
