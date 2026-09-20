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
                <h5 class="card-title">Sales in progress on each till. A ticket clears when the sale is paid, held or cancelled.</h5>
                <div class="header-elements">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="includeEmpty">
                        <label class="form-check-label" for="includeEmpty">Show empty tickets</label>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <table id="CartTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>Merchant</th>
                        <th>Cashier</th>
                        <th>Till</th>
                        <th>Type</th>
                        <th>Lines</th>
                        <th>Units</th>
                        <th>Total</th>
                        <th>Last change</th>
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
            $('#CartTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('admin.carts.index') }}',
                    type: 'GET',
                    data: function (params) {
                        params.include_empty = $('#includeEmpty').is(':checked') ? 1 : 0;
                    }
                },
                columns: [
                    {data: 'merchant', name: 'merchant'},
                    {data: 'cashier', name: 'cashier', defaultContent: '—'},
                    {data: 'device_id', name: 'device_id'},
                    {data: 'type', name: 'type', searchable: false},
                    {data: 'lines', name: 'lines', searchable: false},
                    {data: 'units', name: 'units', searchable: false},
                    {data: 'total', name: 'total', orderable: false, searchable: false},
                    {data: 'updated_at', name: 'updated_at'},
                    {data: 'action', name: 'action', orderable: false, searchable: false}
                ],
                order: [[7, 'desc']]
            });

            $('#includeEmpty').on('change', function () {
                $('#CartTable').DataTable().ajax.reload();
            });
        });
    </script>
@endpush
