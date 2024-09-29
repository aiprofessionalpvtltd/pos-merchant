<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantSubscription;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;

class MerchantSubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $title = 'All Subscriptions';

        // Check if the request is an AJAX call
        if ($request->ajax()) {
            // Fetch subscriptions with associated merchant and subscription plan
            $subscriptions = MerchantSubscription::with(['merchant', 'subscriptionPlan'])
                ->withTrashed() // This will include soft-deleted records
                ->select('merchant_subscriptions.*');


            return DataTables::of($subscriptions)
                ->addColumn('merchant_name', function ($subscription) {
                    return $subscription->merchant->business_name ?? 'N/A';
                })
                ->addColumn('subscription_plan_name', function ($subscription) {
                    return $subscription->subscriptionPlan->name ?? 'N/A';
                })
                ->addColumn('is_canceled', function ($subscription) {
                    return $subscription->is_canceled ? 'YES' : 'NO';
                })
                ->addColumn('canceled_at', function ($subscription) {
                    return $subscription->canceled_at;
                })
                ->addColumn('status', function ($subscription) {
                    return $subscription->deleted_at ? 'Inactive' : 'Active';
                })

                ->rawColumns(['action'])
                ->make(true);
        }

        return view('admin.merchant_subscriptions.index', compact('title'));
    }

//    public function show($id)
//    {
//        $subscription = MerchantSubscription::with(['merchant', 'subscriptionPlan'])->findOrFail($id);
//        return view('admin.merchant_subscriptions.index', compact('subscription'));
//    }
}
