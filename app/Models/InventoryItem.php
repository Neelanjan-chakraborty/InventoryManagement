<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'category', 'description', 'make_model', 'tracking_type',
        'total_quantity', 'damaged_quantity', 'lost_quantity', 'maintenance_quantity',
        'max_per_issue', 'max_renewals', 'location_id', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function location()
    {
        return $this->belongsTo(StorageLocation::class, 'location_id');
    }

    public function assets()
    {
        return $this->hasMany(Asset::class, 'item_id');
    }

    public function loanLines()
    {
        return $this->hasMany(LoanLine::class, 'item_id');
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class, 'item_id');
    }

    public function availableQuantity(): int
    {
        if (! $this->active) {
            return 0;
        }

        if ($this->tracking_type === 'asset') {
            return $this->assets()->where('status', 'available')->where('condition', 'usable')->count();
        }

        $issued = $this->loanLines()
            ->where('outstanding_quantity', '>', 0)
            ->whereHas('loan', fn ($query) => $query->where('status', 'active'))
            ->sum('outstanding_quantity');

        $unavailable = $this->damaged_quantity + $this->lost_quantity + $this->maintenance_quantity;

        return max(0, $this->total_quantity - $unavailable - $issued);
    }

    public function conditionQuantity(string $condition): int
    {
        if ($this->tracking_type === 'asset') {
            return $this->assets()->where('condition', $condition)->where('status', '!=', 'retired')->count();
        }

        return match ($condition) {
            'damaged' => $this->damaged_quantity,
            'lost' => $this->lost_quantity,
            'maintenance' => $this->maintenance_quantity,
            default => 0,
        };
    }
}
