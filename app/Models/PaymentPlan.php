<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'type',
        'amount',
        'currency',
        'installments',
        'frequency',
        'due_day',
        'curriculum_id',
        'is_active',
        'is_default',
        'auto_generate_invoices',
        'setup_fee',
        'discount_percentage',
        'discount_amount',
        'discount_valid_until',
        'grace_period_days',
        'late_fee_amount',
        'late_fee_percentage',
        'terms_and_conditions',
        'payment_instructions',
        'accepted_payment_methods',
        'metadata',
        'internal_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_valid_until' => 'date',
        'late_fee_amount' => 'decimal:2',
        'late_fee_percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'auto_generate_invoices' => 'boolean',
        'accepted_payment_methods' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Payment plan types
    const TYPE_MONTHLY = 'monthly';
    const TYPE_QUARTERLY = 'quarterly';
    const TYPE_SEMI_ANNUAL = 'semi-annual';
    const TYPE_ANNUAL = 'annual';
    const TYPE_ONE_TIME = 'one-time';

    // Default payment methods
    const PAYMENT_METHODS = [
        'bank_transfer' => 'Bank Transfer',
        'credit_card' => 'Credit Card',
        'debit_card' => 'Debit Card',
        'cash' => 'Cash',
        'check' => 'Check',
        'online_payment' => 'Online Payment',
        'mobile_payment' => 'Mobile Payment',
    ];

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->code) {
                $model->code = static::generateCode($model->name, $model->type);
            }
            if (!$model->frequency) {
                $model->frequency = $model->type;
            }
            if (!$model->accepted_payment_methods) {
                $model->accepted_payment_methods = array_keys(self::PAYMENT_METHODS);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('type') && !$model->isDirty('frequency')) {
                $model->frequency = $model->type;
            }
        });
    }

    /**
     * Relationships
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function programEnrollments(): HasMany
    {
        return $this->hasMany(ProgramEnrollment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scopes
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    public function scopeForCurriculum(Builder $query, $curriculumId): Builder
    {
        if ($curriculumId) {
            return $query->where('curriculum_id', $curriculumId);
        }
        return $query;
    }

    public function scopeGeneral(Builder $query): Builder
    {
        return $query->whereNull('curriculum_id');
    }

    public function scopeCurriculumSpecific(Builder $query): Builder
    {
        return $query->whereNotNull('curriculum_id');
    }

    public function scopeWithActiveDiscount(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNotNull('discount_percentage')
              ->orWhereNotNull('discount_amount');
        })->where(function ($q) {
            $q->whereNull('discount_valid_until')
              ->orWhere('discount_valid_until', '>=', now());
        });
    }

    /**
     * Attribute Accessors
     */
    public function getFormattedAmountAttribute(): string
    {
        return $this->formatCurrency($this->amount);
    }

    public function getTotalAmountAttribute(): float
    {
        return $this->amount * $this->installments;
    }

    public function getFormattedTotalAmountAttribute(): string
    {
        return $this->formatCurrency($this->total_amount);
    }

    public function getDisplayNameAttribute(): string
    {
        $name = $this->name;
        if ($this->curriculum) {
            $name = "{$this->curriculum->name} - {$name}";
        }
        return $name . ' (' . $this->formatted_amount . ')';
    }

    public function getShortDisplayNameAttribute(): string
    {
        return $this->name . ' (' . $this->formatted_amount . ')';
    }

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

    public function getCurrentDiscountAmountAttribute(): ?float
    {
        if (!$this->hasActiveDiscount()) {
            return null;
        }

        if ($this->discount_amount) {
            return $this->discount_amount;
        }

        if ($this->discount_percentage) {
            return ($this->amount * $this->discount_percentage) / 100;
        }

        return null;
    }

    public function getDiscountedAmountAttribute(): float
    {
        $discount = $this->current_discount_amount;
        return $discount ? $this->amount - $discount : $this->amount;
    }

    public function getFormattedDiscountedAmountAttribute(): string
    {
        return $this->formatCurrency($this->discounted_amount);
    }

    /**
     * Helper Methods
     */
    public function formatCurrency(float $amount): string
    {
        $symbol = match($this->currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $this->currency . ' '
        };

        return $symbol . number_format($amount, 2);
    }

    public function isRecurring(): bool
    {
        return in_array($this->type, [
            self::TYPE_MONTHLY,
            self::TYPE_QUARTERLY,
            self::TYPE_SEMI_ANNUAL,
            self::TYPE_ANNUAL
        ]);
    }

    public function isOneTime(): bool
    {
        return $this->type === self::TYPE_ONE_TIME;
    }

    public function hasActiveDiscount(): bool
    {
        if (!$this->discount_percentage && !$this->discount_amount) {
            return false;
        }

        if ($this->discount_valid_until && $this->discount_valid_until->isPast()) {
            return false;
        }

        return true;
    }

    public function getNextPaymentDate(Carbon $startDate, int $paymentNumber = 1): ?Carbon
    {
        if ($this->isOneTime()) {
            return $paymentNumber === 1 ? $startDate->copy() : null;
        }

        $nextDate = $startDate->copy();

        return match($this->frequency) {
            'monthly' => $nextDate->addMonths($paymentNumber - 1),
            'quarterly' => $nextDate->addMonths(($paymentNumber - 1) * 3),
            'semi-annual' => $nextDate->addMonths(($paymentNumber - 1) * 6),
            'annual' => $nextDate->addYears($paymentNumber - 1),
            default => null
        };
    }

    public function calculatePaymentSchedule(Carbon $startDate): array
    {
        $schedule = [];

        for ($i = 1; $i <= $this->installments; $i++) {
            $dueDate = $this->getNextPaymentDate($startDate, $i);

            if (!$dueDate) {
                break;
            }

            // Adjust to specific due day if set
            if ($this->due_day && $this->due_day <= $dueDate->daysInMonth) {
                $dueDate->day = $this->due_day;
            }

            $amount = $this->hasActiveDiscount() ? $this->discounted_amount : $this->amount;

            // Add setup fee to first payment
            if ($i === 1 && $this->setup_fee) {
                $amount += $this->setup_fee;
            }

            $schedule[] = [
                'installment_number' => $i,
                'due_date' => $dueDate,
                'amount' => $amount,
                'formatted_amount' => $this->formatCurrency($amount),
                'description' => "Payment {$i} of {$this->installments}",
            ];
        }

        return $schedule;
    }

    public function getSavingsComparedToMonthly(?float $monthlyAmount = null): array
    {
        if (!$monthlyAmount || $this->type === self::TYPE_MONTHLY) {
            return ['amount' => 0, 'percentage' => 0];
        }

        $totalIfMonthly = $monthlyAmount * 12; // Annual equivalent
        $thisTotal = $this->total_amount;

        $savings = $totalIfMonthly - $thisTotal;
        $percentage = $totalIfMonthly > 0 ? ($savings / $totalIfMonthly) * 100 : 0;

        return [
            'amount' => max(0, $savings),
            'percentage' => max(0, round($percentage, 1))
        ];
    }

    public function canBeUsedForCurriculum(?int $curriculumId): bool
    {
        // General plans can be used for any curriculum
        if (!$this->curriculum_id) {
            return true;
        }

        // Specific plans can only be used for their curriculum
        return $this->curriculum_id === $curriculumId;
    }

    /**
     * Static Methods
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_MONTHLY => 'Monthly',
            self::TYPE_QUARTERLY => 'Quarterly',
            self::TYPE_SEMI_ANNUAL => 'Semi-Annual',
            self::TYPE_ANNUAL => 'Annual',
            self::TYPE_ONE_TIME => 'One-time',
        ];
    }

    public static function getPaymentMethods(): array
    {
        return self::PAYMENT_METHODS;
    }

    public static function getDefaultInstallments(string $type): int
    {
        return match($type) {
            self::TYPE_MONTHLY => 12,
            self::TYPE_QUARTERLY => 4,
            self::TYPE_SEMI_ANNUAL => 2,
            self::TYPE_ANNUAL => 1,
            self::TYPE_ONE_TIME => 1,
            default => 1
        };
    }

    public static function generateCode(string $name, string $type): string
    {
        $nameCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 4));
        $typeCode = strtoupper(substr($type, 0, 2));
        $randomCode = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 3));

        $code = $nameCode . $typeCode . $randomCode;

        // Ensure uniqueness
        $counter = 1;
        $originalCode = $code;

        while (static::where('code', $code)->exists()) {
            $code = $originalCode . $counter;
            $counter++;
        }

        return $code;
    }

    public static function getDefaultForCurriculum(?int $curriculumId): ?self
    {
        return static::active()
            ->where('curriculum_id', $curriculumId)
            ->where('is_default', true)
            ->first() ?? static::active()
            ->whereNull('curriculum_id')
            ->where('is_default', true)
            ->first();
    }
}