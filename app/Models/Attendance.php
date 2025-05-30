<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'child_profile_id',
        'status',
        'remarks', // Changed from 'notes' to match your migration
    ];

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function childProfile()
    {
        return $this->belongsTo(ChildProfile::class);
    }
}
