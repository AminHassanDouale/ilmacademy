<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PaymentPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'amount',
        'currency',
        'curriculum_id',
        'description',
        'is_active',
        'installments',
        'frequency',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
        'installments' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Payment plan types
    const TYPE_MONTHLY = 'monthly';
    const TYPE_QUARTERLY = 'quarterly';
    const TYPE_SEMI_ANNUAL = 'semi-annual';
    const TYPE_ANNUAL = 'annual';
    const TYPE_ONE_TIME = 'one-time';

    // Get all available types
    public static function getTypes(): array
    {
        return [
            self::TYPE_MONTHLY,
            self::TYPE_QUARTERLY,
            self::TYPE_SEMI_ANNUAL,
            self::TYPE_ANNUAL,
            self::TYPE_ONE_TIME,
        ];
    }

    /**
     * Get the curriculum associated with this payment plan.
     */
    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * Get the program enrollments using this payment plan.
     */
    public function programEnrollments()
    {
        return $this->hasMany(ProgramEnrollment::class);
    }

    /**
     * Get the invoices associated with this payment plan.
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Scope to get only active payment plans.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by curriculum.
     */
    public function scopeForCurriculum(Builder $query, $curriculumId): Builder
    {
        if ($curriculumId) {
            return $query->where('curriculum_id', $curriculumId);
        }
        return $query;
    }

    /**
     * Scope to filter by type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get general plans (not tied to curriculum).
     */
    public function scopeGeneral(Builder $query): Builder
    {
        return $query->whereNull('curriculum_id');
    }

    /**
     * Scope to get curriculum-specific plans.
     */
    public function scopeCurriculumSpecific(Builder $query): Builder
    {
        return $query->whereNotNull('curriculum_id');
    }

    /**
     * Get formatted amount with currency symbol.
     */
    public function getFormattedAmountAttribute(): string
    {
        $symbol = match($this->currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $this->currency . ' '
        };

        return $symbol . number_format($this->amount, 2);
    }

    /**
     * Get the total amount if installments are involved.
     */
    public function getTotalAmountAttribute(): string
    {
        $total = $this->amount * ($this->installments ?? 1);

        $symbol = match($this->currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $this->currency . ' '
        };

        return $symbol . number_format($total, 2);
    }

    /**
     * Check if this is a recurring payment plan.
     */
    public function isRecurring(): bool
    {
        return in_array($this->type, [
            self::TYPE_MONTHLY,
            self::TYPE_QUARTERLY,
            self::TYPE_SEMI_ANNUAL,
            self::TYPE_ANNUAL
        ]);
    }

    /**
     * Check if this is a one-time payment.
     */
    public function isOneTime(): bool
    {
        return $this->type === self::TYPE_ONE_TIME;
    }

    /**
     * Get the display name for the payment plan.
     */
    public function getDisplayNameAttribute(): string
    {
        $name = $this->name ?? ucfirst($this->type) . ' Plan';
        return $name . ' - ' . $this->formatted_amount;
    }

    /**
     * Get a short display name for dropdowns.
     */
    public function getShortDisplayNameAttribute(): string
    {
        $name = $this->name ?? ucfirst($this->type);
        return $name;
    }

    /**
     * Get human-readable frequency text.
     */
    public function getFrequencyTextAttribute(): string
    {
        return match($this->frequency) {
            'monthly' => 'Every month',
            'quarterly' => 'Every 3 months',
            'semi-annual' => 'Every 6 months',
            'annual' => 'Once per year',
            'one-time' => 'One-time payment',
            default => ucfirst($this->frequency ?? 'Unknown')
        };
    }

    /**
     * Get the next payment date based on a start date.
     */
    public function getNextPaymentDate(\Carbon\Carbon $startDate, int $paymentNumber = 1): ?\Carbon\Carbon
    {
        if ($this->isOneTime()) {
            return $paymentNumber === 1 ? $startDate : null;
        }

        return match($this->frequency) {
            'monthly' => $startDate->copy()->addMonths($paymentNumber - 1),
            'quarterly' => $startDate->copy()->addMonths(($paymentNumber - 1) * 3),
            'semi-annual' => $startDate->copy()->addMonths(($paymentNumber - 1) * 6),
            'annual' => $startDate->copy()->addYears($paymentNumber - 1),
            default => null
        };
    }

    /**
     * Calculate savings compared to monthly plan.
     */
    public function getSavingsComparedToMonthly(?float $monthlyAmount = null): array
    {
        if (!$monthlyAmount || $this->type === self::TYPE_MONTHLY) {
            return ['amount' => 0, 'percentage' => 0];
        }

        $totalIfMonthly = $monthlyAmount * 12; // Annual equivalent
        $thisTotal = $this->amount * ($this->installments ?? 1);

        $savings = $totalIfMonthly - $thisTotal;
        $percentage = $totalIfMonthly > 0 ? ($savings / $totalIfMonthly) * 100 : 0;

        return [
            'amount' => max(0, $savings),
            'percentage' => max(0, round($percentage, 1))
        ];
    }

    /**
     * Override the type attribute for better display.
     */
    public function getTypeAttribute($value): string
    {
        return $value;
    }

    /**
     * Get nice type name for display.
     */
    public function getTypeDisplayAttribute(): string
    {
        return match($this->type) {
            self::TYPE_MONTHLY => 'Monthly',
            self::TYPE_QUARTERLY => 'Quarterly',
            self::TYPE_SEMI_ANNUAL => 'Semi-Annual',
            self::TYPE_ANNUAL => 'Annual',
            self::TYPE_ONE_TIME => 'One-time',
            default => ucfirst($this->type)
        };
    }
}
