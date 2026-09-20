@extends('admin.layouts.app')

@section('content')
    @php($ticket = $detail['ticket'])

    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{ $title }}</span> #{{ $cart->id }} — {{ $detail['merchant'] }}</h4>
            </div>
            <div class="header-elements">
                <a href="{{ route('admin.carts.index') }}" class="btn btn-outline-secondary">Back to open tickets</a>
            </div>
        </div>
    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">

        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Cashier</div>
                    <h5 class="mb-0">{{ $detail['cashier'] ?: '—' }}</h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Till</div>
                    <h5 class="mb-0">{{ $detail['device'] }}</h5>
                    <small class="text-muted">{{ ucfirst($ticket['type']) }} ticket &middot; version {{ $ticket['version'] }}</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Lines</div>
                    <h5 class="mb-0">{{ $ticket['item_count'] }} <small class="text-muted">({{ $ticket['unit_count'] }} units)</small></h5>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Total</div>
                    <h5 class="mb-0">{{ $ticket['totals']['total']['display'] }}</h5>
                    <small class="text-muted">{{ $ticket['totals']['total_alt']['display'] }}</small>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title mb-0">Lines</h5></div>
            <div class="card-body">
                @if($ticket['is_empty'])
                    <p class="text-muted mb-0">This ticket is empty.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Barcode</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-center">In stock</th>
                                <th class="text-end">Unit price</th>
                                <th class="text-end">Line total</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($ticket['items'] as $index => $line)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td><a href="{{ route('admin.products.view', $line['product_id']) }}">{{ $line['product_name'] }}</a></td>
                                    <td>{{ $line['bar_code'] ?: '—' }}</td>
                                    <td class="text-center">{{ $line['quantity'] }}</td>
                                    <td class="text-center">{{ $line['available_quantity'] }}</td>
                                    <td class="text-end">{{ $line['unit_price']['display'] }}</td>
                                    <td class="text-end">{{ $line['line_total']['display'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="row">
                        <div class="col-md-4 offset-md-8">
                            <table class="table table-sm">
                                <tr><th>Subtotal</th><td class="text-end">{{ $ticket['totals']['subtotal']['display'] }}</td></tr>
                                <tr><th>VAT</th><td class="text-end">{{ $ticket['totals']['vat']['display'] }}</td></tr>
                                <tr><th>Total</th><td class="text-end"><strong>{{ $ticket['totals']['total']['display'] }}</strong></td></tr>
                                <tr><th></th><td class="text-end text-muted">{{ $ticket['totals']['total_alt']['display'] }} at {{ number_format($ticket['totals']['exchange_rate']) }}</td></tr>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>

    </div>
    <!-- /content area -->
@endsection
