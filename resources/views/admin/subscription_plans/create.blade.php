@extends('admin.layouts.app')

@section('content')
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{ $title }}</span></h4>
            </div>
        </div>
    </div>

    <div class="content">
        <div class="card">
            <form action="{{ route('admin.subscription-plans.store') }}" method="post">
                @csrf
                <div class="card-body">
                    @include('admin.subscription_plans._form')

                    <div class="mt-4">
                        <a href="{{ route('admin.subscription-plans.index') }}" class="btn btn-light">Cancel</a>
                        <button type="submit" class="btn btn-outline-primary">Create plan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
