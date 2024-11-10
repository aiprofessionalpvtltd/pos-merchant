<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\DataTables;
use Illuminate\Support\Facades\Validator;


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
                }) ->addColumn('phone_number', function ($subscription) {
                    return $subscription->merchant->phone_number ?? 'N/A';
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
                ->addColumn('action', function ($subscription) {
                    $viewBtn = '';
                    $deleteBtn = '';

                    if (auth()->user()->can('view-merchant')) {
                        $viewBtn = '<a title="View" href="' . route('edit-subscriptions', $subscription->id) . '"
class="badge bg-primary m-1"><i class="fas fa-fw fa-edit"></i></a>';
                    }

                    return '<div class="d-flex">' . $viewBtn . '</div>';
                })
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('admin.merchant_subscriptions.index', compact('title'));
    }

    public function edit($id)
    {
        $title = 'Update Subscriptions';
        $subscription = MerchantSubscription::with(['merchant', 'subscriptionPlan'])->findOrFail($id);
//        dd($subscription);
        return view('admin.merchant_subscriptions.edit', compact('subscription', 'title'));
    }

    public function update(Request $request, $id)
    {
        $subscription = MerchantSubscription::find($id);

         if (!$subscription) {
            return redirect()->route('admin.subscriptions.index')->with('error', 'Subscription not found.');
        }

        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'subscription_plan_id' => 'required',
            ]);

            if ($validator->fails()) {
                return redirect()->back()
                    ->withErrors($validator)
                    ->withInput();
            }

            $validatedData = $validator->validated();


            $subscription->update($validatedData);


            DB::commit();
            return redirect()->route('admin.subscriptions.index')->with('success', 'Subscription Updated Successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }


}
