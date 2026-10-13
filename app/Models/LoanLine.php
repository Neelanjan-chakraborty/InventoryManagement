<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanLine extends Model
{
    use HasFactory;

    protected $fillable = ['loan_id', 'item_id', 'asset_id', 'quantity', 'outstanding_quantity'];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function returns()
    {
        return $this->hasMany(ItemReturn::class);
    }
}
