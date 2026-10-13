@extends('layouts.app')

@section('title', 'Issue acknowledgement')

@section('content')
<div class="page-heading"><div><span class="eyebrow">ISSUE ACKNOWLEDGEMENT</span><h1>Issue recorded</h1><p>Transaction #{{ $loan->id }} · Issued {{ $loan->issue_date->format('d M Y') }}</p></div><button class="button button-secondary" type="button" onclick="window.print()">Print acknowledgement</button></div>
<section class="section">
    <div class="section-head"><h2>{{ $loan->student->name }}</h2><span class="pill">{{ $loan->student->enrollment_number }}</span></div>
    <div class="section-body"><p class="muted">{{ $loan->student->programme }} · Semester {{ $loan->student->semester }} · {{ $loan->student->contact }}</p><p><strong>Purpose:</strong> {{ $loan->purpose }}</p><p><strong>Issued by:</strong> {{ $loan->issuer->name }}</p></div>
    <div class="table-wrap"><table><thead><tr><th>Item</th><th>Quantity</th><th>Due date</th></tr></thead><tbody>@foreach($loan->lines as $line)<tr><td><strong>{{ $line->item->name }}</strong><span class="table-sub">{{ $line->item->code }}{{ $line->asset ? ' · Asset '.$line->asset->asset_tag : '' }}</span></td><td>{{ $line->quantity }}</td><td><strong>{{ $loan->due_date->format('l, d F Y') }}</strong></td></tr>@endforeach</tbody></table></div>
    <div class="section-body"><p class="muted">Please return the item or obtain an approved renewal by the due date. A submitted renewal request does not extend this date.</p><a href="{{ route('loans.index') }}" class="button button-primary">Back to issue desk <span>→</span></a></div>
</section>
@endsection
