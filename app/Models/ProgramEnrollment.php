<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProgramEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'child_profile_id',
        'curriculum_id',
        'academic_year_id',
        'status',
        'payment_plan_id',
    ];

    public function childProfile()
    {
        return $this->belongsTo(ChildProfile::class);
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function paymentPlan()
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public function subjectEnrollments()
    {
        return $this->hasMany(SubjectEnrollment::class);
    }

    // Option 1: Direct relationship if program_enrollment_id exists in invoices table
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    // Option 2: Alternative relationship through child_profile_id and academic_year_id
    public function getInvoicesViaStudentAttribute()
    {
        if (!class_exists(Invoice::class)) {
            return collect();
        }

        // If direct relationship exists, use it
        try {
            return $this->invoices;
        } catch (\Exception $e) {
            // Fallback: Get invoices by student and academic year
            return Invoice::where('child_profile_id', $this->child_profile_id)
                         ->where('academic_year_id', $this->academic_year_id)
                         ->get();
        }
    }

    // Helper method to safely get invoices count
    public function getInvoicesCountAttribute()
    {
        try {
            return $this->invoices()->count();
        } catch (\Exception $e) {
            if (!class_exists(Invoice::class)) {
                return 0;
            }

            // Fallback count
            return Invoice::where('child_profile_id', $this->child_profile_id)
                         ->where('academic_year_id', $this->academic_year_id)
                         ->count();
        }
    }

    // Helper method to safely check if invoices exist
    public function hasInvoices()
    {
        try {
            return $this->invoices()->exists();
        } catch (\Exception $e) {
            if (!class_exists(Invoice::class)) {
                return false;
            }

            // Fallback check
            return Invoice::where('child_profile_id', $this->child_profile_id)
                         ->where('academic_year_id', $this->academic_year_id)
                         ->exists();
        }
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }

    public function scopeForStudent($query, $studentId)
    {
        return $query->where('child_profile_id', $studentId);
    }

    public function scopeForCurriculum($query, $curriculumId)
    {
        return $query->where('curriculum_id', $curriculumId);
    }

    public function scopeForAcademicYear($query, $academicYearId)
    {
        return $query->where('academic_year_id', $academicYearId);
    }
}
