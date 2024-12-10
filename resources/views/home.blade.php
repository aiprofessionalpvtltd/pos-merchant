@extends('admin.layouts.app')

@section('content')
    <div class="container-fluid p-0">
        <div class="row mb-2 mb-xl-3">
            <div class="col-auto d-none d-sm-block">
                <h3><strong>Update</strong> Conversion Rate</h3>
            </div>
        </div>

        <div class="card">
            <div class="card-body">

                <form action="{{ route('conversion.rate.update') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="conversion_rate" class="form-label">Conversion Rate</label>
                        <input type="number" name="conversion_rate" id="conversion_rate" class="form-control"
                               value="{{ old('conversion_rate', $conversionRate) }}" required>
                        @error('conversion_rate')
                        <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">Update</button>
                </form>
            </div>
        </div>
    </div>
@endsection
