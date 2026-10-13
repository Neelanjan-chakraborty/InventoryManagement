@extends('layouts.app')

@section('title', 'Issue desk')

@section('content')
<div class="page-heading"><div><span class="eyebrow">BORROWING OPERATIONS</span><h1>Issue desk</h1><p>Every issue is due 15 calendar days after its recorded issue date.</p></div><div class="inline-form"><a class="button button-secondary" href="{{ route('loans.export', request()->query()) }}">↓ Export transactions</a><button class="button button-secondary" type="button" onclick="window.print()">Print</button><a class="button button-secondary" href="{{ route('renewals.index') }}">Renewals <span>→</span></a></div></div>
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
<section class="section">
    <div class="section-head"><h2>Issue an item</h2><span class="pill">Registered students only</span></div>
    <div class="section-body"><form method="POST" action="{{ route('loans.store') }}" class="form-stack">@csrf
        <div class="form-grid"><label>Student<select name="student_id" required><option value="">Select a registered student</option>@foreach($students as $student)<option value="{{ $student->id }}">{{ $student->name }} · {{ $student->enrollment_number }}</option>@endforeach</select></label><label>Inventory item<select name="item_id" required><option value="">Select item</option>@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->code }} · {{ $item->availableQuantity() }} available{{ $item->tracking_type === 'asset' ? ' · tracked asset' : '' }}</option>@endforeach</select></label></div>
        <div class="form-grid"><label>Quantity<input type="number" name="quantity" min="1" value="1" required><span class="field-hint">For unique assets, issue quantity must be one.</span></label><label>Asset ID<select name="asset_id"><option value="">Not applicable for quantity stock</option>@foreach($items as $item)@foreach($item->assets->where('status', 'available')->where('condition', 'usable') as $asset)<option value="{{ $asset->id }}">{{ $item->code }} · {{ $asset->asset_tag }}{{ $asset->serial_number ? ' · '.$asset->serial_number : '' }}</option>@endforeach @endforeach</select></label></div>
        <div class="form-grid"><label>Issue date<input type="date" name="issue_date" value="{{ today()->format('Y-m-d') }}" required></label><label>Purpose<input name="purpose" required maxlength="1000" placeholder="Coursework, lab practical, project"></label></div>
        <button class="button button-primary" type="submit">Create issue & acknowledgement <span>→</span></button>
    </form></div>
</section>
<section class="section">
    <div class="section-head"><h2>Find issue and return records</h2><span class="table-sub">Filter by student, programme, item or laboratory.</span></div>
    <div class="section-body"><form method="GET" action="{{ route('loans.index') }}" class="form-stack">
        <div class="form-grid"><label>Student<select name="student_id"><option value="">All students</option>@foreach($students as $student)<option value="{{ $student->id }}" @selected(request('student_id') == $student->id)>{{ $student->name }} · {{ $student->enrollment_number }}</option>@endforeach</select></label><label>Programme<select name="programme"><option value="">All programmes</option>@foreach($programmes as $programme)<option value="{{ $programme }}" @selected(request('programme') === $programme)>{{ $programme }}</option>@endforeach</select></label></div>
        <div class="form-grid"><label>Item<select name="item_id"><option value="">All items</option>@foreach($items as $item)<option value="{{ $item->id }}" @selected(request('item_id') == $item->id)>{{ $item->name }} · {{ $item->code }}</option>@endforeach</select></label><label>Laboratory / location<select name="location_id"><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location_id') == $location->id)>{{ $location->name }}{{ $location->laboratory ? ' · '.$location->laboratory : '' }}</option>@endforeach</select></label></div>
        <div class="form-grid"><label>Issue status<select name="status"><option value="active" @selected(request('status', 'active') === 'active')>Active</option><option value="completed" @selected(request('status') === 'completed')>Completed</option><option value="all" @selected(request('status') === 'all')>All</option></select></label><div class="inline-form"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-secondary" href="{{ route('loans.index') }}">Clear</a></div></div>
    </form></div>
</section>
<section class="section"><div class="section-head"><h2>Open issues</h2><span class="pill">{{ $loans->total() }} active</span></div>
    @if($loans->isEmpty())<div class="empty-state">No active items are currently on loan.</div>@else
    <div class="table-wrap"><table><thead><tr><th>Student</th><th>Item</th><th>Issued</th><th>Due</th><th>Balance</th><th>Return inspection</th></tr></thead><tbody>
        @foreach($loans as $loan) @foreach($loan->lines->where('outstanding_quantity', '>', 0) as $line)
            <tr><td><strong>{{ $loan->student->name }}</strong><span class="table-sub">{{ $loan->student->enrollment_number }} · {{ $loan->student->programme }}</span></td><td>{{ $line->item->name }}<span class="table-sub">{{ $line->item->code }}{{ $line->asset ? ' · '.$line->asset->asset_tag : '' }}</span></td><td>{{ $loan->issue_date->format('d M Y') }}</td><td><strong>{{ $loan->due_date->format('d M Y') }}</strong>@if($loan->due_date->isBefore(today()))<span class="table-sub" style="color:var(--coral)">Overdue {{ today()->diffInDays($loan->due_date) }} days</span>@endif</td><td>{{ $line->outstanding_quantity }} / {{ $line->quantity }}</td><td>
                @if($loan->status === 'active')<form method="POST" action="{{ route('loan-lines.return', $line) }}" class="inline-form">@csrf
                    <label>Return qty<input type="number" name="quantity" min="1" max="{{ $line->outstanding_quantity }}" value="{{ $line->outstanding_quantity }}" required></label>
                    <label>Condition<select name="condition"><option value="usable">Usable</option><option value="damaged">Damaged</option><option value="lost">Lost</option><option value="maintenance">Maintenance</option></select></label>
                    <label>Remarks<input name="remarks" placeholder="Inspection notes"></label><button class="button button-primary button-small" type="submit">Record return</button>
                </form>@else <span class="pill">Returned</span>@endif
            </td></tr>
        @endforeach @endforeach
    </tbody></table></div><div class="pagination">{{ $loans->links() }}</div>@endif
</section>
@endsection
