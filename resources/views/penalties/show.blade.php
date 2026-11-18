@extends('layouts.app')

@section('title', 'Penalty receipt ' . $penalty->penalty_reference . ' | ' . config('app.name'))

@section('content')
    @php
        $amountDue = max(0, (float) $penalty->penalty_amount - (float) $penalty->amount_paid);
        $walletBalance = (float) $walletBalance;
    @endphp
    <section class="container page-wrap py-5">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2"><a href="{{ route('penalties.index') }}">Wallet & penalty history</a> / Receipt</p>
                <h1 class="h2 mb-1">Penalty receipt</h1>
                <p class="font-monospace text-muted-custom mb-0">{{ $penalty->penalty_reference }}</p>
            </div>
            <button class="btn btn-outline-secondary" type="button" onclick="window.print()">Print receipt</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="content-panel p-4 p-md-5" aria-labelledby="receipt-details-heading">
            <h2 class="h5 mb-4" id="receipt-details-heading">Penalty details</h2>
            <dl class="row mb-0">
                <dt class="col-sm-4">Status</dt><dd class="col-sm-8">{{ $penalty->status }}</dd>
                <dt class="col-sm-4">Reason</dt><dd class="col-sm-8">{{ $penalty->penalty_reason }}</dd>
                <dt class="col-sm-4">Booking</dt><dd class="col-sm-8"><a href="{{ route('bookings.show', $penalty->booking) }}">{{ $penalty->booking->booking_reference }}</a></dd>
                <dt class="col-sm-4">Flight</dt><dd class="col-sm-8">{{ $penalty->flight->flight_number }} · {{ $penalty->flight->departure_airport }} to {{ $penalty->flight->arrival_airport }}</dd>
                <dt class="col-sm-4">Departure</dt><dd class="col-sm-8">{{ $penalty->flight->departure_date->format('M j, Y') }} at {{ substr($penalty->flight->departure_time, 0, 5) }}</dd>
                <dt class="col-sm-4">Cabin class / fare</dt><dd class="col-sm-8">{{ $penalty->booking->cabin_class }} · {{ number_format((float) $penalty->booking->fare_amount, 2) }}</dd>
                <dt class="col-sm-4">Penalty amount</dt><dd class="col-sm-8">{{ number_format((float) $penalty->penalty_amount, 2) }}</dd>
                <dt class="col-sm-4">Paid</dt><dd class="col-sm-8">{{ number_format((float) $penalty->amount_paid, 2) }}</dd>
                <dt class="col-sm-4">Amount due</dt><dd class="col-sm-8">{{ number_format($amountDue, 2) }}</dd>
                <dt class="col-sm-4">Issued</dt><dd class="col-sm-8">{{ $penalty->created_at->format('M j, Y g:i A') }}</dd>
            </dl>
        </section>

        @if ($amountDue > 0 && $penalty->status === \App\Models\Penalty::STATUS_APPLIED)
            <form class="content-panel p-4 mt-4" method="POST" action="{{ route('penalties.pay', $penalty) }}">
                @csrf
                <h2 class="h5">Pay from wallet</h2>
                <p class="mb-3">Wallet balance: {{ number_format($walletBalance, 2) }} · Due: {{ number_format($amountDue, 2) }}</p>
                <button class="btn btn-primary" type="submit" @disabled($walletBalance < $amountDue)>Pay penalty</button>
                @if ($walletBalance < $amountDue)
                    <p class="small text-muted-custom mt-2 mb-0">Contact the administrator to record a wallet credit before paying this balance.</p>
                @endif
            </form>
        @endif

        <section class="content-panel overflow-hidden mt-4" aria-labelledby="receipt-transactions-heading">
            <h2 class="h5 p-3 mb-0 border-bottom" id="receipt-transactions-heading">Payment history</h2>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Amount</th><th scope="col">Wallet balance</th></tr></thead>
                    <tbody>
                        @forelse ($penalty->walletTransactions as $transaction)
                            <tr>
                                <td>{{ $transaction->created_at->format('M j, Y g:i A') }}</td>
                                <td>{{ ucfirst($transaction->transaction_type) }}</td>
                                <td>{{ number_format((float) $transaction->amount, 2) }}</td>
                                <td>{{ number_format((float) $transaction->balance_after, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted-custom py-4" colspan="4">No wallet payments have been recorded for this penalty.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </section>
@endsection
