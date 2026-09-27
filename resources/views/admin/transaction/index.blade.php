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
                <h5 class="card-title">Every payment v1 and the legacy app have settled: registration, verification, subscription and sales</h5>
                <div class="header-elements">
                    <label for="statusFilter" class="me-2 mb-0">Status</label>
                    <select id="statusFilter" class="form-select form-select-sm" style="width: auto;">
                        <option value="">All</option>
                        <option value="Paid">Paid</option>
                        <option value="Approved">Approved</option>
                        <option value="Failed">Failed</option>
                        <option value="Expired">Expired</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="card-body">
                <table id="TransactionTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Shop</th>
                        <th>Merchant</th>
                        <th>Amount</th>
                        <th>Mobile No</th>
                        <th>Message</th>
                        <th>Transaction ID</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
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
            var colors = {'Paid': 'success', 'Approved': 'success', 'Failed': 'danger', 'Expired': 'secondary', 'Cancelled': 'secondary', 'Declined': 'danger'};

            $('#TransactionTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('admin.transactions.show') }}',
                    type: 'GET',
                    data: function (params) {
                        params.status = $('#statusFilter').val();
                    }
                },
                columns: [
                    {data: 'type', name: 'type'},
                    {data: 'shop', name: 'shop'},
                    {data: 'merchant_account', name: 'merchant_account', orderable: false, searchable: false, defaultContent: '—'},
                    {data: 'amount', name: 'amount', orderable: false},
                    {data: 'phone_number', name: 'phone_number'},
                    {data: 'message', name: 'message'},
                    {data: 'transaction_id', name: 'transaction_id'},
                    {data: 'status', name: 'status', render: function (status) {
                            return '<span class="badge bg-' + (colors[status] || 'secondary') + '">' + $('<div>').text(status || '').html() + '</span>';
                        }},
                    {data: 'created_at', name: 'created_at'},
                    {data: 'action', name: 'action', orderable: false, searchable: false}
                ],
                order: [[8, 'desc']]
            });

            $('#statusFilter').on('change', function () {
                $('#TransactionTable').DataTable().ajax.reload();
            });
        });
    </script>
@endpush
