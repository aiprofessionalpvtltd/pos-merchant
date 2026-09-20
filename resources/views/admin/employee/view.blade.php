@extends('admin.layouts.app')

@section('content')
    @php
        $merchantName = $employee->merchant?->business_name ?: trim($employee->merchant?->first_name . ' ' . $employee->merchant?->last_name);
    @endphp

    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{ $title }}</span> — {{ $employee->first_name }} {{ $employee->last_name }}
                    <span class="badge bg-{{ $detail['status']['color'] }}">{{ $detail['status']['label'] }}</span></h4>
            </div>
            <div class="header-elements">
                <a href="{{ route('admin.employees.index') }}" class="btn btn-outline-secondary">Back to employees</a>
            </div>
        </div>
    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">

        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Shifts</div>
                    <h4 class="mb-0">{{ $detail['shift_count'] }}</h4>
                    <small class="text-muted">{{ $detail['worked'] }} worked in total</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Right now</div>
                    @if($detail['open_shift'])
                        <h5 class="mb-0"><span class="badge bg-success">On shift</span> {{ $detail['open_shift']['elapsed'] }}</h5>
                        <small class="text-muted">since {{ \Illuminate\Support\Carbon::parse($detail['open_shift']['started_at'])->format('d M Y H:i') }}</small>
                    @else
                        <h5 class="mb-0 text-muted">Not clocked in</h5>
                    @endif
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Completed sales</div>
                    <h4 class="mb-0">{{ $detail['paid_orders'] }}</h4>
                    <small class="text-muted">{{ $detail['sales'] }} of {{ $detail['orders'] }} order(s) taken</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">PIN</div>
                    <h5 class="mb-0">
                        @if($detail['locked'])
                            <span class="badge bg-danger">Locked</span>
                        @elseif($detail['pin'] === 'Set')
                            <span class="badge bg-success">Set</span>
                        @else
                            <span class="badge bg-secondary">Not set yet</span>
                        @endif
                    </h5>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Details</h5></div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tr><th>Merchant</th><td>
                                    @if($employee->merchant)
                                        <a href="{{ route('view-merchant', $employee->merchant->id) }}">{{ $merchantName }}</a>
                                    @else — @endif
                                </td></tr>
                            <tr><th>Phone</th><td>{{ $employee->phone_number }}</td></tr>
                            <tr><th>Role</th><td>{{ $employee->role ?: '—' }}</td></tr>
                            <tr><th>Date of birth</th><td>{{ $employee->dob ? \Illuminate\Support\Carbon::parse($employee->dob)->format('d M Y') : '—' }}</td></tr>
                            <tr><th>Salary</th><td>{{ $detail['salary'] ?: '—' }}</td></tr>
                            <tr><th>Joined</th><td>{{ $employee->created_at?->format('d M Y H:i') }}</td></tr>
                            @if($employee->removed_at)
                                <tr><th>Removed</th><td>{{ $employee->removed_at->format('d M Y H:i') }}
                                        @if($employee->former_phone_number)<br><small class="text-muted">was {{ $employee->former_phone_number }}</small>@endif
                                    </td></tr>
                            @endif
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Access</h5></div>
                    <div class="card-body">
                        @forelse($detail['permissions'] as $permission)
                            <span class="badge bg-primary me-1">{{ $permission->name }}</span>
                        @empty
                            <p class="text-muted mb-0">No permissions.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Shifts (latest {{ $detail['shifts']->count() }})</h5></div>
                    <div class="card-body">
                        @if($detail['shifts']->isEmpty())
                            <p class="text-muted mb-0">No shifts yet.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead>
                                    <tr><th>Started</th><th>Ended</th><th>Duration</th><th>Note</th></tr>
                                    </thead>
                                    <tbody>
                                    @foreach($detail['shifts'] as $shift)
                                        <tr>
                                            <td>{{ $shift['started_at'] ? \Illuminate\Support\Carbon::parse($shift['started_at'])->format('d M Y H:i') : '—' }}</td>
                                            <td>
                                                @if($shift['is_open'])
                                                    <span class="badge bg-success">Open</span>
                                                @else
                                                    {{ \Illuminate\Support\Carbon::parse($shift['ended_at'])->format('d M Y H:i') }}
                                                @endif
                                            </td>
                                            <td>{{ $shift['duration'] }}</td>
                                            <td>
                                                @if($shift['edited_by'])
                                                    Corrected by {{ $shift['edited_by'] }}@if($shift['edit_reason']): {{ $shift['edit_reason'] }}@endif
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Latest orders taken</h5></div>
                    <div class="card-body">
                        @if($detail['recent_orders']->isEmpty())
                            <p class="text-muted mb-0">No orders yet.</p>
                        @else
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                <tr><th>Order</th><th>Customer</th><th>Status</th><th class="text-end">Total</th><th>When</th></tr>
                                </thead>
                                <tbody>
                                @foreach($detail['recent_orders'] as $order)
                                    <tr>
                                        <td><a href="{{ route('admin.orders.view', $order->id) }}">#{{ $order->id }}</a></td>
                                        <td>{{ $order->name ?: '—' }}</td>
                                        <td>{{ ucfirst(strtolower($order->order_status)) }}</td>
                                        <td class="text-end">${{ number_format((float) $order->total_price, 2) }}</td>
                                        <td>{{ $order->created_at?->format('d M Y H:i') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        </div>

    </div>
    <!-- /content area -->
@endsection
