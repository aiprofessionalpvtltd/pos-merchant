@extends('admin.layouts.app')

@section('content')
    @php
        $merchantName = $product->merchant?->business_name ?: trim($product->merchant?->first_name . ' ' . $product->merchant?->last_name);
        $labels = ['opening' => 'Opening stock', 'transfer' => 'Transfer', 'adjustment' => 'Correction', 'sale' => 'Sale', 'return' => 'Returned'];
    @endphp

    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{ $title }}</span> — {{ $product->product_name }}
                    <span class="badge bg-{{ $detail['status']['color'] }}">{{ $detail['status']['label'] }}</span></h4>
            </div>
            <div class="header-elements">
                <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary">Back to products</a>
            </div>
        </div>
    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">

        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Shelf</div>
                    <h4 class="mb-0">{{ $detail['quantities']['shop'] }}</h4>
                    <small class="text-muted">alarm at {{ $product->alarm_limit ?: 'off' }}</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Back room</div>
                    <h4 class="mb-0">{{ $detail['quantities']['stock'] }}</h4>
                    <small class="text-muted">restock at {{ $product->stock_limit ?: 'off' }}</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">In transit</div>
                    <h4 class="mb-0">{{ $detail['quantities']['transportation'] }}</h4>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-body">
                    <div class="text-muted">Sold</div>
                    <h4 class="mb-0">{{ $detail['sold_units'] }} <small class="text-muted">units</small></h4>
                    <small class="text-muted">{{ $detail['sold_revenue'] }} before VAT</small>
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
                                    @if($product->merchant)
                                        <a href="{{ route('view-merchant', $product->merchant->id) }}">{{ $merchantName }}</a>
                                    @else — @endif
                                </td></tr>
                            <tr><th>Barcode</th><td>{{ $product->bar_code ?: '—' }}</td></tr>
                            <tr><th>Category</th><td>{{ $product->category?->name ?: '—' }}</td></tr>
                            <tr><th>Price</th><td>{{ $detail['price'] }} <span class="text-muted">({{ $detail['price_sls'] }})</span></td></tr>
                            <tr><th>VAT</th><td>{{ $product->vat }}% &middot; {{ $detail['price_with_vat'] }} with VAT</td></tr>
                            <tr><th>On open tickets</th><td>{{ $detail['open_tickets'] }}</td></tr>
                            <tr><th>Version</th><td>{{ $product->version }}</td></tr>
                            <tr><th>Device id</th><td>{{ $product->client_uuid ?: 'Legacy app' }}</td></tr>
                            <tr><th>Added</th><td>{{ $product->created_at?->format('d M Y H:i') }}</td></tr>
                            @if($product->trashed())
                                <tr><th>Deleted</th><td>{{ $product->deleted_at->format('d M Y H:i') }}</td></tr>
                            @endif
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Stock movements (latest {{ $detail['movements']->count() }})</h5></div>
                    <div class="card-body">
                        @if($detail['movements']->isEmpty())
                            <p class="text-muted mb-0">No stock has moved yet.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead>
                                    <tr><th>When</th><th>What</th><th>Quantity</th><th>Where</th><th>By</th><th>Note</th></tr>
                                    </thead>
                                    <tbody>
                                    @foreach($detail['movements'] as $movement)
                                        <tr>
                                            <td>{{ $movement->created_at?->format('d M Y H:i') }}</td>
                                            <td>{{ $labels[$movement->kind] ?? ucfirst($movement->kind) }}@if($movement->reason) <span class="text-muted">({{ $movement->reason }})</span>@endif</td>
                                            <td>{{ $movement->quantity > 0 && $movement->kind === 'adjustment' ? '+' : '' }}{{ $movement->quantity }}</td>
                                            <td>
                                                @if($movement->kind === 'transfer')
                                                    {{ $movement->from_location }} &rarr; {{ $movement->to_location }}
                                                @else
                                                    {{ $movement->from_location }}
                                                @endif
                                            </td>
                                            <td>{{ $movement->user?->name ?: '—' }}</td>
                                            <td>{{ $movement->note ?: '' }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

    </div>
    <!-- /content area -->
@endsection
