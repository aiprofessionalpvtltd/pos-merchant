<?php

use App\Http\Controllers\API\MerchantController;
use App\Http\Controllers\API\PassportAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('login', [PassportAuthController::class, 'login']);


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:api');

Route::resource('merchants', MerchantController::class);

