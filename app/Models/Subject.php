<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'curriculum_id',
        'name',
        'code',
        'level',
    ];

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
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

    public function subjectEnrollments()
    {
        return $this->hasMany(SubjectEnrollment::class);
    }

    /**
     * The teachers who teach this subject.
     */
    public function teachers()
    {
        return $this->belongsToMany(TeacherProfile::class, 'teacher_subject', 'subject_id', 'teacher_profile_id')
                    ->withTimestamps();
    }
}
