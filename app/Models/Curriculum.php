<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Curriculum extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
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

}
