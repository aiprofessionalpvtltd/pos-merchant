@extends('admin.layouts.app')

@section('content')

    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">Invoice #{{ $order->id }}</span></h4>
            </div>
        </div>
    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">

        <!-- Invoice Card -->
        <div class="card">
            <div class="card-header text-center">
                <h5 class="card-title">INVOICE</h5>
            </div>

            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-sm-6">
                        <h6 class="mb-3">Customer Details:</h6>
                        <div><strong>Name:</strong> {{ $order->name ?? $order->mobile_number }}</div>
                        <div><strong>Phone:</strong> {{ $order->mobile_number }}</div>
                    </div>

                    <div class="col-sm-6 text-end">
                        <h6 class="mb-3">Shop Details:</h6>
                        <div><strong>Business Name:</strong> {{ $order->merchant->business_name }}</div>
                        <div><strong>Contact:</strong> {{ $order->merchant->first_name }} {{ $order->merchant->last_name }}</div>
                        <div><strong>Merchant:</strong> {{ $order->merchantAccount?->fullName() ?? '—' }}</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-sm-6">
                        <strong>Invoice Date:</strong> {{ showDatePicker($order->created_at) }}<br>
                        <strong>Status:</strong> {{ $order->order_status }}
                    </div>
                    <div class="col-sm-6 text-end">
                        <strong>Invoice #:</strong> {{ $order->id }}
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered mt-4">
                        <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Product Name</th>
                            <th class="text-center">Quantity</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total (USD)</th>
                            <th class="text-end">Total (SLSH)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($order->items as $index => $item)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ $item->product->product_name }}</td>
                                <td class="text-center">{{ $item->quantity }}</td>
                                <td class="text-end">${{ number_format($item->price, 2) }}</td>
                                <td class="text-end">${{ number_format($item->quantity * $item->price, 2) }}</td>
                                <td class="text-end">{{ number_format(round($item->quantity * $item->price * $rate)) }} SLSH</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="row mt-4">
                    <div class="col-sm-6">
                        <div class="bg-light p-3 rounded">
                            <h6>Notes</h6>
                            <p>Please make the payment by the due date. Thank you for your business.</p>
                        </div>
                    </div>
                    <div class="col-sm-6 text-end">
                        <ul class="list-unstyled">
                            <li><strong>Subtotal:</strong> ${{ number_format($subtotal, 2) }} ({{ number_format(round($subtotal * $rate)) }} SLSH)</li>
                            <li><strong>VAT:</strong> ${{ number_format($vat, 2) }} ({{ number_format(round($vat * $rate)) }} SLSH)</li>
                            <li><strong>Exelo Amount:</strong> ${{ number_format($exeloAmount, 2) }} ({{ number_format(round($exeloAmount * $rate)) }} SLSH)</li>
                            <li><strong>Total:</strong> ${{ number_format($totalPriceWithVAT, 2) }} ({{ number_format($totalSls) }} SLSH)</li>
                        </ul>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="#" class="btn btn-primary">Print Invoice</a>
                </div>
            </div>
        </div>
        <!-- /Invoice Card -->

    </div>
    <!-- /content area -->

@endsection
