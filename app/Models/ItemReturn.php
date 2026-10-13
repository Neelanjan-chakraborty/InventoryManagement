<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemReturn extends Model
{
    use HasFactory;

    protected $fillable = ['loan_line_id', 'quantity', 'condition', 'received_by', 'received_at', 'remarks'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'quantity' => 'integer'];
    }

    public function line()
    {
        return $this->belongsTo(LoanLine::class, 'loan_line_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
