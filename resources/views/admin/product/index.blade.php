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

        <div class="card">
            <div class="card-header header-elements-inline">
                <h5 class="card-title">Every product of every shop, with stock on the shelf, in the back room and in transit</h5>
                <div class="header-elements">
                    <label for="statusFilter" class="me-2 mb-0">Stock</label>
                    <select id="statusFilter" class="form-select form-select-sm" style="width: auto;">
                        <option value="">All</option>
                        @foreach($statuses as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="card-body">
                <table id="ProductTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>Merchant</th>
                        <th>Product</th>
                        <th>Barcode</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Shelf</th>
                        <th>Back room</th>
                        <th>In transit</th>
                        <th>Stock</th>
                        <th>Added</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

    </div>
    <!-- /content area -->
@endsection

@push('script')
    <script src="{{ asset('backend/js/datatables.js') }}"></script>
    <script>
        $(document).ready(function () {
            var colors = {'In stock': 'success', 'Low stock': 'warning', 'Out of stock': 'danger', 'Deleted': 'secondary'};

            $('#ProductTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('admin.products.index') }}',
                    type: 'GET',
                    data: function (params) {
                        params.status = $('#statusFilter').val();
                    }
                },
                columns: [
                    {data: 'merchant', name: 'merchant'},
                    {data: 'product_name', name: 'product_name'},
                    {data: 'bar_code', name: 'bar_code', defaultContent: '—'},
                    {data: 'category', name: 'category', defaultContent: '—'},
                    {data: 'price_display', name: 'price_display', searchable: false},
                    {data: 'shop', name: 'shop', searchable: false},
                    {data: 'stock', name: 'stock', searchable: false},
                    {data: 'transit', name: 'transit', searchable: false},
                    {data: 'status', name: 'status', orderable: false, searchable: false, render: function (status) {
                            return '<span class="badge bg-' + (colors[status] || 'secondary') + '">' + $('<div>').text(status || '').html() + '</span>';
                        }},
                    {data: 'created_at', name: 'created_at'},
                    {data: 'action', name: 'action', orderable: false, searchable: false}
                ],
                order: [[9, 'desc']]
            });

            $('#statusFilter').on('change', function () {
                $('#ProductTable').DataTable().ajax.reload();
            });
        });
    </script>
@endpush
