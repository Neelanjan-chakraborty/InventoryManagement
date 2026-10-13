<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StorageLocation extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'department', 'laboratory', 'room', 'cupboard', 'active'];

    public function items()
    {
        return $this->hasMany(InventoryItem::class, 'location_id');
    }

    public function assets()
    {
        return $this->hasMany(Asset::class, 'location_id');
    }
}
