@extends('layouts.app')

@section('title', 'Wallet and penalties | ' . config('app.name'))

@section('content')
    <section class="container page-wrap py-5">
        <div class="mb-4">
            <p class="eyebrow mb-2">Passenger account</p>
            <h1 class="h2 mb-0">Wallet & penalty history</h1>
        </div>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif

        <div class="row g-3 mb-4">
            <div class="col-sm-6">
                <section class="content-panel h-100 p-3">
                    <p class="mono-label mb-1">Wallet balance</p>
                    <p class="h3 mb-0">{{ number_format((float) $walletBalance, 2) }}</p>
                </section>
            </div>
            <div class="col-sm-6">
                <section class="content-panel h-100 p-3">
                    <p class="mono-label mb-1">Outstanding penalties</p>
                    <p class="h3 mb-0">{{ number_format((float) $outstandingAmount, 2) }}</p>
                </section>
            </div>
        </div>

        <div class="content-panel table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th scope="col">Receipt</th><th scope="col">Booking / flight</th><th scope="col">Reason</th><th scope="col">Amount / paid</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Receipt</span></th></tr></thead>
                <tbody>
                    @forelse ($penalties as $penalty)
                        <tr>
                            <td><span class="font-monospace">{{ $penalty->penalty_reference }}</span><span class="d-block small text-muted-custom">{{ $penalty->created_at->format('M j, Y') }}</span></td>
                            <td>{{ $penalty->booking->booking_reference }}<span class="d-block small text-muted-custom">{{ $penalty->flight->flight_number }}</span></td>
                            <td>{{ $penalty->penalty_reason }}</td>
                            <td>{{ number_format((float) $penalty->penalty_amount, 2) }}<span class="d-block small text-muted-custom">Paid {{ number_format((float) $penalty->amount_paid, 2) }}</span></td>
                            <td>{{ $penalty->status }}</td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('penalties.show', $penalty) }}">View receipt</a></td>
                        </tr>
                    @empty
                        <tr><td class="text-center text-muted-custom py-5" colspan="6">There are no penalties on your account.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $penalties->links() }}</div>
    </section>
@endsection
