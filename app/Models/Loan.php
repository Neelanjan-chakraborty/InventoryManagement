<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'issued_by', 'issue_date', 'due_date', 'purpose', 'status'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date'];
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function lines()
    {
        return $this->hasMany(LoanLine::class);
    }

    public function renewals()
    {
        return $this->hasMany(RenewalRequest::class);
    }
}
