<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubscriptionPlanUpsertRequest;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;

class SubscriptionPlanController extends Controller
{
    public function __construct(private readonly SubscriptionPlanService $plans)
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-subscription'])->only('index');
        $this->middleware(['permission:create-subscription'])->only(['create', 'store']);
        $this->middleware(['permission:edit-subscription'])->only(['edit', 'update']);
        $this->middleware(['permission:delete-subscription'])->only('destroy');
    }

    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::of(SubscriptionPlan::query()->withCount(['merchantSubscriptions as subscriptions_count']))
                ->addColumn('features_count', fn (SubscriptionPlan $plan) => count($plan->features ?? []))
                ->addColumn('action', function (SubscriptionPlan $plan) use ($request) {
                    $buttons = '';

                    if ($request->user()->can('edit-subscription')) {
                        $buttons .= '<a title="Edit plan" href="'.route('admin.subscription-plans.edit', $plan).'" class="badge bg-primary m-1"><i class="fas fa-fw fa-edit"></i></a>';
                    }

                    if ($request->user()->can('delete-subscription') && ! $plan->is_default) {
                        $buttons .= '<form method="post" action="'.route('admin.subscription-plans.destroy', $plan).'" class="d-inline delete-plan">'
                            .csrf_field().method_field('DELETE')
                            .'<button type="submit" title="Delete plan" class="badge bg-danger m-1 border-0"><i class="fas fa-fw fa-trash"></i></button></form>';
                    }

                    return '<div class="d-flex">'.$buttons.'</div>';
                })
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('admin.subscription_plans.index', ['title' => 'Subscription Plans']);
    }

    public function create()
    {
        return view('admin.subscription_plans.create', ['title' => 'Add Subscription Plan', 'plan' => new SubscriptionPlan]);
    }

    public function store(SubscriptionPlanUpsertRequest $request)
    {
        $plan = $this->plans->create($request->validated(), $request->user()->id);

        return redirect()->route('admin.subscription-plans.index')->with('success', "{$plan->name} created.");
    }

    public function edit(SubscriptionPlan $subscriptionPlan)
    {
        return view('admin.subscription_plans.edit', [
            'title' => 'Edit Subscription Plan',
            'plan' => $subscriptionPlan,
            'usage' => $this->plans->usage($subscriptionPlan),
        ]);
    }

    public function update(SubscriptionPlanUpsertRequest $request, SubscriptionPlan $subscriptionPlan)
    {
        try {
            $this->plans->update($subscriptionPlan, $request->validated(), $request->user()->id);
        } catch (ApiException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.subscription-plans.index')->with('success', "{$subscriptionPlan->name} updated.");
    }

    public function destroy(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        try {
            $this->plans->delete($subscriptionPlan, $request->user()->id);
        } catch (ApiException $e) {
            return redirect()->route('admin.subscription-plans.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.subscription-plans.index')->with('success', "{$subscriptionPlan->name} deleted.");
    }
}
