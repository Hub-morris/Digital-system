@extends('layouts.app')

@section('title', 'Penalty rules | ' . config('app.name'))

@section('content')
    <section class="container page-wrap py-5">
        <p class="eyebrow mb-2"><a href="{{ route('admin.penalties.index') }}">Penalty management</a> / Rules</p>
        <h1 class="h2 mb-2">Cancellation and no-show penalty rules</h1>
        <p class="text-muted-custom mb-4">Rules are evaluated for each booking's cabin class. Enabled cancellation penalties are applied immediately whenever a booking is cancelled. Disabling a rule affects future penalties; existing balances remain due until paid or waived.</p>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.penalty-rules.update') }}">
            @csrf
            @method('PUT')
            <div class="row g-3">
                @foreach ($formKeys as $key => $cabinClass)
                    @php($rule = $rules->get($cabinClass))
                    <div class="col-lg-4">
                        <section class="content-panel h-100 p-4" aria-labelledby="rule-{{ $key }}">
                            <h2 class="h5 mb-3" id="rule-{{ $key }}">{{ $cabinClass }}</h2>
                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="rules[{{ $key }}][enabled]" value="0">
                                <input class="form-check-input" id="enabled-{{ $key }}" name="rules[{{ $key }}][enabled]" type="checkbox" value="1" @checked(old("rules.{$key}.enabled", $rule->enabled))>
                                <label class="form-check-label" for="enabled-{{ $key }}">Enable penalties</label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="type-{{ $key }}">Penalty type</label>
                                <select class="form-select" id="type-{{ $key }}" name="rules[{{ $key }}][penalty_type]" required>
                                    <option value="percentage" @selected(old("rules.{$key}.penalty_type", $rule->penalty_type) === 'percentage')>Percentage of ticket fare</option>
                                    <option value="fixed" @selected(old("rules.{$key}.penalty_type", $rule->penalty_type) === 'fixed')>Fixed amount per passenger</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="value-{{ $key }}">Penalty value</label>
                                <input class="form-control" id="value-{{ $key }}" name="rules[{{ $key }}][penalty_value]" type="number" min="0.01" step="0.01" value="{{ old("rules.{$key}.penalty_value", $rule->penalty_value) }}" required>
                                @error("rules.{$key}.penalty_value") <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                        </section>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">Save penalty rules</button>
                <a class="btn btn-outline-secondary" href="{{ route('admin.penalties.index') }}">Back to penalties</a>
            </div>
        </form>
    </section>
@endsection
