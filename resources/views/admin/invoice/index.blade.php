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
                <div class="header-elements">
                    <label for="statusFilter" class="me-2 mb-0">Status</label>
                    <select id="statusFilter" class="form-select form-select-sm" style="width: auto;">
                        <option value="">All</option>
                        <option value="Paid">Paid</option>
                        <option value="Pending">Pending</option>
                        <option value="Expired">Expired</option>
                        <option value="Failed">Failed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="card-body">
                <table id="InvoiceTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Merchant</th>
                        <th>Type</th>
                        <th>Phone No</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Method</th>
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
            $('#InvoiceTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('admin.invoices.show') }}', // Add the correct route for AJAX
                    type: 'GET',
                    data: function (params) {
                        params.status = $('#statusFilter').val();
                    }
                },
                columns: [
                    {data: 'invoice_no', name: 'invoice_no'},
                    {data: 'merchant', name: 'merchant'},
                    {data: 'type', name: 'type'},
                    {data: 'mobile_number', name: 'mobile_number'},
                    {data: 'transaction_id', name: 'transaction_id', defaultContent: '—'},
                    {data: 'amount', name: 'amount', orderable: false, searchable: false},
                    {data: 'method', name: 'method', orderable: false, searchable: false},
                    {data: 'status', name: 'status', render: function (status) {
                            var colors = {Paid: 'success', Pending: 'warning', Expired: 'secondary', Failed: 'danger', Cancelled: 'secondary'};

                            return '<span class="badge bg-' + (colors[status] || 'secondary') + '">' + $('<div>').text(status || '').html() + '</span>';
                        }},
                    {data: 'created_at', name: 'created_at'},
                    {data: 'action', name: 'action', orderable: false, searchable: false}
                ],
                order: [[0, 'desc']]
            });

            $('#statusFilter').on('change', function () {
                $('#InvoiceTable').DataTable().ajax.reload();
            });

            $('#InvoiceTable').on('click', '.confirm-cash', function () {
                var button = $(this);

                if (!confirm('Confirm you received this cash payment? The plan starts immediately.')) {
                    return;
                }

                button.prop('disabled', true);

                $.post(button.data('url'), {_token: '{{ csrf_token() }}'})
                    .done(function (response) {
                        alert(response.message);
                        $('#InvoiceTable').DataTable().ajax.reload(null, false);
                    })
                    .fail(function (xhr) {
                        alert((xhr.responseJSON && xhr.responseJSON.message) || 'Something went wrong.');
                        button.prop('disabled', false);
                    });
            });
        });
    </script>
@endpush
