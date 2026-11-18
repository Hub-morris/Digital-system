@extends('layouts.app')

@section('title', 'Payment management | ' . config('app.name'))

@section('content')
    <section class="container page-wrap py-5">
        <div class="mb-4">
            <p class="eyebrow mb-2">Administration · Management</p>
            <h1 class="h2 mb-0">Payment management</h1>
        </div>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form class="content-panel p-4 mb-4" method="POST" action="{{ route('admin.payments.wallet-credits.store') }}">
            @csrf
            <h2 class="h5">Record a wallet credit</h2>
            <p class="small text-muted-custom">Record a verified external payment or authorized account credit. This page does not process card or bank payments.</p>
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="user_email">Passenger email</label>
                    <input class="form-control" id="user_email" name="user_email" type="email" value="{{ old('user_email') }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="amount">Amount</label>
                    <input class="form-control" id="amount" name="amount" type="number" min="0.01" step="0.01" value="{{ old('amount') }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="external_reference">External payment reference</label>
                    <input class="form-control" id="external_reference" name="external_reference" maxlength="80" value="{{ old('external_reference') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="reason">Reason</label>
                    <input class="form-control" id="reason" name="reason" minlength="5" maxlength="255" value="{{ old('reason') }}" required>
                </div>
                <div class="col-md-12 d-grid d-md-flex justify-content-md-end"><button class="btn btn-primary" type="submit">Record credit</button></div>
            </div>
        </form>

        <form class="content-panel p-3 mb-3" method="GET" action="{{ route('admin.payments.index') }}">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label" for="filter_email">Filter by passenger email</label>
                    <input class="form-control" id="filter_email" name="user_email" type="email" value="{{ request('user_email') }}">
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
                <div class="col-md-2"><a class="btn btn-outline-secondary w-100" href="{{ route('admin.payments.index') }}">Clear</a></div>
            </div>
        </form>

        <div class="content-panel table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th scope="col">Date</th><th scope="col">Passenger</th><th scope="col">Type</th><th scope="col">Amount</th><th scope="col">Balance after</th><th scope="col">Reference / reason</th><th scope="col">Recorded by</th></tr></thead>
                <tbody>
                    @forelse ($transactions as $transaction)
                        <tr>
                            <td>{{ $transaction->created_at->format('M j, Y g:i A') }}</td>
                            <td>{{ $transaction->passenger->full_name }}<span class="d-block small text-muted-custom">{{ $transaction->passenger->email }}</span></td>
                            <td>{{ ucfirst($transaction->transaction_type) }}</td>
                            <td>{{ number_format((float) $transaction->amount, 2) }}</td>
                            <td>{{ number_format((float) $transaction->balance_after, 2) }}</td>
                            <td>
                                @if ($transaction->penalty)
                                    <a href="{{ route('admin.penalties.show', $transaction->penalty) }}">{{ $transaction->penalty->penalty_reference }}</a>
                                @else
                                    {{ $transaction->description }}
                                @endif
                            </td>
                            <td>{{ $transaction->actor?->full_name ?? 'System' }}</td>
                        </tr>
                    @empty
                        <tr><td class="text-center text-muted-custom py-5" colspan="7">No wallet transactions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $transactions->links() }}</div>
    </section>
@endsection
