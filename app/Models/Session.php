<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'teacher_profile_id',
        'classroom_id',  // Add this line
        'start_time',
        'end_time',
        'link',
        'type',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacherProfile()
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    // Add this relationship
    public function classroom()
    {
        return $this->belongsTo(Room::class, 'classroom_id');
    }
}
