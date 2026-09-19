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
                <h1 class="my-4">Merchant Subscriptions</h1>
                <table class="table table-striped" id="subscriptionsTable">
                    <thead>
                    <tr>
                         <th>Merchant Name</th>
                         <th>Phone Number</th>
                        <th>Subscription Plan</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Paid By</th>
                        <th>Scheduled Change</th>
                        <th>Cancellation</th>
                        <th>Status</th>
                        <th>Action</th>
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
    <script src="{{ asset('backend/js/datatables.js') }}"></script>

    <script>
        $(document).ready(function () {
            $('#subscriptionsTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('admin.subscriptions.index') }}', // Add the correct route for AJAX
                    type: 'GET'
                },
                columns: [
                    {data: 'merchant_name', name: 'merchant.business_name'},
                    {data: 'phone_number', name: 'merchant.phone_number'},
                    {data: 'subscription_plan_name', name: 'subscriptionPlan.name'},
                    {data: 'start_date', name: 'start_date'},
                    {data: 'end_date', name: 'end_date', defaultContent: 'No expiry', render: function (data) {
                            return data ? data : 'No expiry';
                        }},
                    {data: 'paid_by', name: 'paid_by', orderable: false, searchable: false, defaultContent: '—', render: function (data) {
                            return data ? data : '—';
                        }},
                    {data: 'scheduled_plan', name: 'scheduled_plan', orderable: false, searchable: false, render: function (data) {
                            return data ? 'Moves to ' + escapeHtml(data) : '—';
                        }},
                    {data: 'is_canceled', name: 'is_canceled', render: function (data, type, full) {
                            return data === 'YES'
                                ? 'Cancelled ' + escapeHtml((full.canceled_at || '').substring(0, 10))
                                : '—';
                        }},
                    {data: 'status', name: 'status', orderable: false, searchable: false, render: function (state) {
                            var colors = {active: 'success', grace: 'warning', cancelled: 'warning', expired: 'danger', past: 'secondary', replaced: 'secondary'};

                            return '<span class="badge bg-' + (colors[state.key] || 'secondary') + '">' + escapeHtml(state.label) + '</span>';
                        }},
                    {data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center'}
                ],
                order: [[3, 'desc']]
            });

            function escapeHtml(value) {
                return $('<div>').text(value == null ? '' : value).html();
            }
        });
    </script>
@endpush
