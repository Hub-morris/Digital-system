@extends('layouts.app')

@section('title', 'Penalty ' . $penalty->penalty_reference . ' | ' . config('app.name'))

@section('content')
    <section class="container page-wrap py-5">
        <p class="eyebrow mb-2"><a href="{{ route('admin.penalties.index') }}">Penalty management</a> / Penalty details</p>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <h1 class="h2 mb-1">{{ $penalty->penalty_reference }}</h1>
                <p class="text-muted-custom mb-0">{{ $penalty->status }} · {{ $penalty->penalty_reason }}</p>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('admin.penalty-rules.edit') }}">Penalty rules</a>
        </div>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="content-panel p-4 mb-4">
            <dl class="row mb-0">
                <dt class="col-sm-4">Passenger</dt><dd class="col-sm-8">{{ $penalty->passenger->full_name }} · {{ $penalty->passenger->email }}</dd>
                <dt class="col-sm-4">Flight / booking</dt><dd class="col-sm-8">{{ $penalty->flight->flight_number }} · {{ $penalty->booking->booking_reference }}</dd>
                <dt class="col-sm-4">Cabin class / fare</dt><dd class="col-sm-8">{{ $penalty->booking->cabin_class }} · {{ number_format((float) $penalty->booking->fare_amount, 2) }}</dd>
                <dt class="col-sm-4">Penalty amount</dt><dd class="col-sm-8">{{ number_format((float) $penalty->penalty_amount, 2) }}</dd>
                <dt class="col-sm-4">Paid / outstanding</dt><dd class="col-sm-8">{{ number_format((float) $penalty->amount_paid, 2) }} / {{ number_format(max(0, (float) $penalty->penalty_amount - (float) $penalty->amount_paid), 2) }}</dd>
                <dt class="col-sm-4">Applied</dt><dd class="col-sm-8">{{ $penalty->created_at->format('M j, Y g:i A') }}</dd>
                @if ($penalty->adjustment_reason)
                    <dt class="col-sm-4">Last adjustment</dt><dd class="col-sm-8">{{ $penalty->adjustment_reason }}@if ($penalty->adjustedBy) · {{ $penalty->adjustedBy->full_name }}@endif</dd>
                @endif
            </dl>
        </div>

        @if ($penalty->status !== \App\Models\Penalty::STATUS_WAIVED)
            <div class="row g-3 mb-4">
                <div class="col-lg-6">
                    <form class="content-panel h-100 p-4" method="POST" action="{{ route('admin.penalties.adjust', $penalty) }}">
                        @csrf
                        @method('PATCH')
                        <h2 class="h5">Adjust penalty</h2>
                        <div class="mb-3">
                            <label class="form-label" for="penalty_amount">New total amount</label>
                            <input class="form-control" id="penalty_amount" name="penalty_amount" type="number" min="0.01" step="0.01" value="{{ old('penalty_amount', $penalty->penalty_amount) }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="adjust_reason">Reason</label>
                            <textarea class="form-control" id="adjust_reason" name="reason" rows="3" minlength="5" maxlength="1000" required>{{ old('reason') }}</textarea>
                        </div>
                        <button class="btn btn-primary" type="submit">Save adjustment</button>
                    </form>
                </div>
                <div class="col-lg-6">
                    <form class="content-panel h-100 p-4" method="POST" action="{{ route('admin.penalties.waive', $penalty) }}">
                        @csrf
                        @method('PATCH')
                        <h2 class="h5">Waive penalty</h2>
                        <p class="small text-muted-custom">Any amount already collected will be returned to the passenger's wallet.</p>
                        <div class="mb-3">
                            <label class="form-label" for="waive_reason">Reason</label>
                            <textarea class="form-control" id="waive_reason" name="reason" rows="3" minlength="5" maxlength="1000" required>{{ old('reason') }}</textarea>
                        </div>
                        <button class="btn btn-outline-danger" type="submit">Waive penalty</button>
                    </form>
                </div>
            </div>
        @endif

        <section class="content-panel overflow-hidden">
            <h2 class="h5 p-3 mb-0 border-bottom">Wallet transactions</h2>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Amount</th><th scope="col">Balance after</th><th scope="col">Description</th></tr></thead>
                    <tbody>
                        @forelse ($penalty->walletTransactions as $transaction)
                            <tr>
                                <td>{{ $transaction->created_at->format('M j, Y g:i A') }}</td>
                                <td>{{ ucfirst($transaction->transaction_type) }}</td>
                                <td>{{ number_format((float) $transaction->amount, 2) }}</td>
                                <td>{{ number_format((float) $transaction->balance_after, 2) }}</td>
                                <td>{{ $transaction->description }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted-custom py-4" colspan="5">No wallet transactions for this penalty.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </section>
@endsection
