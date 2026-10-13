@extends('layouts.app')

@section('title', 'Renewals')

@section('content')
<div class="page-heading"><div><span class="eyebrow">REQUEST REVIEW</span><h1>Renewal requests</h1><p>A request does not change the due date until a coordinator approves it.</p></div><a class="button button-secondary" href="{{ route('loans.index') }}">Issue desk <span>→</span></a></div>
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
<section class="section"><div class="section-head"><h2>Request history</h2><span class="pill">{{ $renewals->total() }} requests</span></div>
    @if($renewals->isEmpty())<div class="empty-state">No renewal requests have been submitted.</div>@else
    <div class="table-wrap"><table><thead><tr><th>Student</th><th>Item</th><th>Requested</th><th>Due date</th><th>Decision</th></tr></thead><tbody>
        @foreach($renewals as $renewal)
            <tr><td><strong>{{ $renewal->requester->name }}</strong><span class="table-sub">{{ $renewal->requester->enrollment_number }} · {{ $renewal->requester->programme }}</span></td><td>@foreach($renewal->loan->lines as $line){{ $line->item->name }}{{ $line->asset ? ' · '.$line->asset->asset_tag : '' }}@endforeach</td><td>{{ $renewal->requested_at->format('d M Y, H:i') }}</td><td>{{ $renewal->previous_due_date->format('d M Y') }} @if($renewal->revised_due_date)<span class="table-sub">Revised: {{ $renewal->revised_due_date->format('d M Y') }}</span>@endif</td><td>
                @if($renewal->status === 'pending')
                    <form method="POST" action="{{ route('renewals.review', $renewal) }}" class="form-stack">@csrf
                        <label>Remarks<input name="remarks" maxlength="1000" placeholder="Decision notes"></label>
                        <div class="inline-form"><button class="button button-primary button-small" name="decision" value="approved" type="submit">Approve +15 days</button><button class="button button-danger button-small" name="decision" value="rejected" type="submit">Reject</button></div>
                    </form>
                @else
                    <span class="pill {{ $renewal->status === 'rejected' ? 'pill-warning' : '' }}">{{ ucfirst($renewal->status) }}</span><span class="table-sub">{{ $renewal->reviewer?->name }}{{ $renewal->remarks ? ' · '.$renewal->remarks : '' }}</span>
                @endif
            </td></tr>
        @endforeach
    </tbody></table></div><div class="pagination">{{ $renewals->links() }}</div>@endif
</section>
@endsection
