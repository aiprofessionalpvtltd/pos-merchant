<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMerchantSubscriptionRequest;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionDirectoryService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\DataTables;


class MerchantSubscriptionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-merchant'])->only(['index', 'edit']);
        $this->middleware(['permission:edit-merchant'])->only(['edit', 'update']);
    }

    public function index(Request $request, SubscriptionDirectoryService $directory)
    {
        $title = 'All Subscriptions';

        // Check if the request is an AJAX call
        if ($request->ajax()) {
            // Fetch subscriptions with associated merchant and subscription plan
            $subscriptions = MerchantSubscription::with(['merchant', 'subscriptionPlan', 'nextPlan', 'invoice'])
                ->withTrashed() // This will include soft-deleted records
                ->select('merchant_subscriptions.*');

            $rows = [];
            $row = function (MerchantSubscription $subscription) use (&$rows, $directory) {
                return $rows[$subscription->id] ??= $directory->listRow($subscription);
            };

            return DataTables::of($subscriptions)
                ->addColumn('merchant_id', fn ($subscription) => $subscription->merchant_id)
                ->addColumn('merchant_name', fn ($subscription) => $row($subscription)['merchant_name'])
                ->addColumn('phone_number', fn ($subscription) => $row($subscription)['phone_number'] ?? 'N/A')
                ->addColumn('subscription_plan_name', function ($subscription) {
                    return $subscription->subscriptionPlan->name ?? 'N/A';
                })
                ->addColumn('paid_by', fn ($subscription) => $row($subscription)['paid_by'])
                ->addColumn('is_canceled', function ($subscription) {
                    return $subscription->is_canceled ? 'YES' : 'NO';
                })
                ->addColumn('canceled_at', function ($subscription) {
                    return $subscription->canceled_at;
                })
                ->addColumn('scheduled_plan', fn ($subscription) => $row($subscription)['next_plan'])
                ->addColumn('status', fn ($subscription) => $row($subscription)['state'])
                ->addColumn('action', function ($subscription) use ($row) {
                    $viewBtn = '';
                    $editBtn = '';

                    if (auth()->user()->can('view-merchant')) {
                        $viewBtn = '<a title="View merchant" href="' . route('view-merchant', $subscription->merchant_id) . '"
class="badge bg-info m-1"><i class="fas fa-fw fa-eye"></i></a>';
                    }

                    // Only a merchant's newest subscription can be changed
                    if (auth()->user()->can('edit-merchant') && $row($subscription)['is_current']) {
                        $editBtn = '<a title="Change plan" href="' . route('edit-subscriptions', $subscription->id) . '"
class="badge bg-primary m-1"><i class="fas fa-fw fa-edit"></i></a>';
                    }

                    return '<div class="d-flex">' . $viewBtn . $editBtn . '</div>';
                })
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('admin.merchant_subscriptions.index', compact('title'));
    }

    public function edit($id, SubscriptionDirectoryService $directory)
    {
        $title = 'Update Subscriptions';
        $subscription = MerchantSubscription::with(['merchant', 'subscriptionPlan', 'nextPlan', 'invoice'])->findOrFail($id);
        $plans = SubscriptionPlan::offered()->orderBy('price_slsh')->get();
        $summary = $directory->listRow($subscription);

        return view('admin.merchant_subscriptions.edit', compact('subscription', 'title', 'plans', 'summary'));
    }

    public function update(UpdateMerchantSubscriptionRequest $request, $id, SubscriptionService $subscriptions)
    {
        $subscription = MerchantSubscription::find($id);

        if (!$subscription) {
            return redirect()->route('admin.subscriptions.index')->with('error', 'Subscription not found.');
        }

        $plan = SubscriptionPlan::findOrFail($request->validated('subscription_plan_id'));
        $previousPlanId = $subscription->subscription_plan_id;

        try {
            $subscriptions->adminChangePlan($subscription, $plan, $request->validated('end_date'));
        } catch (ApiException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        Log::info('Subscription changed by admin', [
            'subscription_id' => $subscription->id,
            'merchant_id' => $subscription->merchant_id,
            'from_plan_id' => $previousPlanId,
            'to_plan_id' => $plan->id,
            'end_date' => $request->validated('end_date'),
            'changed_by' => auth()->id(),
        ]);

        return redirect()->route('admin.subscriptions.index')->with('success', 'Subscription Updated Successfully');
    }


}
