<?php

use App\Http\Controllers\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::resource('subscription-plans', SubscriptionPlanController::class);

