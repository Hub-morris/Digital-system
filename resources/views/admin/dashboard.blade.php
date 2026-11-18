@extends('layouts.app')

@section('title', 'Administration | ' . config('app.name'))

@section('content')
    @php
        $cards = [
            ['label' => 'Passengers', 'key' => 'passengers', 'route' => 'admin.users.index', 'query' => ['role' => 'passenger'], 'color' => 'primary'],
            ['label' => 'Airport staff', 'key' => 'staff', 'route' => 'admin.users.index', 'query' => ['role' => 'staff'], 'color' => 'success'],
            ['label' => 'Flights', 'key' => 'flights', 'route' => 'admin.flights.index', 'query' => [], 'color' => 'info'],
            ['label' => 'Bookings', 'key' => 'bookings', 'route' => 'admin.bookings.index', 'query' => [], 'color' => 'warning'],
            ['label' => 'Check-ins', 'key' => 'check_ins', 'route' => 'admin.bookings.index', 'query' => [], 'color' => 'secondary'],
            ['label' => 'Active passes', 'key' => 'active_boarding_passes', 'route' => 'admin.boarding-passes.index', 'query' => ['pass_status' => 'ACTIVE'], 'color' => 'success'],
            ['label' => 'Used passes', 'key' => 'used_boarding_passes', 'route' => 'admin.boarding-passes.index', 'query' => ['pass_status' => 'USED'], 'color' => 'secondary'],
            ['label' => 'Invalid attempts', 'key' => 'invalid_verifications', 'route' => 'admin.verification-records.index', 'query' => ['result' => 'INVALID'], 'color' => 'danger'],
            ['label' => "Today's boarding", 'key' => 'today_boarding_activity', 'route' => 'admin.reports.index', 'query' => [], 'color' => 'primary'],
            ['label' => 'Offline verifications', 'key' => 'offline_verifications', 'route' => 'admin.verification-records.index', 'query' => ['verification_method' => 'OFFLINE'], 'color' => 'info'],
            ['label' => 'Pending synchronization', 'key' => 'pending_synchronization', 'route' => 'admin.verification-records.index', 'query' => [], 'color' => 'warning'],
        ];
    @endphp

    <section class="container-fluid admin-dashboard px-3 px-lg-4 py-4">
        <section class="content-panel p-3 p-lg-4 mb-4" aria-labelledby="admin-management-heading">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
                <div>
                    <p class="eyebrow mb-1">Quick access</p>
                    <h1 class="h3 mb-0" id="admin-management-heading">Management</h1>
                </div>
                <span class="small text-muted-custom">Admin tools, one click away</span>
            </div>
            <div class="row g-3">
                <div class="col-6 col-md-4 col-xl">
                    <a class="content-panel quick-access-link quick-access-flights d-flex align-items-center gap-3 h-100 p-3 text-decoration-none text-body" href="{{ route('admin.flights.index') }}">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><path d="M3 13.5 21 3l-5.5 18-3.2-7.3L3 13.5Z"/><path d="m12.3 13.7 4.2-4.2"/></svg>
                        <span class="fw-semibold">Flight Management</span>
                    </a>
                </div>
                <div class="col-6 col-md-4 col-xl">
                    <a class="content-panel quick-access-link quick-access-users d-flex align-items-center gap-3 h-100 p-3 text-decoration-none text-body" href="{{ route('admin.users.index') }}">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 5.2a3.5 3.5 0 0 1 0 5.6M18 14a5.5 5.5 0 0 1 3.5 5.2"/></svg>
                        <span class="fw-semibold">User Management</span>
                    </a>
                </div>
                <div class="col-6 col-md-4 col-xl">
                    <a class="content-panel quick-access-link quick-access-bookings d-flex align-items-center gap-3 h-100 p-3 text-decoration-none text-body" href="{{ route('admin.bookings.index') }}">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><path d="M4 4.5h16v15H4z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                        <span class="fw-semibold">Booking Management</span>
                    </a>
                </div>
                <div class="col-6 col-md-6 col-xl">
                    <a class="content-panel quick-access-link quick-access-payments d-flex align-items-center gap-3 h-100 p-3 text-decoration-none text-body" href="{{ route('admin.payments.index') }}">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9h19M7 14h4"/></svg>
                        <span class="fw-semibold">Payment Management</span>
                    </a>
                </div>
                <div class="col-6 col-md-6 col-xl">
                    <a class="content-panel quick-access-link quick-access-penalties d-flex align-items-center gap-3 h-100 p-3 text-decoration-none text-body" href="{{ route('admin.penalties.index') }}">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.2v.1"/></svg>
                        <span class="fw-semibold">Penalty Management</span>
                    </a>
                </div>
            </div>
        </section>

        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">Airport operations · Administration</p>
                <h2 class="h2 mb-1">Operations dashboard</h2>
                <p class="text-muted-custom mb-0">Live overview of passengers, flights, boarding and system synchronization.</p>
            </div>
            <div class="content-panel px-3 py-2">
                <span class="mono-label d-block">Administrator</span>
                <span class="fw-semibold">{{ auth()->user()->full_name }}</span>
            </div>
        </div>
        <p class="small text-muted-custom mb-3">
            Offline verification and synchronization metrics reflect records received centrally; scans still queued on a disconnected gate terminal appear after it reconnects.
        </p>

        <div class="row g-3 mb-4">
            @foreach ($cards as $card)
                <div class="col-6 col-md-4 col-xl-3 col-xxl-2">
                    <a class="content-panel metric-card d-block h-100 p-3 text-decoration-none" href="{{ route($card['route'], $card['query']) }}">
                        <span class="d-flex align-items-center gap-2 small text-muted-custom">
                            <span class="badge text-bg-{{ $card['color'] }}">&nbsp;</span>
                            {{ $card['label'] }}
                        </span>
                        <span class="d-block display-6 fw-semibold mt-2 text-body">{{ number_format($statistics[$card['key']]) }}</span>
                    </a>
                </div>
            @endforeach
        </div>

        <div class="row g-4">
            <div class="col-12">
                <section class="content-panel overflow-hidden">
                    <div class="d-flex justify-content-between align-items-center gap-3 p-3 border-bottom">
                        <div>
                            <p class="mono-label mb-1">Gate activity</p>
                            <h2 class="h5 mb-0">Recent verifications</h2>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.verification-records.index') }}">View all</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr><th>Time</th><th>Pass reference</th><th>Flight</th><th>Staff</th><th>Result</th><th>Method</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($recentVerifications as $verification)
                                    <tr>
                                        <td class="text-nowrap">{{ $verification->verified_at->format('M j, H:i') }}</td>
                                        <td class="font-monospace">{{ $verification->verification_reference ?? '—' }}</td>
                                        <td>{{ $verification->flight?->flight_number ?? '—' }}</td>
                                        <td>{{ $verification->staff?->full_name ?? '—' }}</td>
                                        <td><span class="badge text-bg-{{ $verification->result === 'VALID' ? 'success' : 'secondary' }}">{{ $verification->result }}</span></td>
                                        <td>{{ $verification->verification_method }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-center text-muted-custom py-4" colspan="6">No verification activity recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </section>
@endsection