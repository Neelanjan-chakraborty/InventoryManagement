@extends('layouts.app')

@section('title', 'Inventory')

@section('content')
<div class="page-heading">
    <div><span class="eyebrow">STOCK REGISTER</span><h1>Inventory</h1><p>Track quantities, unique assets, condition and issue limits.</p></div>
    <div class="inline-form"><a class="button button-secondary" href="{{ route('inventory.export') }}">↓ Export CSV</a><button class="button button-secondary" type="button" onclick="window.print()">Print</button><button class="button button-primary" type="button" onclick="document.getElementById('new-item').open = true">+ Add item</button></div>
</div>
<details class="section" id="new-item">
    <summary>Add inventory item</summary>
    <div class="details-body"><form method="POST" action="{{ route('inventory.store') }}" class="form-stack">@csrf
        <div class="form-grid"><label>Item code<input name="code" required maxlength="60" value="{{ old('code') }}"></label><label>Item name<input name="name" required maxlength="160" value="{{ old('name') }}"></label></div>
        <div class="form-grid"><label>Category<input name="category" required value="{{ old('category') }}"></label><label>Make / model<input name="make_model" value="{{ old('make_model') }}"></label></div>
        <label>Description<textarea name="description">{{ old('description') }}</textarea></label>
        <div class="form-grid"><label>Tracking type<select name="tracking_type"><option value="bulk">Quantity-based</option><option value="asset">Individually tracked asset</option></select></label><label>Opening quantity<input type="number" name="total_quantity" min="0" value="0" required><span class="field-hint">For individually tracked stock, set zero and register assets below.</span></label></div>
        <div class="form-grid"><label>Storage location<select name="location_id"><option value="">Not assigned</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></label><label>Maximum per issue<input type="number" name="max_per_issue" min="1" value="10" required></label></div>
        <div class="form-grid"><label>Maximum renewals<input type="number" name="max_renewals" min="0" value="2" required></label><span class="field-hint">New items are available only after their stock or assets are registered.</span></div>
        <button class="button button-primary" type="submit">Save inventory item</button>
    </form></div>
</details>
@if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
<section class="section">
    <div class="section-head"><h2>Registered items</h2><span class="pill">{{ $items->total() }} records</span></div>
    @if($items->isEmpty())<div class="empty-state">No inventory items are registered yet.</div>
    @else<div class="table-wrap"><table><thead><tr><th>Item</th><th>Type</th><th>Total</th><th>Available</th><th>Issued</th><th>Overdue</th><th>Unavailable</th><th>Location</th><th></th></tr></thead><tbody>
        @foreach($items as $item)
            @php
                $issued = $item->loanLines()->where('outstanding_quantity', '>', 0)->whereHas('loan', fn ($query) => $query->where('status', 'active'))->sum('outstanding_quantity');
                $overdue = $item->loanLines()->where('outstanding_quantity', '>', 0)->whereHas('loan', fn ($query) => $query->where('status', 'active')->whereDate('due_date', '<', today()))->sum('outstanding_quantity');
                $damaged = $item->conditionQuantity('damaged');
                $lost = $item->conditionQuantity('lost');
                $maintenance = $item->conditionQuantity('maintenance');
                $unavailable = $damaged + $lost + $maintenance;
            @endphp
            <tr>
                <td><strong>{{ $item->name }}</strong><span class="table-sub">{{ $item->code }} · {{ $item->category }}</span></td>
                <td><span class="pill">{{ $item->tracking_type === 'asset' ? 'Asset' : 'Quantity' }}</span>@unless($item->active)<span class="table-sub">Retired</span>@endunless</td>
                <td>{{ $item->total_quantity }}</td><td><strong>{{ $item->availableQuantity() }}</strong></td><td>{{ $issued }}</td><td>{{ $overdue }}</td>
                <td>{{ $unavailable }}<span class="table-sub">D {{ $damaged }} · L {{ $lost }} · M {{ $maintenance }}</span></td>
                <td>{{ $item->location?->name ?? '—' }}</td><td><button class="button button-secondary button-small" type="button" onclick="document.getElementById('manage-{{ $item->id }}').open = !document.getElementById('manage-{{ $item->id }}').open">Manage</button></td>
            </tr>
            <tr><td colspan="9" style="padding:0"><details id="manage-{{ $item->id }}"><summary>Manage {{ $item->code }}</summary><div class="details-body">
                <form method="POST" action="{{ route('inventory.update', $item) }}" class="form-stack">@csrf @method('PUT')
                    <div class="form-grid"><label>Name<input name="name" value="{{ $item->name }}" required></label><label>Category<input name="category" value="{{ $item->category }}" required></label></div>
                    <div class="form-grid"><label>Make / model<input name="make_model" value="{{ $item->make_model }}"></label><label>Location<select name="location_id"><option value="">Not assigned</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected($item->location_id === $location->id)>{{ $location->name }}</option>@endforeach</select></label></div>
                    <label>Description<textarea name="description">{{ $item->description }}</textarea></label>
                    <div class="form-grid"><label>Maximum per issue<input type="number" name="max_per_issue" min="1" value="{{ $item->max_per_issue }}" required></label><label>Maximum renewals<input type="number" name="max_renewals" min="0" value="{{ $item->max_renewals }}" required></label></div>
                    <button class="button button-secondary button-small" type="submit">Save item and policy</button>
                </form>
                <form method="POST" action="{{ route('inventory.status', $item) }}" class="inline-form" style="margin-top:14px">@csrf
                    <input type="hidden" name="active" value="{{ $item->active ? 0 : 1 }}"><label>Reason<input name="reason" required placeholder="{{ $item->active ? 'Reason for retirement' : 'Reason for reactivation' }}"></label><button class="button {{ $item->active ? 'button-danger' : 'button-secondary' }} button-small" type="submit">{{ $item->active ? 'Retire item' : 'Reactivate item' }}</button>
                </form>
                @if($item->tracking_type === 'bulk')
                    <form method="POST" action="{{ route('inventory.adjust', $item) }}" class="form-stack" style="margin-top:20px">@csrf
                        <strong class="section-title">Stock correction or condition recovery</strong>
                        <div class="form-grid"><label>Adjustment<select name="adjustment"><option value="add">Add stock</option><option value="remove">Remove available stock</option><option value="restore_damaged">Restore damaged to usable</option><option value="restore_lost">Correct lost count</option><option value="restore_maintenance">Restore from maintenance</option></select></label><label>Quantity<input type="number" name="quantity" min="1" required></label></div>
                        <label>Reason<input name="reason" required maxlength="1000" placeholder="Required for the audit record"></label><button class="button button-secondary button-small" type="submit">Record adjustment</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('inventory.assets.store', $item) }}" class="form-stack" style="margin-top:20px">@csrf
                        <strong class="section-title">Register a unique asset</strong>
                        <div class="form-grid"><label>Asset ID<input name="asset_tag" required></label><label>Serial number<input name="serial_number"></label></div>
                        <label>Location<select name="location_id"><option value="">Use item location</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></label><button class="button button-secondary button-small" type="submit">Add asset</button>
                    </form>
                    @foreach($item->assets as $asset)
                        <form method="POST" action="{{ route('assets.condition', $asset) }}" class="inline-form" style="margin-top:11px">@csrf
                            <strong>{{ $asset->asset_tag }} <span class="table-sub">{{ $asset->serial_number ?? 'No serial' }} · {{ $asset->status }}</span></strong>
                            <label>Condition<select name="condition"><option @selected($asset->condition === 'usable') value="usable">Usable</option><option @selected($asset->condition === 'damaged') value="damaged">Damaged</option><option @selected($asset->condition === 'lost') value="lost">Lost</option><option @selected($asset->condition === 'maintenance') value="maintenance">Maintenance</option></select></label>
                            <label>Status<select name="status"><option @selected($asset->status === 'available') value="available">Available</option><option @selected($asset->status === 'damaged') value="damaged">Damaged</option><option @selected($asset->status === 'lost') value="lost">Lost</option><option @selected($asset->status === 'maintenance') value="maintenance">Maintenance</option><option @selected($asset->status === 'retired') value="retired">Retired</option></select></label>
                            <label>Location<select name="location_id"><option value="">No location</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected($asset->location_id === $location->id)>{{ $location->name }}</option>@endforeach</select></label>
                            <label>Reason<input name="reason" required placeholder="Condition change reason"></label><button class="button button-secondary button-small" type="submit">Update</button>
                        </form>
                    @endforeach
                @endif
            </div></details></td></tr>
        @endforeach
    </tbody></table></div>
    <div class="pagination">{{ $items->links() }}</div>@endif
