@extends('admin.layouts.app')

@push('style')
@endpush

@section('content')
    @php
        $planColor = $detail['plan']['status'] === 'active' ? 'success' : ($detail['plan']['status'] === 'expired' ? 'danger' : 'warning');
        $money = fn ($amount, $currency) => number_format((float) $amount, 2) . ' ' . $currency;
    @endphp

    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{ $title }}</span> — {{ $merchant->business_name ?? trim($merchant->first_name . ' ' . $merchant->last_name) }}</h4>
                <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
            </div>
            <div class="header-elements">
                <a href="{{ route('admin.merchant.index') }}" class="btn btn-outline-secondary">Back to merchants</a>
            </div>
        </div>
    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">

        @if($detail['pending_cash']->isNotEmpty())
            <div class="alert alert-warning">
                <strong>{{ $detail['pending_cash']->count() }} cash payment(s) waiting for confirmation.</strong>
                Confirm them from
                <a href="{{ route('admin.invoices.show') }}">Invoices</a> once the cash is received.
                @foreach($detail['pending_cash'] as $invoice)
                    <div>{{ $money($invoice->amount, $invoice->currency) }} · {{ $invoice->public_id }} · {{ $invoice->created_at->format('d M Y H:i') }}</div>
                @endforeach
            </div>
        @endif

        <!-- Summary -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Plan</div>
                    <h5 class="mb-0">{{ $detail['plan']['name'] }}
                        <span class="badge bg-{{ $planColor }}">{{ ucfirst($detail['plan']['status']) }}</span></h5>
                    @if($detail['plan']['expires_at'])
                        <small class="text-muted">until {{ $detail['plan']['expires_at']->format('d M Y') }}</small>
                    @endif
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">PIN</div>
                    <h5 class="mb-0">
                        @if($detail['pin'] === null)
                            —
                        @elseif($detail['pin']['state'] === 'locked')
                            <span class="badge bg-danger">Locked</span>
                        @elseif($detail['pin']['state'] === 'set')
                            <span class="badge bg-success">Set</span>
                        @else
                            <span class="badge bg-secondary">Not set</span>
                        @endif
                    </h5>
                    @if($detail['pin'] && $detail['pin']['state'] === 'locked')
                        <small class="text-muted">until {{ $detail['pin']['locked_until']->format('d M H:i') }}</small>
                    @elseif($detail['pin'] && $detail['pin']['failed_attempts'] > 0)
                        <small class="text-muted">{{ $detail['pin']['failed_attempts'] }} wrong attempt(s)</small>
                    @endif
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Orders</div>
                    <h5 class="mb-0">{{ $detail['stats']['orders'] }}</h5>
                    <small class="text-muted">
                        @forelse($detail['stats']['orders_by_status'] as $status => $total)
                            {{ ucfirst($status) }} {{ $total }}@if(!$loop->last) · @endif
                        @empty
                            No orders yet
                        @endforelse
                    </small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Subscription payments</div>
                    <h5 class="mb-0">{{ number_format($detail['stats']['subscription_paid_slsh']) }} SLSH</h5>
                    <small class="text-muted">{{ $detail['stats']['invoices'] }} invoice(s), {{ $detail['stats']['transactions'] }} transaction(s)</small>
                </div>
            </div>
        </div>

        <!-- Profile -->
        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Profile</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="m-3"><strong>First Name:</strong> {{ $merchant->first_name }}</h6>
                        <h6 class="m-3"><strong>Last Name:</strong> {{ $merchant->last_name }}</h6>
                        <h6 class="m-3"><strong>DOB:</strong> {{ $merchant->dob }}</h6>
                        <h6 class="m-3"><strong>Edahab Agent Code:</strong> {{ $merchant->merchant_code ?? '—' }}</h6>
                        <h6 class="m-3"><strong>Zaad Agent Code:</strong> {{ $merchant->other_merchant_code ?? '—' }}</h6>
                        <h6 class="m-3"><strong>Address:</strong> {{ $detail['address'] ?? '—' }}</h6>
                        <h6 class="m-3"><strong>Email:</strong> {{ $merchant->email ?? '—' }}</h6>
                        <h6 class="m-3"><strong>Phone NO:</strong> {{ $merchant->phone_number }}</h6>
                    </div>
                    <div class="col-md-6">
                        <h6 class="m-3"><strong>Verification Status:</strong>
                            <span class="badge bg-{{ $merchant->confirmation_status ? 'success' : 'danger' }}">{{ $merchant->confirmation_status ? 'Verified' : 'Not Verified' }}</span></h6>
                        <h6 class="m-3"><strong>Account Approval Status:</strong>
                            <span class="badge bg-{{ $merchant->is_approved ? 'success' : 'danger' }}">{{ $merchant->is_approved ? 'Approved' : 'Not Approved' }}</span></h6>
                        <h6 class="m-3"><strong>Account Created At:</strong> {{ $merchant->created_at->format('Y-m-d H:i:s') }}</h6>
                        <h6 class="m-3"><strong>Last Updated At:</strong> {{ $merchant->updated_at->format('Y-m-d H:i:s') }}</h6>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payout wallets -->
        <h3>Payout Wallets</h3>
        <div class="table-responsive mb-5">
            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>Wallet</th>
                    <th>Number</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach($detail['wallets'] as $wallet => $number)
                    @php $isSet = ! empty($number) && $number !== '0'; @endphp
                    <tr>
                        <td>{{ $wallet }}</td>
                        <td>{{ $isSet ? $number : '—' }}</td>
                        <td><span class="badge bg-{{ $isSet ? 'success' : 'secondary' }}">{{ $isSet ? 'On file' : 'Not set' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <!-- Subscription history -->
        <h3>Subscription History</h3>
        <div class="table-responsive mb-5">
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Plan</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Paid by</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse($detail['subscriptions'] as $subscription)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $subscription->subscriptionPlan?->name ?? '—' }}</td>
                        <td>{{ $subscription->start_date }}</td>
                        <td>{{ $subscription->end_date ?? 'No expiry' }}</td>
                        <td>{{ $subscription->invoice ? ucfirst($subscription->invoice->rail ?? $subscription->invoice->payment_method) : '—' }}</td>
                        <td>
                            @if($subscription->is_canceled)
                                <span class="badge bg-warning">Cancelled {{ $subscription->canceled_at?->format('d M Y') }}</span>
                            @else
                                <span class="badge bg-secondary">{{ $subscription->transaction_status }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            No subscription record for this shop. It has never subscribed, so it is on the
                            <strong>{{ $detail['plan']['name'] }}</strong> plan by default ({{ $detail['plan']['status'] }}, no expiry).
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <!-- Employees -->
        <h3>Employees ({{ $detail['employees']->count() }})</h3>
        <div class="table-responsive mb-5">
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse($detail['employees'] as $employee)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $employee->first_name }} {{ $employee->last_name }}</td>
                        <td>{{ $employee->phone_number }}</td>
                        <td>{{ $employee->role }}</td>
                        <td><span class="badge bg-{{ $employee->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($employee->status) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted">No employees</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <!-- Orders Table -->
        <h3>Order Details</h3>
        <p class="text-muted">Latest {{ $detail['recent']['limit'] }} of {{ $detail['stats']['orders'] }}</p>
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
                @foreach($detail['recent']['orders'] as $order)
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
        <p class="text-muted">Latest {{ $detail['recent']['limit'] }} of {{ $detail['stats']['invoices'] }}</p>
        <div class="table-responsive mb-5">
            <table class="table table-bordered table-hover">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Invoice ID</th>
                    <th>Transaction ID</th>
                    <th>Rail</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Created At</th>
                </tr>
                </thead>
                <tbody>
                @foreach($detail['recent']['invoices'] as $invoice)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $invoice->type }}</td>
                        <td>{{ $invoice->public_id ?? $invoice->invoice_id }}</td>
                        <td>{{ $invoice->transaction_id }}</td>
                        <td>{{ $invoice->rail ? ucfirst($invoice->rail) : ($invoice->payment_method ?? '—') }}</td>
                        <td>{{ $money($invoice->amount, $invoice->currency) }}</td>
                        <td>{{ ucfirst($invoice->status) }}</td>
                        <td>{{ $invoice->created_at->format('Y-m-d H:i:s') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <!-- Transactions Table -->
        <h3>Transaction Details</h3>
        <p class="text-muted">Latest {{ $detail['recent']['limit'] }} of {{ $detail['stats']['transactions'] }}</p>
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
                @foreach($detail['recent']['transactions'] as $transaction)
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
@endsection

@push('script')
@endpush
