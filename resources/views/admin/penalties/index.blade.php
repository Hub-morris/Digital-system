@extends('layouts.app')

@section('title', 'Penalty management | ' . config('app.name'))

@section('content')
    <section class="container page-wrap py-5">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">Administration · Management</p>
                <h1 class="h2 mb-0">Penalty management</h1>
            </div>
            <a class="btn btn-outline-primary" href="{{ route('admin.penalty-rules.edit') }}">Configure penalty rules</a>
        </div>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif

        <div class="content-panel p-3 mb-4">
            <h2 class="h5">Generate penalty report</h2>
            <div class="d-flex flex-wrap gap-2">
                @foreach (['daily' => 'Daily report', 'monthly' => 'Monthly report'] as $period => $label)
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.penalties.report', ['period' => $period, 'date' => today()->toDateString()]) }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        <form class="content-panel p-3 mb-4" method="GET" action="{{ route('admin.penalties.index') }}">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="user_email">Passenger email</label>
                    <input class="form-control" id="user_email" name="user_email" type="email" value="{{ request('user_email') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="flight_number">Flight number</label>
                    <input class="form-control" id="flight_number" name="flight_number" value="{{ request('flight_number') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="date_from">From</label>
                    <input class="form-control" id="date_from" name="date_from" type="date" value="{{ request('date_from') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="date_to">To</label>
                    <input class="form-control" id="date_to" name="date_to" type="date" value="{{ request('date_to') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-primary" type="submit">Filter</button>
                </div>
            </div>
        </form>

        <div class="content-panel table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Penalty</th>
                        <th scope="col">Passenger</th>
                        <th scope="col">Flight</th>
                        <th scope="col">Booking</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Amount / paid</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Details</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($penalties as $penalty)
                        <tr>
                            <td><span class="font-monospace">{{ $penalty->penalty_reference }}</span><span class="d-block small text-muted-custom">{{ $penalty->created_at->format('M j, Y') }}</span></td>
                            <td>{{ $penalty->passenger->full_name }}<span class="d-block small text-muted-custom">{{ $penalty->passenger->email }}</span></td>
                            <td>{{ $penalty->flight->flight_number }}</td>
                            <td>{{ $penalty->booking->booking_reference }}</td>
                            <td>{{ $penalty->penalty_reason }}</td>
                            <td>{{ number_format((float) $penalty->penalty_amount, 2) }}<span class="d-block small text-muted-custom">Paid {{ number_format((float) $penalty->amount_paid, 2) }}</span></td>
                            <td>{{ $penalty->status }}</td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.penalties.show', $penalty) }}">Manage</a></td>
                        </tr>
                    @empty
                        <tr><td class="text-center text-muted-custom py-5" colspan="8">No penalties match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $penalties->links() }}</div>
    </section>
@endsection
