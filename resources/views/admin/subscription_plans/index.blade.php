@extends('admin.layouts.app')

@section('content')
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{ $title }}</span></h4>
            </div>
            @can('create-subscription')
                <div class="header-elements">
                    <a href="{{ route('admin.subscription-plans.create') }}" class="btn btn-outline-primary">Add plan</a>
                </div>
            @endcan
        </div>
    </div>

    <div class="content">
        <div class="card">
            <div class="card-body">
                <table class="table table-striped" id="plansTable">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Key</th>
                        <th>Price ({{ config('exelo.alt_currency') }})</th>
                        <th>Price (USD)</th>
                        <th>Features</th>
                        <th>Default</th>
                        <th>Subscriptions</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ asset('backend/js/datatables.js') }}"></script>

    <script>
        $(document).ready(function () {
            $('#plansTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: {url: '{{ route('admin.subscription-plans.index') }}', type: 'GET'},
                columns: [
                    {data: 'name', name: 'name', render: escapeHtml},
                    {data: 'key', name: 'key', render: function (data) {
                            return data ? '<code>' + escapeHtml(data) + '</code>' : '<span class="text-muted">none (hidden from the app)</span>';
                        }},
                    {data: 'price_slsh', name: 'price_slsh', render: function (data) {
                            return data ? Number(data).toLocaleString() : 'Free';
                        }},
                    {data: 'price', name: 'price', render: function (data) {
                            return '$' + Number(data).toFixed(2);
                        }},
                    {data: 'features_count', name: 'features_count', orderable: false, searchable: false},
                    {data: 'is_default', name: 'is_default', searchable: false, render: function (data) {
                            return data ? '<span class="badge bg-success">Default</span>' : '—';
                        }},
                    {data: 'subscriptions_count', name: 'subscriptions_count', searchable: false},
                    {data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center'}
                ],
                order: [[2, 'asc']]
            });

            $(document).on('submit', '.delete-plan', function (event) {
                if (!window.confirm('Delete this plan? Merchants can no longer choose it.')) {
                    event.preventDefault();
                }
            });

            function escapeHtml(value) {
                return $('<div>').text(value == null ? '' : value).html();
            }
        });
    </script>
@endpush
