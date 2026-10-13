@extends('layouts.app')

@section('title', 'My items')

@section('content')
<div class="page-heading"><div><span class="eyebrow">PERSONAL HISTORY</span><h1>My items</h1><p>Issues, returns, due dates and coordinator decisions on your account.</p></div><a class="button button-secondary" href="{{ route('dashboard') }}">← Overview</a></div>
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
@if($loans->isEmpty())<section class="section"><div class="empty-state">You have no borrowing history yet.</div></section>
@else
    @foreach($loans as $loan)
        <section class="section">
            <div class="section-head"><div><h2>Issue #{{ $loan->id }} · {{ $loan->issue_date->format('d M Y') }}</h2><span class="table-sub">Issued by {{ $loan->issuer->name }} · {{ $loan->purpose }}</span></div><span class="pill {{ $loan->status === 'active' && $loan->due_date->isBefore(today()) ? 'pill-warning' : '' }}">{{ $loan->status === 'active' && $loan->due_date->isBefore(today()) ? 'Overdue' : ucfirst($loan->status) }}</span></div>
            <div class="table-wrap"><table><thead><tr><th>Item</th><th>Due</th><th>Issued</th><th>Outstanding</th><th>Action</th></tr></thead><tbody>
                @foreach($loan->lines as $line)
                    <tr><td><strong>{{ $line->item->name }}</strong><span class="table-sub">{{ $line->item->code }}{{ $line->asset ? ' · '.$line->asset->asset_tag : '' }}</span></td><td><strong>{{ $loan->due_date->format('d M Y') }}</strong></td><td>{{ $line->quantity }}</td><td>{{ $line->outstanding_quantity }}</td><td>
                        @if($loan->status === 'active' && $line->outstanding_quantity > 0)
                            @if($loan->renewals->where('status', 'pending')->isNotEmpty())<span class="pill pill-pending">Renewal awaiting review</span>
                            @elseif($loan->due_date->isBefore(today()))<span class="pill pill-warning">Return is overdue</span>
                            @else<form method="POST" action="{{ route('my-loans.renew', $loan) }}">@csrf<button class="button button-secondary button-small" type="submit">Request renewal</button></form>@endif
                        @else<span class="muted">—</span>@endif
                    </td></tr>
                @endforeach
            </tbody></table></div>
            @foreach($loan->lines as $line)
                @if($line->returns->isNotEmpty())<div class="section-body"><strong class="section-title">Return record</strong>@foreach($line->returns as $return)<p class="muted">{{ $return->quantity }} returned {{ $return->condition }} on {{ $return->received_at->format('d M Y, H:i') }} · received by {{ $return->receiver->name }}{{ $return->remarks ? ' · '.$return->remarks : '' }}</p>@endforeach</div>@endif
            @endforeach
            @if($loan->renewals->isNotEmpty())<div class="section-body"><strong class="section-title">Renewal history</strong>@foreach($loan->renewals as $renewal)<p class="muted">{{ ucfirst($renewal->status) }} · requested {{ $renewal->requested_at->format('d M Y') }}@if($renewal->reviewer) · reviewed by {{ $renewal->reviewer->name }}@endif{{ $renewal->remarks ? ' · '.$renewal->remarks : '' }}</p>@endforeach</div>@endif
        </section>
    @endforeach
    <div class="pagination">{{ $loans->links() }}</div>
@endif
@endsection
