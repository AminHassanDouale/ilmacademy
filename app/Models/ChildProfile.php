<?php
// App/Models/ChildProfile.php
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
        'parent_id', // User ID of the parent
        'parent_profile_id', // Direct relationship to ParentProfile (optional)
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
     * Basic Relationships
     */

    // Relationship to the parent user
    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    // Relationship to ParentProfile through parent_id -> user_id
    public function parentProfile()
    {
        return $this->hasOne(ParentProfile::class, 'user_id', 'parent_id');
    }

    // Alternative: Direct relationship to ParentProfile (if using parent_profile_id)
    public function directParentProfile()
    {
        return $this->belongsTo(ParentProfile::class, 'parent_profile_id');
    }

    // Child's own user account (if they have one)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Academic Relationships
     */
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

    public function currentEnrollment()
    {
        return $this->hasOne(ProgramEnrollment::class)
            ->whereHas('academicYear', function($query) {
                $query->where('is_current', true);
            })
            ->where('status', 'Active');
    }

    public function curricula()
    {
        return $this->hasManyThrough(
            Curriculum::class,
            ProgramEnrollment::class,
            'child_profile_id', // Foreign key on program_enrollments
            'id',               // Foreign key on curricula
            'id',               // Local key on child_profiles
            'curriculum_id'     // Local key on program_enrollments
        );
    }

    public function currentCurriculum()
    {
        return $this->hasOneThrough(
            Curriculum::class,
            ProgramEnrollment::class,
            'child_profile_id', // Foreign key on program_enrollments
            'id',               // Foreign key on curricula
            'id',               // Local key on child_profiles
            'curriculum_id'     // Local key on program_enrollments
        )->whereHas('programEnrollments', function($query) {
            $query->where('child_profile_id', $this->id)
                  ->whereHas('academicYear', function($q) {
                      $q->where('is_current', true);
                  })
                  ->where('status', 'Active');
        });
    }

    public function subjects()
    {
        return $this->hasManyThrough(
            Subject::class,
            ProgramEnrollment::class,
            'child_profile_id', // Foreign key on program_enrollments
            'curriculum_id',    // Foreign key on subjects
            'id',               // Local key on child_profiles
            'curriculum_id'     // Local key on program_enrollments
        );
    }

    public function currentSubjects()
    {
        return $this->subjects()
            ->whereHas('curriculum.programEnrollments', function($query) {
                $query->where('child_profile_id', $this->id)
                      ->whereHas('academicYear', function($q) {
                          $q->where('is_current', true);
                      })
                      ->where('status', 'Active');
            });
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Teacher Relationships
     */

    // Get all teachers who teach this student
    public function teachers()
    {
        return TeacherProfile::whereHas('timetableSlots.subject.curriculum.programEnrollments', function($query) {
            $query->where('child_profile_id', $this->id)
                  ->where('status', 'Active')
                  ->whereHas('academicYear', function($yearQuery) {
                      $yearQuery->where('is_current', true);
                  });
        });
    }

    // Get current teachers only
    public function currentTeachers()
    {
        return $this->teachers(); // Same as above, kept for clarity
    }

    // Check if student is taught by a specific teacher
    public function isTaughtByTeacher($teacherProfileId)
    {
        return $this->teachers()
            ->where('teacher_profiles.id', $teacherProfileId)
            ->exists();
    }

    /**
     * Helper Methods
     */
    public function subjectsByTeacher($teacherProfileId)
    {
        return $this->currentSubjects()
            ->whereHas('timetableSlots', function($query) use ($teacherProfileId) {
                $query->where('teacher_profile_id', $teacherProfileId);
            });
    }

    public function isEnrolledInCurriculum($curriculumId)
    {
        return $this->programEnrollments()
            ->where('curriculum_id', $curriculumId)
            ->where('status', 'Active')
            ->whereHas('academicYear', function($query) {
                $query->where('is_current', true);
            })
            ->exists();
    }

    public function hasTimetableWithTeacher($teacherProfileId)
    {
        return $this->currentSubjects()
            ->whereHas('timetableSlots', function($query) use ($teacherProfileId) {
                $query->where('teacher_profile_id', $teacherProfileId);
            })
            ->exists();
    }

    /**
     * Get parent contact information
     */
    public function getParentContactAttribute()
    {
        $parentProfile = $this->parentProfile;
        if ($parentProfile) {
            return $parentProfile->full_contact;
        }

        $parent = $this->parent;
        if ($parent) {
            return $parent->name . ($parent->email ? ' (' . $parent->email . ')' : '');
        }

        return 'No parent contact';
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
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhereHas('parent', function($parentQuery) use ($search) {
                  $parentQuery->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
              })
              ->orWhereHas('parentProfile', function($profileQuery) use ($search) {
                  $profileQuery->where('phone', 'like', "%{$search}%");
              });
        });
    }

    public function scopeByTeacher($query, $teacherProfileId)
    {
        return $query->whereHas('programEnrollments', function($enrollmentQuery) use ($teacherProfileId) {
            $enrollmentQuery->where('status', 'Active')
                ->whereHas('academicYear', function($yearQuery) {
                    $yearQuery->where('is_current', true);
                })
                ->whereHas('curriculum.subjects.timetableSlots', function($slotQuery) use ($teacherProfileId) {
                    $slotQuery->where('teacher_profile_id', $teacherProfileId);
                });
        });
    }

    public function scopeBySubject($query, $subjectId)
    {
        return $query->whereHas('programEnrollments', function($enrollmentQuery) use ($subjectId) {
            $enrollmentQuery->where('status', 'Active')
                ->whereHas('academicYear', function($yearQuery) {
                    $yearQuery->where('is_current', true);
                })
                ->whereHas('curriculum.subjects', function($subjectQuery) use ($subjectId) {
                    $subjectQuery->where('subjects.id', $subjectId);
                });
        });
    }

    public function scopeByCurriculum($query, $curriculumId)
    {
        return $query->whereHas('programEnrollments', function($enrollmentQuery) use ($curriculumId) {
            $enrollmentQuery->where('curriculum_id', $curriculumId)
                ->where('status', 'Active')
                ->whereHas('academicYear', function($yearQuery) {
                    $yearQuery->where('is_current', true);
                });
        });
    }

    public function scopeCurrentlyEnrolled($query)
    {
        return $query->whereHas('programEnrollments', function($enrollmentQuery) {
            $enrollmentQuery->where('status', 'Active')
                ->whereHas('academicYear', function($yearQuery) {
                    $yearQuery->where('is_current', true);
                });
        });
    }

    public function scopeByParent($query, $parentId)
    {
        return $query->where('parent_id', $parentId);
    }
}
