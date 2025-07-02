<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubjectEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'program_enrollment_id',
        'subject_id',
    ];

    public function programEnrollment()
    {
        return $this->belongsTo(ProgramEnrollment::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    // Add this method to access child profile through program enrollment
    public function childProfile()
    {
        return $this->hasOneThrough(
            ChildProfile::class,
            ProgramEnrollment::class,
            'id', // Foreign key on program_enrollments table
            'id', // Foreign key on child_profiles table
            'program_enrollment_id', // Local key on subject_enrollments table
            'child_profile_id' // Local key on program_enrollments table
        );
    }

    // Accessor to get student name easily
    public function getStudentNameAttribute(): string
    {
        return $this->programEnrollment?->childProfile?->full_name ?? 'Unknown Student';
    }

    // Accessor to get subject name easily
    public function getSubjectNameAttribute(): string
    {
        return $this->subject?->name ?? 'Unknown Subject';
    }

    // Accessor to get academic year name easily
    public function getAcademicYearNameAttribute(): string
    {
        return $this->programEnrollment?->academicYear?->name ?? 'Unknown Academic Year';
    }

    // Scope to filter by academic year
    public function scopeByAcademicYear($query, $academicYearId)
    {
        return $query->whereHas('programEnrollment.academicYear', function ($q) use ($academicYearId) {
            $q->where('id', $academicYearId);
        });
    }

    // Scope to filter by subject
    public function scopeBySubject($query, $subjectId)
    {
        return $query->where('subject_id', $subjectId);
    }

    // Scope to search by student or subject
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->whereHas('programEnrollment.childProfile', function ($childQuery) use ($search) {
                $childQuery->where('first_name', 'like', "%{$search}%")
                          ->orWhere('last_name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%");
            })
            ->orWhereHas('subject', function ($subjectQuery) use ($search) {
                $subjectQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
            });
        });
    }
}
