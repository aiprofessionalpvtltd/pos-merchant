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
                        <th>Plan</th>
                        <th>PIN</th>
                        <th>Approval Status</th>
                        <th>Registered</th>
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
                    {data: 'name', name: 'name'},
                    {data: 'business_name', name: 'business_name'},
                    {data: 'merchant_code', name: 'merchant_code', defaultContent: '—'},
                    {data: 'phone_number', name: 'phone_number'},
                    {data: 'address', name: 'address', orderable: false, defaultContent: '—'},
                    {data: 'plan', name: 'plan', orderable: false, searchable: false, render: renderPlan},
                    {data: 'pin', name: 'pin', orderable: false, searchable: false, render: renderPin},
                    {data: 'is_approved', name: 'is_approved', render: function (data) {
                            return data == 1
                                ? '<span class="badge bg-success">Approved</span>'
                                : '<span class="badge bg-danger">Not Approved</span>';
                        }},
                    {data: 'created_at', name: 'created_at'},
                    {data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center'}
                ],
                order: [[0, 'desc']],
                responsive: true,
            });

            function escapeHtml(value) {
                return $('<div>').text(value == null ? '' : value).html();
            }

            function renderPlan(plan) {
                if (!plan) {
                    return '—';
                }

                var color = plan.status === 'active' ? 'success' : (plan.status === 'expired' ? 'danger' : 'warning');
                var until = plan.expires_at ? '<small class="text-muted d-block">until ' + escapeHtml(plan.expires_at.substring(0, 10)) + '</small>' : '';

                return escapeHtml(plan.name) + ' <span class="badge bg-' + color + '">' + escapeHtml(plan.status) + '</span>' + until;
            }

            function renderPin(pin) {
                if (!pin) {
                    return '—';
                }

                if (pin.state === 'locked') {
                    return '<span class="badge bg-danger" title="' + pin.failed_attempts + ' wrong attempts">Locked</span>';
                }

                return pin.state === 'set'
                    ? '<span class="badge bg-success">Set</span>'
                    : '<span class="badge bg-secondary">Not set</span>';
            }
        });
    </script>
@endpush
