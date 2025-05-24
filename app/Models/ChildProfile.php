<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChildProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'email',
        'phone',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'parent_id',
        'user_id',
        'medical_conditions',
        'allergies',
        'notes',
        'medical_information', // Legacy field
        'special_needs',
        'additional_needs',
        'photo',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the full name attribute.
     */
    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: 'Unknown Student';
    }

    /**
     * Get the initials for avatar display
     */
    public function getInitialsAttribute(): string
    {
        $firstName = $this->first_name ?? '';
        $lastName = $this->last_name ?? '';

        $firstInitial = $firstName ? strtoupper(substr($firstName, 0, 1)) : '?';
        $lastInitial = $lastName ? strtoupper(substr($lastName, 0, 1)) : '?';

        return $firstInitial . $lastInitial;
    }

    /**
     * Get the age based on date of birth
     */
    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth ? $this->date_of_birth->age : null;
    }

    /**
     * Relationships
     */
    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function programEnrollments()
    {
        return $this->hasMany(ProgramEnrollment::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    public function scopeByAge($query, $minAge = null, $maxAge = null)
    {
        if ($minAge !== null) {
            $query->whereDate('date_of_birth', '<=', now()->subYears($minAge));
        }

        if ($maxAge !== null) {
            $query->whereDate('date_of_birth', '>=', now()->subYears($maxAge + 1));
        }

        return $query;
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });
    }
}
