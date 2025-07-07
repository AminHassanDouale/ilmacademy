<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'capacity',
        'location',
        'description',
        'has_projector',
        'has_computers',
        'is_accessible',
        'floor',
        'building',
    ];

    protected $casts = [
        'has_projector' => 'boolean',
        'has_computers' => 'boolean',
        'is_accessible' => 'boolean',
    ];


    public function sessions()
{
    return $this->hasMany(Session::class, 'classroom_id');
}
}
