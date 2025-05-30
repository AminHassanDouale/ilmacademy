<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'address',
        'emergency_contact',
        'occupation',
        'company',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the parent profile.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the children for this parent.
     */
    public function children()
    {
        return $this->hasMany(ChildProfile::class, 'parent_id', 'user_id');
    }

    /**
     * Alternative relationship if using parent_profile_id
     */
    public function childProfiles()
    {
        return $this->hasMany(ChildProfile::class, 'parent_profile_id');
    }

    /**
     * Get program enrollments through children
     */
    public function programEnrollments()
    {
        return $this->hasManyThrough(
            ProgramEnrollment::class,
            ChildProfile::class,
            'parent_id', // Foreign key on child_profiles table
            'child_profile_id', // Foreign key on program_enrollments table
            'user_id', // Local key on parent_profiles table
            'id' // Local key on child_profiles table
        );
    }

    /**
     * Get invoices through children
     */
    public function invoices()
    {
        return $this->hasManyThrough(
            Invoice::class,
            ChildProfile::class,
            'parent_id',
            'child_profile_id',
            'user_id',
            'id'
        );
    }

    /**
     * Scope to search parents
     */
    public function scopeSearch($query, $search)
    {
        return $query->whereHas('user', function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        })->orWhere('phone', 'like', "%{$search}%");
    }

    /**
     * Get the parent's full contact info
     */
    public function getFullContactAttribute()
    {
        $contact = $this->user->name ?? 'Unknown Parent';
        if ($this->phone) {
            $contact .= ' (' . $this->phone . ')';
        }
        return $contact;
    }
}
