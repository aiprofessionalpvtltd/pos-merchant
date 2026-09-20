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
                <h5 class="card-title">Every shop's product categories</h5>
            </div>

            <div class="card-body">
                <table id="CategoryTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>Merchant</th>
                        <th>Category</th>
                        <th>Products</th>
                        <th>Status</th>
                        <th>Added</th>
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
            $('#CategoryTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {url: '{{ route('admin.categories.index') }}', type: 'GET'},
                columns: [
                    {data: 'merchant', name: 'merchant'},
                    {data: 'name', name: 'name'},
                    {data: 'products_count', name: 'products_count', searchable: false},
                    {data: 'status', name: 'status', orderable: false, searchable: false, render: function (status) {
                            return '<span class="badge bg-' + (status === 'Active' ? 'success' : 'secondary') + '">' + $('<div>').text(status || '').html() + '</span>';
                        }},
                    {data: 'created_at', name: 'created_at'}
                ],
                order: [[4, 'desc']]
            });
        });
    </script>
@endpush
