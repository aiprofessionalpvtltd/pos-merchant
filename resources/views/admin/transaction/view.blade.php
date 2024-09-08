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
                <h4><span class="font-weight-semibold"></span>{{$title}}</h4>
                <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
            </div>
        </div>
    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="m-4"><strong>First Name:</strong> {{ $merchant->first_name }}</h6>
                        <h6 class="m-4"><strong>Last Name:</strong> {{ $merchant->last_name }}</h6>
                        <h6 class="m-4"><strong>DOB:</strong> {{ $merchant->dob }}</h6>
                        <h6 class="m-4"><strong>Agent Code:</strong> {{ $merchant->merchant_code }}</h6>

                        <h6 class="m-4"><strong>Merchant ID:</strong> {{ $merchant->merchant_code }}</h6>
                        <h6 class="m-4"><strong>Address:</strong> {{ $merchant->location }}</h6>
                        <h6 class="m-4"><strong>Email:</strong> {{ $merchant->email }}</h6>
                        <h6 class="m-4"><strong>Phone NO:</strong> {{ $merchant->phone_number }}</h6>
                    </div>
                    <div class="col-md-6">

                        <h6 class="m-4"><strong>Pin Generated:</strong> {{ ($merchant->user->pin != null) ? showBoolean(1) : showBoolean(0) }}</h6>
                        <h6 class="m-4"><strong>Verification Status:</strong> {{ showVerification($merchant->confirmation_status) }}</h6>
                        <h6 class="m-4"><strong>Account Approval Status:</strong> {{ showApproval($merchant->is_approved) }}</h6>

                        <h6 class="m-4"><strong>Account Created At:</strong> {{ $merchant->created_at->format('Y-m-d H:i:s') }}</h6>
                        <h6 class="m-4"><strong>Last Updated At:</strong> {{ $merchant->updated_at->format('Y-m-d H:i:s') }}</h6>
                    </div>
                </div>
            </div>
        </div>
        <!-- Sales Details -->
        <!-- Sales Details -->
        <h3>Sales Details</h3>
        <div class="table-responsive mb-5">

        <table class="table table-bordered table-hover ">
            <thead>
            <tr>
                <th>#</th>
                <th>Amount</th>
                <th>Payment Method</th>
                <th>Is Successful</th>
                <th>Is Completed</th>
                <th>Total Amount After Conversion</th>
                <th>Amount to Merchant</th>
                <th>Conversion Fee</th>
                <th>Transaction Fee</th>
                <th>Total Fee Charged to Customer</th>
                <th>Amount Sent to Exelo</th>
                <th>Total Amount Charged to Customer</th>
                <th>Conversion Rate</th>
                 <th>Created At</th>
            </tr>
            </thead>
            <tbody>
            @foreach($merchant->sales as $key => $sale)
                <tr>
                    <td>{{ $loop->iteration }}</td> <!-- Using $loop->iteration for numbering -->

                    <td>{{ $sale->amount }}</td>
                    <td>{{ ucfirst($sale->payment_method) }}</td>
                    <td>{{ $sale->is_successful ? 'Yes' : 'No' }}</td>
                    <td>{{ $sale->is_completed ? 'Yes' : 'No' }}</td>
                    <td>{{ showCurrency($sale->currency,$sale->total_amount_after_conversion) }}</td>
                    <td>{{ showCurrency($sale->currency,$sale->amount_to_merchant) }}</td>
                    <td>{{ showCurrency($sale->currency,$sale->conversion_fee_amount) }}</td>
                    <td>{{ showCurrency($sale->currency,$sale->transaction_fee_amount) }}</td>
                    <td>{{ showCurrency($sale->currency,$sale->total_fee_charge_to_customer) }}</td>
                    <td>{{ showCurrency($sale->currency,$sale->amount_sent_to_exelo) }}</td>
                    <td>{{ showCurrency($sale->currency,$sale->total_amount_charge_to_customer) }}</td>
                    <td>{{ $sale->conversion_rate }}</td>
                     <td>{{ $sale->created_at }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        <!-- Payment Details -->
        <h3>Payment Details</h3>
        <table class="table table-bordered table-hover ">
            <thead>
            <tr>
                <th>#</th>
                 <th>Amount</th>
                <th>Payment Method</th>
                <th>Transaction ID</th>
                <th>Is Successful</th>
                <th>Amount to Merchant</th>
                <th>Amount to Exelo</th>
                <th>Created At</th>
            </tr>
            </thead>
            <tbody>
            @foreach($merchant->sales as $sale)
                @foreach($sale->payments as $key => $payment)
                    <tr>
                        <td>{{ $loop->parent->iteration }}.{{ $loop->iteration }}</td> <!-- Using $loop->iteration for numbering -->
                         <td>{{ $payment->amount }}</td>
                        <td>{{ ucfirst($payment->payment_method) }}</td>
                        <td>{{ $payment->transaction_id }}</td>
                        <td>{{ $payment->is_successful ? 'Yes' : 'No' }}</td>
                        <td>{{ showCurrency($sale->currency,$payment->amount_to_merchant) }}</td>
                        <td>{{ showCurrency($sale->currency,$payment->amount_to_exelo) }}</td>
                        <td>{{ $payment->created_at }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>

    </div>
    <!-- /content area -->
    <!--**********************************
        Content body end
    ***********************************-->

@endsection

@push('script')
@endpush
