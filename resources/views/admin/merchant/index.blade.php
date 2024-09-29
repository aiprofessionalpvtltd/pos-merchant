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
                <div class="header-elements"></div>
            </div>

            <div class="card-body">
                <table id="merchantTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Merchant Name</th>
                        <th>Business Name</th>
                        <th>Agent Code</th>
                        <th>Phone No</th>
                        <th>Address</th>
                        <th>Approval Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>
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
        $(document).ready(function () {
            // Initialize Yajra DataTable
            $('#merchantTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: '{{ route('admin.merchant.index') }}',
                columns: [
                    {data: 'id', name: 'id'},
                    {data: 'first_name', name: 'first_name', render: function (data, type, full) {
                            return full.first_name + ' ' + full.last_name;
                        }},
                    {data: 'business_name', name: 'business_name'},
                    {data: 'merchant_code', name: 'merchant_code'},
                    {data: 'phone_number', name: 'phone_number'},
                    {data: 'location', name: 'location'},
                    {data: 'is_approved', name: 'is_approved', render: function (data, type, full) {
                            return data == 1 ? 'Approved' : 'Not Approved';
                        }},
                    {data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center'}
                ],
                order: [[0, 'asc']],
                responsive: true,
            });
        });
    </script>
@endpush
