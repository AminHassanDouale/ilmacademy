<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'specialization',
        'phone',
        'employee_id',
        'department',
        'qualification',
        'experience_years',
        'date_joined',
        'status',
    ];

    protected $casts = [
        'date_joined' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Basic Relationships
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sessions()
    {
        return $this->hasMany(Session::class);
    }

    public function exams()
    {
        return $this->hasMany(Exam::class);
    }

    public function timetableSlots()
    {
        return $this->hasMany(TimetableSlot::class);
    }

    /**
     * Subject Relationships
     */
    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'teacher_subject', 'teacher_profile_id', 'subject_id')
                    ->withTimestamps();
    }

    public function assignedSubjects()
    {
        return $this->hasMany(Subject::class, 'primary_teacher_id'); // If subjects have a primary teacher
    }

    /**
     * Student Relationships through Teaching Assignments
     */

    // Get all students this teacher teaches (through timetable slots)
    public function students()
    {
        return $this->hasManyThrough(
            ChildProfile::class,
            ProgramEnrollment::class,
            'curriculum_id', // Foreign key on program_enrollments
            'id', // Foreign key on child_profiles
            'id', // Local key on teacher_profiles (through subjects)
            'child_profile_id' // Local key on program_enrollments
        )->join('subjects', 'program_enrollments.curriculum_id', '=', 'subjects.curriculum_id')
         ->join('timetable_slots', 'subjects.id', '=', 'timetable_slots.subject_id')
         ->where('timetable_slots.teacher_profile_id', $this->id)
         ->where('program_enrollments.status', 'Active')
         ->whereHas('programEnrollments.academicYear', function($query) {
             $query->where('is_current', true);
         })
         ->select('child_profiles.*')
         ->distinct();
    }

    // Alternative: More direct approach
    public function currentStudents()
    {
        return ChildProfile::whereHas('programEnrollments', function($enrollmentQuery) {
            $enrollmentQuery->where('status', 'Active')
                ->whereHas('academicYear', function($yearQuery) {
                    $yearQuery->where('is_current', true);
                })
                ->whereHas('curriculum.subjects.timetableSlots', function($slotQuery) {
                    $slotQuery->where('teacher_profile_id', $this->id);
                });
        });
    }

    // Get parents of students this teacher teaches
    public function studentParents()
    {
        $studentIds = $this->currentStudents()->pluck('id');

        return ClientProfile::whereHas('children', function($query) use ($studentIds) {
            $query->whereIn('id', $studentIds);
        });
    }

    // Alternative parent relationship through User
    public function parentUsers()
    {
        $parentIds = $this->currentStudents()->pluck('parent_id')->filter();

        return User::whereIn('id', $parentIds);
    }

    /**
     * Academic Relationships
     */

    // Get curricula this teacher is involved with
    public function curricula()
    {
        return Curriculum::whereHas('subjects.timetableSlots', function($query) {
            $query->where('teacher_profile_id', $this->id);
        });
    }

    // Get current academic year enrollments for this teacher's students
    public function currentEnrollments()
    {
        return ProgramEnrollment::whereHas('curriculum.subjects.timetableSlots', function($query) {
            $query->where('teacher_profile_id', $this->id);
        })->where('status', 'Active')
          ->whereHas('academicYear', function($yearQuery) {
              $yearQuery->where('is_current', true);
          });
    }

    // Get attendance records for this teacher's classes
    public function attendanceRecords()
    {
        return Attendance::whereHas('timetableSlot', function($query) {
            $query->where('teacher_profile_id', $this->id);
        });
    }

    /**
     * Helper Methods
     */

    // Get students for a specific subject
    public function studentsForSubject($subjectId)
    {
        return $this->currentStudents()
            ->whereHas('programEnrollments.curriculum.subjects', function($query) use ($subjectId) {
                $query->where('subjects.id', $subjectId);
            });
    }

    // Get students for a specific curriculum
    public function studentsForCurriculum($curriculumId)
    {
        return $this->currentStudents()
            ->whereHas('programEnrollments', function($query) use ($curriculumId) {
                $query->where('curriculum_id', $curriculumId);
            });
    }

    // Check if teacher teaches a specific student
    public function teachesStudent($studentId)
    {
        return $this->currentStudents()
            ->where('child_profiles.id', $studentId)
            ->exists();
    }

    // Get count of current students
    public function getCurrentStudentCountAttribute()
    {
        return $this->currentStudents()->count();
    }

    // Get count of subjects taught
    public function getSubjectCountAttribute()
    {
        return $this->subjects()->count();
    }

    // Get full name with title
    public function getFullNameAttribute()
    {
        return $this->user ? $this->user->name : 'Unknown Teacher';
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeWithStudents($query)
    {
        return $query->whereHas('timetableSlots.subject.curriculum.programEnrollments', function($enrollmentQuery) {
            $enrollmentQuery->where('status', 'Active')
                ->whereHas('academicYear', function($yearQuery) {
                    $yearQuery->where('is_current', true);
                });
        });
    }

    public function scopeBySubject($query, $subjectId)
    {
        return $query->whereHas('subjects', function($subjectQuery) use ($subjectId) {
            $subjectQuery->where('subjects.id', $subjectId);
        });
    }

    public function scopeByDepartment($query, $department)
    {
        return $query->where('department', $department);
    }

    public function scopeSearch($query, $search)
    {
        return $query->whereHas('user', function($userQuery) use ($search) {
            $userQuery->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
        })->orWhere('specialization', 'like', "%{$search}%")
          ->orWhere('department', 'like', "%{$search}%")
          ->orWhere('employee_id', 'like', "%{$search}%");
    }
}
