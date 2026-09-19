@extends('admin.layouts.app')
@push('style')

@endpush
@section('content')
    <!--**********************************
            Content body start
        ***********************************-->

    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold"></span>{{$title}}
                </h4>
                <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
            </div>


        </div>


    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">

        <!-- Form validation -->
        <div class="card">

            <!-- Registration form -->
            <form action="{{route('update-subscriptions', $subscription->id)}}" method="post"
                  name="user_registration" class="flex-fill form-validate-jquery">
                @csrf
                @method('PUT')
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card mb-0">
                            <div class="card-body">

                                @if(session('error'))
                                    <div class="alert alert-danger">{{ session('error') }}</div>
                                @endif

                                <div class="row mb-3">
                                    <div class="col-md-12">
                                        <p class="mb-1"><strong>Merchant:</strong>
                                            <a href="{{ route('view-merchant', $subscription->merchant_id) }}">{{ $summary['merchant_name'] }}</a>
                                            ({{ $summary['phone_number'] ?? 'no phone' }})</p>
                                        <p class="mb-1"><strong>Current plan:</strong>
                                            {{ $subscription->subscriptionPlan?->name ?? '—' }}
                                            <span class="badge bg-secondary">{{ $summary['state']['label'] }}</span></p>
                                        <p class="text-muted mb-0">Changing the plan here skips the payment flow. Use it for support cases or cash arranged outside the app. Any cancellation or scheduled downgrade is cleared.</p>
                                    </div>
                                </div>

                                @unless($summary['is_current'])
                                    <div class="alert alert-warning">This is not the merchant's current subscription, so it cannot be changed.</div>
                                @endunless

                                <div class="row">

                                    <div class="col-md-4">
                                        <label class="col-form-label  ">Subscription<span
                                                class="text-danger">*</span> </label>
                                        <div
                                            class="form-group form-group-feedback form-group-feedback-right">
                                            <select data-placeholder="Select Subscription" required
                                                    name="subscription_plan_id" id="subscription_plan_id"
                                                    class="form-control select2 mb-3 "
                                                    data-fouc>
                                                <option></option>
                                                @foreach($plans as $plan)
                                                    <option data-default="{{ $plan->is_default ? 1 : 0 }}"
                                                            {{ (int) old('subscription_plan_id', $subscription->subscription_plan_id) === $plan->id ? 'selected' : '' }}
                                                            value="{{ $plan->id }}">{{ $plan->name }}@if($plan->price_slsh) — {{ number_format($plan->price_slsh) }} SLSH @else — Free @endif</option>
                                                @endforeach
                                            </select>
                                            @if ($errors->has('subscription_plan_id'))
                                                <span
                                                    class="text-danger">{{ $errors->first('subscription_plan_id') }}</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="col-form-label">End date</label>
                                        <input type="date" name="end_date" id="end_date" class="form-control mb-1"
                                               min="{{ now()->addDay()->toDateString() }}"
                                               value="{{ old('end_date', $subscription->end_date) }}">
                                        <small class="text-muted">Required for a paid plan. The free plan never expires.</small>
                                        @if ($errors->has('end_date'))
                                            <span class="text-danger d-block">{{ $errors->first('end_date') }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="row mt-4">
                                    <div class="col-md-4">
                                        <button type="submit" {{ $summary['is_current'] ? '' : 'disabled' }}
                                                class="btn  btn-outline-primary float-end">
                                            <b><i class="icon-plus3"></i></b> Update
                                        </button>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <!-- /registration form -->
        </div>
        <!-- /form validation -->

    </div>
    <!-- /content area -->
    <!--**********************************
        Content body end
    ***********************************-->

@endsection

@push('script')

    <script src="{{asset('assets/global_assets/js/plugins/forms/validation/validate.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/inputs/touchspin.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/selects/select2.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/styling/switch.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/styling/switchery.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/styling/uniform.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/demo_pages/form_validation.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/demo_pages/form_select2.js')}}"></script>
 

@endpush
