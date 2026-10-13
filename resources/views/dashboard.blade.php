@extends('layouts.app')

@section('title', 'Overview')

@section('content')
@if(auth()->user()->hasRole('student'))
    <div class="page-heading">
        <div><span class="eyebrow">STUDENT ACCOUNT</span><h1>Good day, {{ explode(' ', auth()->user()->name)[0] }}.</h1><p>Your borrowed equipment and next due dates, all in one place.</p></div>
        <a class="button button-secondary" href="{{ route('my-loans') }}">View full history <span>→</span></a>
    </div>
    <div class="stat-grid">
        <div class="stat"><span class="stat-label">ACTIVE LOANS</span><strong class="stat-value">{{ $activeLoans }}</strong><span class="stat-note">Items currently checked out</span></div>
        <div class="stat {{ $overdueLoans ? 'alert-stat' : '' }}"><span class="stat-label">OVERDUE</span><strong class="stat-value">{{ $overdueLoans }}</strong><span class="stat-note">Needs return or coordinator review</span></div>
        <div class="stat"><span class="stat-label">DUE IN 5 DAYS</span><strong class="stat-value">{{ $dueSoonLoans }}</strong><span class="stat-note">Plan your return or renewal</span></div>
        <div class="stat"><span class="stat-label">ENROLLMENT</span><strong class="stat-value" style="font-size:16px">{{ auth()->user()->enrollment_number }}</strong><span class="stat-note">{{ auth()->user()->programme }} · Semester {{ auth()->user()->semester }}</span></div>
    </div>
    <section class="section">
        <div class="section-head"><h2>Current borrowing</h2><a href="{{ route('my-loans') }}">History <span>→</span></a></div>
        @php($currentLoans = $loans->where('status', 'active'))
        @if($currentLoans->isEmpty())
            <div class="empty-state">No items are currently checked out to your account.</div>
        @else
            <div class="table-wrap"><table><thead><tr><th>Item</th><th>Issue date</th><th>Due date</th><th>Outstanding</th><th>Renewal</th></tr></thead><tbody>
            @foreach($currentLoans as $loan)
                @foreach($loan->lines as $line)
                    <tr><td><strong>{{ $line->item->name }}</strong><span class="table-sub">{{ $line->item->code }}{{ $line->asset ? ' · '.$line->asset->asset_tag : '' }}</span></td><td>{{ $loan->issue_date->format('d M Y') }}</td><td><strong>{{ $loan->due_date->format('d M Y') }}</strong>@if($loan->due_date->isBefore(today()))<span class="table-sub" style="color:var(--coral)">{{ today()->diffInDays($loan->due_date) }} days overdue</span>@endif</td><td>{{ $line->outstanding_quantity }}</td><td>
                        @if($loan->renewals->where('status', 'pending')->isNotEmpty())<span class="pill pill-pending">Awaiting review</span>
                        @elseif($loan->due_date->isBefore(today()))<span class="pill pill-warning">Overdue</span>
                        @else<form method="POST" action="{{ route('my-loans.renew', $loan) }}">@csrf<button class="button button-secondary button-small" type="submit">Request renewal</button></form>@endif
                    </td></tr>
                @endforeach
            @endforeach
            </tbody></table></div>
        @endif
    </section>
@else
    <div class="page-heading">
        <div><span class="eyebrow">INVENTORY OPERATIONS</span><h1>Department overview</h1><p>Stock health, due dates and decisions that need attention.</p></div>
        <a class="button button-primary" href="{{ route('loans.index') }}">Open issue desk <span>→</span></a>
    </div>
    <div class="stat-grid">
        <div class="stat"><span class="stat-label">ACTIVE ITEMS</span><strong class="stat-value">{{ $itemCount }}</strong><span class="stat-note">Catalogued inventory records</span></div>
        <div class="stat"><span class="stat-label">AVAILABLE UNITS</span><strong class="stat-value">{{ $availableCount }}</strong><span class="stat-note">Usable and ready to issue</span></div>
        <div class="stat"><span class="stat-label">ACTIVE LOANS</span><strong class="stat-value">{{ $activeCount }}</strong><span class="stat-note">Open student transactions</span></div>
        <div class="stat {{ $overdueCount ? 'alert-stat' : '' }}"><span class="stat-label">OVERDUE LOANS</span><strong class="stat-value">{{ $overdueCount }}</strong><span class="stat-note">Across {{ $pendingRenewals }} pending renewal requests</span></div>
    </div>
    <div class="content-grid">
        <section class="section">
            <div class="section-head"><h2>Overdue items</h2><a href="{{ route('loans.index') }}">Issue desk <span>→</span></a></div>
            @if($overdueLoans->isEmpty())<div class="empty-state">No overdue items. The return desk is clear.</div>
            @else<div class="table-wrap"><table><thead><tr><th>Student</th><th>Item</th><th>Due</th><th>Late</th></tr></thead><tbody>
                @foreach($overdueLoans as $loan) @foreach($loan->lines as $line)
                    <tr><td><strong>{{ $loan->student->name }}</strong><span class="table-sub">{{ $loan->student->enrollment_number }}</span></td><td>{{ $line->item->name }}<span class="table-sub">{{ $line->outstanding_quantity }} outstanding</span></td><td>{{ $loan->due_date->format('d M Y') }}</td><td><span class="pill pill-warning">{{ today()->diffInDays($loan->due_date) }} days</span></td></tr>
                @endforeach @endforeach
            </tbody></table></div>@endif
        </section>
        <section class="section">
            <div class="section-head"><h2>Due in the next 5 days</h2><span class="pill">{{ $upcomingLoans->count() }} loans</span></div>
            @if($upcomingLoans->isEmpty())<div class="empty-state">Nothing is due in the next five days.</div>
            @else<div class="table-wrap"><table><thead><tr><th>Student / item</th><th>Due</th></tr></thead><tbody>
                @foreach($upcomingLoans as $loan) @foreach($loan->lines as $line)
                    <tr><td><strong>{{ $loan->student->name }}</strong><span class="table-sub">{{ $line->item->name }} · {{ $line->outstanding_quantity }} outstanding</span></td><td>{{ $loan->due_date->format('d M') }}</td></tr>
                @endforeach @endforeach
            </tbody></table></div>@endif
            @if(auth()->user()->hasRole('admin'))<div class="section-body"><a href="{{ route('admin.users.index') }}">Manage account access <span>→</span></a></div>@endif
        </section>
    </div>
@endif
@endsection
