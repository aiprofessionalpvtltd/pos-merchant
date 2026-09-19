@extends('admin.layouts.app')

@section('content')


    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{$title}}</span>
                </h4>
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
                    @can('create-users')
                        <div class="col-md-12 mt-5">

                            <a href="{{route('add-user')}}"
                               class="btn btn-outline-primary float-end"><b><i
                                        class="fas fa-plus"></i></b> {{$title}}
                            </a>
                        </div>
                    @endcan
                </div>
            </div>

            <div class="card-body">
                <table id="" class="table table-striped datatables-reponsive">
                <thead>
                <tr>
                    <th>User Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Business</th>
                    <th>Role</th>
                    <th>Plan</th>
                    <th>PIN</th>
                    <th>Registered</th>
                     <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{$row['name']}}</td>
                        <td>{{$row['email']}}</td>
                        <td>{{$row['phone_number'] ?? '—'}}</td>
                        <td>{{$row['business_name'] ?? '—'}}</td>
                        <td>{{$row['type']}}</td>
                        <td>
                            @if($row['plan'])
                                {{$row['plan']['name']}}
                                <span class="badge bg-{{ $row['plan']['status'] === 'active' ? 'success' : ($row['plan']['status'] === 'expired' ? 'danger' : 'warning') }}">{{ ucfirst($row['plan']['status']) }}</span>
                                @if($row['plan']['expires_at'])
                                    <small class="text-muted d-block">until {{ $row['plan']['expires_at']->format('d M Y') }}</small>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($row['pin'] === null)
                                —
                            @elseif($row['pin']['state'] === 'locked')
                                <span class="badge bg-danger"
                                      title="{{ $row['pin']['failed_attempts'] }} wrong attempts">Locked until {{ $row['pin']['locked_until']->format('d M H:i') }}</span>
                            @elseif($row['pin']['state'] === 'set')
                                <span class="badge bg-success">Set</span>
                            @else
                                <span class="badge bg-secondary">Not set</span>
                            @endif
                        </td>
                        <td data-order="{{ $row['registered_at']?->timestamp }}">{{ $row['registered_at']?->format('d M Y') }}</td>
                        <td>
                            <div class="d-flex">
                                @can('edit-users')
                                    <a title="Edit" href="{{ route('edit-user', $row['id']) }}"
                                       class="text-primary mr-1"><i
                                            class="fas fa-edit"></i></a>
                                @endcan

                                @can('delete-users')
                                    <a href="javascript:void(0)" data-url="{{route('changeStatus-user')}}"
                                       data-status='0' data-label="delete"
                                       data-id="{{$row['id']}}"
                                       class=" text-danger mr-1 change-status-record"
                                       title="Suspend Record"><i class="fas fa-trash"></i></a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <!-- /basic datatable -->

    </div>
    <!-- /content area -->
@endsection

@push('script')
    <script src="{{asset('backend/js/datatables.js')}}"></script>

@endpush
