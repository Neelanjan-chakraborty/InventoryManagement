<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\StorageLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index()
    {
        return view('inventory.index', [
            'items' => InventoryItem::query()->with('location', 'assets.location')->orderBy('name')->paginate(25),
            'locations' => StorageLocation::query()->where('active', true)->orderBy('name')->get(),
            'movements' => InventoryMovement::query()->with('item', 'user')->latest('occurred_at')->limit(12)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'unique:inventory_items,code'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'make_model' => ['nullable', 'string', 'max:160'],
            'tracking_type' => ['required', Rule::in(['bulk', 'asset'])],
            'total_quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'location_id' => ['nullable', 'exists:storage_locations,id'],
            'max_per_issue' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_renewals' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        if ($data['tracking_type'] === 'asset' && (int) $data['total_quantity'] !== 0) {
            throw ValidationException::withMessages(['total_quantity' => 'Add individually tracked equipment through the asset register.']);
        }

        DB::transaction(function () use ($data, $request) {
            $item = InventoryItem::create($data);
            if ($item->total_quantity > 0) {
                $this->movement($item, $request, 'initial_stock', $item->total_quantity, 'Opening stock');
            }
        });

        return back()->with('success', 'Inventory item created.');
    }

    public function update(Request $request, InventoryItem $item)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'make_model' => ['nullable', 'string', 'max:160'],
            'location_id' => ['nullable', 'exists:storage_locations,id'],
            'max_per_issue' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_renewals' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        $item->update($data);

        return back()->with('success', 'Inventory details and item policy updated.');
    }

    public function storeAsset(Request $request, InventoryItem $item)
    {
        abort_unless($item->tracking_type === 'asset', 422);
        $data = $request->validate([
            'asset_tag' => ['required', 'string', 'max:100', 'unique:assets,asset_tag'],
            'serial_number' => ['nullable', 'string', 'max:160', 'unique:assets,serial_number'],
            'location_id' => ['nullable', 'exists:storage_locations,id'],
        ]);

        DB::transaction(function () use ($item, $data, $request) {
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $asset = $item->assets()->create($data + ['condition' => 'usable', 'status' => 'available']);
            $item->increment('total_quantity');
            $this->movement($item, $request, 'asset_added', 1, 'Asset '.$asset->asset_tag.' registered', $asset);
        });

        return back()->with('success', 'Asset registered and made available.');
    }

    public function updateAsset(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'condition' => ['required', Rule::in(['usable', 'damaged', 'lost', 'maintenance'])],
            'status' => ['required', Rule::in(['available', 'damaged', 'lost', 'maintenance', 'retired'])],
            'location_id' => ['nullable', 'exists:storage_locations,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $expectedStatus = $data['condition'] === 'usable' ? 'available' : $data['condition'];
        if ($data['status'] !== 'retired' && $data['status'] !== $expectedStatus) {
            throw ValidationException::withMessages(['status' => 'Usable assets must be available; other conditions must remain unavailable.']);
        }

        DB::transaction(function () use ($asset, $data, $request) {
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($asset->status === 'issued') {
                throw ValidationException::withMessages(['asset' => 'An issued asset can only change condition when it is returned.']);
            }
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($asset->item_id);
            $previousStatus = $asset->status;
            $asset->update([
                'condition' => $data['condition'],
                'status' => $data['status'],
                'location_id' => $data['location_id'] ?? null,
            ]);
            if ($previousStatus !== 'retired' && $data['status'] === 'retired') {
                $item->decrement('total_quantity');
            } elseif ($previousStatus === 'retired' && $data['status'] !== 'retired') {
                $item->increment('total_quantity');
            }
            $this->movement($item, $request, 'asset_status_changed', 0, $data['reason'], $asset);
        });

        return back()->with('success', 'Asset condition and availability updated.');
    }

    public function setActive(Request $request, InventoryItem $item)
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($item, $data, $request) {
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $active = (bool) $data['active'];
            if ($item->active === $active) {
                throw ValidationException::withMessages(['active' => 'The inventory item already has that status.']);
            }
            $item->update(['active' => $active]);
            $this->movement($item, $request, $active ? 'item_reactivated' : 'item_retired', 0, $data['reason']);
        });

        return back()->with('success', $data['active'] ? 'Inventory item reactivated.' : 'Inventory item retired from new issues.');
    }

    public function adjust(Request $request, InventoryItem $item)
    {
        $data = $request->validate([
            'adjustment' => ['required', Rule::in(['add', 'remove', 'restore_damaged', 'restore_lost', 'restore_maintenance'])],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($item->tracking_type !== 'bulk') {
            throw ValidationException::withMessages(['item' => 'Use the asset register to change individually tracked stock.']);
        }

        DB::transaction(function () use ($item, $data, $request) {
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $quantity = $data['quantity'];
            $delta = 0;

            if ($data['adjustment'] === 'add') {
                $item->increment('total_quantity', $quantity);
                $delta = $quantity;
            } elseif ($data['adjustment'] === 'remove') {
                if ($quantity > $item->availableQuantity()) {
                    throw ValidationException::withMessages(['quantity' => 'Stock corrections cannot remove issued or unavailable units.']);
                }
                $item->decrement('total_quantity', $quantity);
                $delta = -$quantity;
            } else {
                $column = match ($data['adjustment']) {
                    'restore_damaged' => 'damaged_quantity',
                    'restore_lost' => 'lost_quantity',
                    'restore_maintenance' => 'maintenance_quantity',
                };
                if ($quantity > $item->{$column}) {
                    throw ValidationException::withMessages(['quantity' => 'The recovery quantity exceeds the recorded unavailable amount.']);
                }
                $item->decrement($column, $quantity);
            }

            $this->movement($item, $request, 'stock_'.$data['adjustment'], $delta, $data['reason']);
        });

        return back()->with('success', 'Stock adjustment recorded with its reason.');
    }

    public function storeLocation(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:storage_locations,name'],
            'department' => ['nullable', 'string', 'max:120'],
            'laboratory' => ['nullable', 'string', 'max:120'],
            'room' => ['nullable', 'string', 'max:80'],
            'cupboard' => ['nullable', 'string', 'max:80'],
        ]);
        StorageLocation::create($data);

        return back()->with('success', 'Storage location created.');
    }

    public function updateLocation(Request $request, StorageLocation $location)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('storage_locations', 'name')->ignore($location->id)],
            'department' => ['nullable', 'string', 'max:120'],
            'laboratory' => ['nullable', 'string', 'max:120'],
            'room' => ['nullable', 'string', 'max:80'],
            'cupboard' => ['nullable', 'string', 'max:80'],
        ]);
        $location->update($data);

        return back()->with('success', 'Storage location updated.');
    }

    public function export()
    {
        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Code', 'Item', 'Category', 'Tracking', 'Total', 'Available', 'Issued', 'Damaged', 'Lost', 'Maintenance', 'Location']);
            InventoryItem::query()->with('location')->orderBy('code')->chunk(200, function ($items) use ($output) {
                foreach ($items as $item) {
                    $issued = $item->loanLines()->where('outstanding_quantity', '>', 0)
                        ->whereHas('loan', fn ($query) => $query->where('status', 'active'))->sum('outstanding_quantity');
                    fputcsv($output, [
                        $item->code, $item->name, $item->category, $item->tracking_type,
                        $item->total_quantity, $item->availableQuantity(), $issued,
                        $item->conditionQuantity('damaged'), $item->conditionQuantity('lost'),
                        $item->conditionQuantity('maintenance'), $item->location?->name,
                    ]);
                }
            });
            fclose($output);
        }, 'department-inventory.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function movement(InventoryItem $item, Request $request, string $type, int $quantity, string $reason, ?Asset $asset = null): void
    {
        InventoryMovement::create([
            'item_id' => $item->id,
            'asset_id' => $asset?->id,
            'user_id' => $request->user()->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
