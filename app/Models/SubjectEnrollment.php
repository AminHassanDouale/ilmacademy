<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubjectEnrollment extends Model
{
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
}