</section>
<div class="content-grid">
    <section class="section"><div class="section-head"><h2>Storage locations</h2></div><div class="section-body">
        <form method="POST" action="{{ route('locations.store') }}" class="form-stack">@csrf
            <label>Location name<input name="name" required placeholder="Lab 2 · Cabinet A"></label>
            <div class="form-grid"><label>Department<input name="department"></label><label>Laboratory<input name="laboratory"></label></div>
            <div class="form-grid"><label>Room<input name="room"></label><label>Cupboard<input name="cupboard"></label></div>
            <button class="button button-secondary" type="submit">Add location</button>
        </form>
        @foreach($locations as $location)
            <details><summary>{{ $location->name }}</summary><div class="details-body"><form method="POST" action="{{ route('locations.update', $location) }}" class="form-stack">@csrf @method('PUT')
                <label>Location name<input name="name" value="{{ $location->name }}" required></label>
                <div class="form-grid"><label>Department<input name="department" value="{{ $location->department }}"></label><label>Laboratory<input name="laboratory" value="{{ $location->laboratory }}"></label></div>
                <div class="form-grid"><label>Room<input name="room" value="{{ $location->room }}"></label><label>Cupboard<input name="cupboard" value="{{ $location->cupboard }}"></label></div>
                <button class="button button-secondary button-small" type="submit">Update location</button>
            </form></div></details>
        @endforeach
    </div></section>
    <section class="section"><div class="section-head"><h2>Recent stock movements</h2></div>
        @if($movements->isEmpty())<div class="empty-state">No stock events recorded yet.</div>@else
        <div class="table-wrap"><table><thead><tr><th>When</th><th>Movement</th><th>Item / responsible user</th></tr></thead><tbody>@foreach($movements as $movement)<tr><td>{{ $movement->occurred_at->format('d M, H:i') }}</td><td><strong>{{ str($movement->type)->replace('_', ' ')->title() }}</strong><span class="table-sub">{{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->quantity }}</span></td><td>{{ $movement->item->name }}<span class="table-sub">{{ $movement->user->name }} · {{ $movement->reason }}</span></td></tr>@endforeach</tbody></table></div>@endif
    </section>
</div>
@endsection
