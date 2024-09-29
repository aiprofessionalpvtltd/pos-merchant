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
                        <th>Subscription Plan</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Cancel Status</th>
                        <th>Cancellation Date</th>
                        <th>Status</th>
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
                    {data: 'subscription_plan_name', name: 'subscriptionPlan.name'},
                    {data: 'start_date', name: 'start_date'},
                    {data: 'end_date', name: 'end_date'},
                    {data: 'is_canceled', name: 'is_canceled'},
                    {data: 'canceled_at', name: 'canceled_at'},
                    {data: 'status', name: 'status'},
                 ]
            });
        });
    </script>
@endpush
