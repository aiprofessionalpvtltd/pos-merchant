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
                <h5 class="card-title">Staff of every shop, with their shifts and completed sales</h5>
                <div class="header-elements">
                    <label for="statusFilter" class="me-2 mb-0">Status</label>
                    <select id="statusFilter" class="form-select form-select-sm" style="width: auto;">
                        <option value="">All</option>
                        @foreach($statuses as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="card-body">
                <table id="EmployeeTable" class="table table-striped">
                    <thead>
                    <tr>
                        <th>Merchant</th>
                        <th>Employee</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Salary</th>
                        <th>Status</th>
                        <th>PIN</th>
                        <th>Access</th>
                        <th>Shifts</th>
                        <th>Last shift</th>
                        <th>Sales</th>
                        <th>Joined</th>
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
            var colors = {'On shift': 'success', 'Off shift': 'secondary', 'Disabled': 'warning', 'Removed': 'danger'};

            $('#EmployeeTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('admin.employees.index') }}',
                    type: 'GET',
                    data: function (params) {
                        params.status = $('#statusFilter').val();
                    }
                },
                columns: [
                    {data: 'merchant', name: 'merchant'},
                    {data: 'name', name: 'name'},
                    {data: 'phone_number', name: 'phone_number'},
                    {data: 'role', name: 'role', defaultContent: '—'},
                    {data: 'salary_display', name: 'salary_display', searchable: false, defaultContent: '—'},
                    {data: 'status_label', name: 'status_label', orderable: false, searchable: false, render: function (status) {
                            return '<span class="badge bg-' + (colors[status] || 'secondary') + '">' + $('<div>').text(status || '').html() + '</span>';
                        }},
                    {data: 'pin', name: 'pin', orderable: false, searchable: false},
                    {data: 'permission_keys', name: 'permission_keys', orderable: false, searchable: false, defaultContent: '—'},
                    {data: 'shifts', name: 'shifts', searchable: false},
                    {data: 'last_shift', name: 'last_shift', searchable: false, defaultContent: '—'},
                    {data: 'sales', name: 'sales', searchable: false},
                    {data: 'created_at', name: 'created_at'},
                    {data: 'action', name: 'action', orderable: false, searchable: false}
                ],
                order: [[11, 'desc']]
            });

            $('#statusFilter').on('change', function () {
                $('#EmployeeTable').DataTable().ajax.reload();
            });
        });
    </script>
@endpush
