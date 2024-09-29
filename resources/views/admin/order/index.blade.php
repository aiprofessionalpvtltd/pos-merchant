@extends('admin.layouts.app')

@section('content')

    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{ $title }}</span></h4>
                <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
            </div>
        </div>
    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">

        <!-- Basic datatable -->
        <div class="card">
            <div class="card-header header-elements-inline">
                <h5 class="card-title"></h5>
            </div>

            <div class="card-body">
                <table id="orderTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Merchant</th>
                        <th>Customer</th>
                        <th>Sub Total</th>
                        <th>Vat</th>
                        <th>Exelo Amount</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Action</th> <!-- New Action Column -->

                    </tr>
                    </thead>
                    <tbody></tbody> <!-- No server-side data needed here, handled by AJAX -->
                </table>
            </div>
        </div>
        <!-- /basic datatable -->

    </div>
    <!-- /content area -->
@endsection

@push('script')
    <script src="{{asset('backend/js/datatables.js')}}"></script>
    <script>
        $(document).ready(function() {
            $('#orderTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('admin.orders.show') }}', // Add your correct route
                    type: 'GET'
                },
                columns: [
                    {data: 'id', name: 'id'},
                    {data: 'merchant', name: 'merchant'},
                    {data: 'customer', name: 'customer'},
                    {data: 'sub_total', name: 'sub_total'},
                    {data: 'vat', name: 'vat'},
                    {data: 'exelo_amount', name: 'exelo_amount'},
                    {data: 'total_price', name: 'total_price'},
                    {data: 'order_status', name: 'order_status'},
                    {data: 'action', name: 'action'}
                ]
            });
        });
    </script>
@endpush
