<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Curriculum extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function programEnrollments()
    {
        return $this->hasMany(ProgramEnrollment::class);
    }

    public function paymentPlans()
    {
        return $this->hasMany(PaymentPlan::class);
    }

    /**
     * Scope to search curricula by name or code
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('code', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%");
        });
    }

    /**
     * Scope to get active curricula
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }
    // In your App\Models\Curriculum.php file
public function academicYears()
{
    return $this->belongsToMany(AcademicYear::class, 'curriculum_academic_year');
    // Adjust the pivot table name if it's different in your database
}
}
