<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamResult extends Model
{
    protected $fillable = [
        'exam_id',
        'child_profile_id',
        'score',
        'remarks',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function childProfile()
    {
        return $this->belongsTo(ChildProfile::class);
    }
}