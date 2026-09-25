{{-- Shared by create and edit. $plan is a new or existing SubscriptionPlan. --}}
@php($features = old('features', $plan->features ?? []))

<div class="row">
    <div class="col-md-4">
        <label class="col-form-label" for="name">Name<span class="text-danger">*</span></label>
        <input type="text" name="name" id="name" class="form-control" maxlength="100" required
               value="{{ old('name', $plan->name) }}" placeholder="Gold Package">
        @error('name') <span class="text-danger">{{ $message }}</span> @enderror
    </div>

    <div class="col-md-4">
        <label class="col-form-label" for="key">Key<span class="text-danger">*</span></label>
        @if($plan->exists)
            <input type="text" id="key" class="form-control" value="{{ $plan->key }}" disabled>
            <small class="text-muted">The app identifies the plan by its key, so it can't be changed.</small>
        @else
            <input type="text" name="key" id="key" class="form-control" maxlength="30" required
                   value="{{ old('key') }}" placeholder="gold" pattern="[a-z][a-z0-9_\-]*">
            <small class="text-muted">Lower-case, e.g. <code>gold</code>. It can't be changed later.</small>
            @error('key') <span class="text-danger d-block">{{ $message }}</span> @enderror
        @endif
    </div>

    <div class="col-md-4">
        <label class="col-form-label">Billing period</label>
        <input type="text" class="form-control" value="Monthly" disabled>
        <small class="text-muted">Each payment covers one month.</small>
    </div>
</div>

<div class="row mt-2">
    <div class="col-md-4">
        <label class="col-form-label" for="price_slsh">Price ({{ config('exelo.alt_currency') }})<span class="text-danger">*</span></label>
        <input type="number" name="price_slsh" id="price_slsh" class="form-control" min="0" step="1" required
               value="{{ old('price_slsh', $plan->price_slsh) }}">
        <small class="text-muted">What the merchant is charged each month on Zaad, eDahab or cash. 0 = free.</small>
        @error('price_slsh') <span class="text-danger d-block">{{ $message }}</span> @enderror
    </div>

    <div class="col-md-4">
        <label class="col-form-label" for="price">Price (USD)<span class="text-danger">*</span></label>
        <input type="number" name="price" id="price" class="form-control" min="0" step="0.01" required
               value="{{ old('price', $plan->price) }}">
        <small class="text-muted">Shown in the app next to the {{ config('exelo.alt_currency') }} price.</small>
        @error('price') <span class="text-danger d-block">{{ $message }}</span> @enderror
    </div>

    <div class="col-md-4">
        <label class="col-form-label d-block">Default plan</label>
        <input type="hidden" name="is_default" value="0">
        <div class="form-check mt-2">
            <input type="checkbox" name="is_default" id="is_default" value="1" class="form-check-input"
                   {{ old('is_default', $plan->is_default) ? 'checked' : '' }}
                   {{ $plan->is_default ? 'disabled' : '' }}>
            <label class="form-check-label" for="is_default">Merchants fall back to this plan and it never expires</label>
        </div>
        @if($plan->is_default)
            {{-- A disabled checkbox isn't sent; keep the default until another plan takes it over. --}}
            <input type="hidden" name="is_default" value="1">
            <small class="text-muted">To change the default, tick "Default plan" on another plan.</small>
        @else
            <small class="text-muted">Only one plan can be the default; ticking this moves it here.</small>
        @endif
        @error('is_default') <span class="text-danger d-block">{{ $message }}</span> @enderror
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <label class="col-form-label d-block">Features</label>
        <div class="row">
            @foreach(\App\Models\SubscriptionPlan::FEATURES as $key => $label)
                <div class="col-md-3">
                    <div class="form-check">
                        <input type="checkbox" name="features[]" value="{{ $key }}" id="feature_{{ $loop->index }}"
                               class="form-check-input" {{ in_array($key, $features, true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="feature_{{ $loop->index }}">
                            {{ $label }} <small class="text-muted">({{ $key }})</small>
                        </label>
                    </div>
                </div>
            @endforeach
        </div>
        @error('features') <span class="text-danger d-block">{{ $message }}</span> @enderror
        @error('features.*') <span class="text-danger d-block">{{ $message }}</span> @enderror
    </div>
</div>
