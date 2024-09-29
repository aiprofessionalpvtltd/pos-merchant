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

        <!-- Orders Table -->
        <h3>Order Details</h3>
        <div class="table-responsive mb-5">
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Order Status</th>
                    <th>Total Price</th>
                    <th>Name</th>
                    <th>Mobile Number</th>
                    <th>Order Type</th>
                    <th>Created At</th>
                </tr>
                </thead>
                <tbody>
                @foreach($orders as $order)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ ucfirst($order->order_status) }}</td>
                        <td>{{ $order->total_price }}</td>
                        <td>{{ $order->name }}</td>
                        <td>{{ $order->mobile_number }}</td>
                        <td>{{ ucfirst($order->order_type) }}</td>
                        <td>{{ $order->created_at->format('Y-m-d H:i:s') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <!-- Invoices Table -->
        <h3>Invoice Details</h3>
        <div class="table-responsive mb-5">
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Invoice ID</th>
                    <th>Transaction ID</th>
                    <th>Amount</th>
                    <th>Currency</th>
                    <th>Status</th>
                    <th>Created At</th>
                </tr>
                </thead>
                <tbody>
                @foreach($invoices as $invoice)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $invoice->invoice_id }}</td>
                        <td>{{ $invoice->transaction_id }}</td>
                        <td>{{ $invoice->amount }}</td>
                        <td>{{ $invoice->currency }}</td>
                        <td>{{ ucfirst($invoice->status) }}</td>
                        <td>{{ $invoice->created_at->format('Y-m-d H:i:s') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <!-- Transactions Table -->
        <h3>Transaction Details</h3>
        <div class="table-responsive mb-5">
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Transaction Amount</th>
                    <th>Transaction Status</th>
                    <th>Transaction Message</th>
                    <th>Phone Number</th>
                     <th>Created At</th>
                </tr>
                </thead>
                <tbody>
                @foreach($transactions as $transaction)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $transaction->transaction_amount }}</td>
                        <td>{{ ucfirst($transaction->transaction_status) }}</td>
                        <td>{{ $transaction->transaction_message }}</td>
                        <td>{{ $transaction->phone_number }}</td>
                         <td>{{ $transaction->created_at->format('Y-m-d H:i:s') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <!-- /content area -->
    <!--**********************************
        Content body end
    ***********************************-->
@endsection

@push('script')
@endpush
