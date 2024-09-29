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
                <table id="TransactionTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>Merchant Name</th>
                        <th>Amount</th>
                        <th>Mobile No</th>
                        <th>Message</th>
                        <th>Transaction ID</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody></tbody> <!-- Removed static content for AJAX -->
                </table>
            </div>
        </div>
        <!-- /basic datatable -->

    </div>
    <!-- /content area -->
@endsection

@push('script')
    <script src="{{ asset('backend/js/datatables.js') }}"></script>
    <script>
        $(document).ready(function() {
            $('#TransactionTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('admin.transactions.show') }}', // Add the correct route for AJAX
                    type: 'GET'
                },
                columns: [
                    {data: 'merchant_name', name: 'merchant_name'},
                    {data: 'amount', name: 'amount'},
                    {data: 'phone_number', name: 'phone_number'},
                    {data: 'message', name: 'message'},
                    {data: 'transaction_id', name: 'transaction_id'},
                    {data: 'status', name: 'status'}
                ]
            });
        });
    </script>
@endpush
