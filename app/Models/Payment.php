<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'amount',
        'payment_date',
        'due_date',
        'status',
        'description',
        'reference_number',
        'child_profile_id',
        'academic_year_id',
        'curriculum_id',
        'invoice_id',
        'created_by',
        'payment_method',
        'transaction_id',
        'notes'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'due_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Payment status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_OVERDUE = 'overdue';

    /**
     * Get the student associated with the payment.
     */
    public function student()
    {
        return $this->belongsTo(ChildProfile::class, 'child_profile_id');
    }

    /**
     * Get the academic year associated with the payment.
     */
    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the curriculum associated with the payment.
     */
    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * Get the invoice associated with the payment.
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the user who created the payment.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to only include payments with a specific status.
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include payments for a specific academic year.
     */
    public function scopeForAcademicYear($query, $academicYearId)
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    /**
     * Scope a query to only include payments for a specific curriculum.
     */
    public function scopeForCurriculum($query, $curriculumId)
    {
        return $query->where('curriculum_id', $curriculumId);
    }

    /**
     * Scope a query to only include payments for a specific student.
     */
    public function scopeForStudent($query, $studentId)
    {
        return $query->where('child_profile_id', $studentId);
    }

    /**
     * Scope a query to only include payments within a date range.
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('payment_date', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ]);
    }

    /**
     * Scope a query to only include overdue payments.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OVERDUE)
                    ->orWhere(function($q) {
                        $q->where('status', self::STATUS_PENDING)
                          ->where('due_date', '<', now());
                    });
    }

    /**
     * Check if the payment is overdue.
     */
    public function isOverdue()
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return false;
        }

        return $this->due_date && $this->due_date < now();
    }

    /**
     * Mark the payment as completed.
     */
    public function markAsCompleted()
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'payment_date' => now()
        ]);

        // Update the invoice if applicable
        if ($this->invoice) {
            $this->invoice->updatePaymentStatus();
        }
    }

    /**
     * Calculate the days overdue.
     */
    public function daysOverdue()
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return now()->diffInDays($this->due_date);
    }
}
