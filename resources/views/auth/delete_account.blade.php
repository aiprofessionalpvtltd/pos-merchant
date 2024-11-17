@extends('layouts.app')

@section('content')
    <div class="row vh-100">
        <div class="col-sm-10 col-md-8 col-lg-6 mx-auto d-table h-100">
            <div class="d-table-cell align-middle">

                <div class="card">
                    <div class="card-body">
                        <div class="m-sm-4">

                            @include('admin.message')
                            <form method="POST" action="{{ route('delete-account') }}">
                                @method('delete')
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input class="form-control form-control-lg @error('phone_number') is-invalid @enderror"
                                           type="text" name="phone_number" placeholder="Enter your mobile"/>

                                    @error('phone_number')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                    @enderror
                                </div>


                                <div class="text-center mt-3">
                                    <button type="submit" class="btn btn-lg btn-primary">Delete Account</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
