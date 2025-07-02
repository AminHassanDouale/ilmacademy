<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'description',
        'type',
        'start_date',
        'end_date',
        'is_all_day',
        'location',
        'color',
        'created_by',
        'academic_year_id',
        'recurring',
        'recurring_pattern',
        'attendees',
        'max_attendees',
        'registration_required',
        'registration_deadline',
        'status',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_all_day' => 'boolean',
        'recurring' => 'boolean',
        'registration_required' => 'boolean',
        'registration_deadline' => 'datetime',
        'attendees' => 'array',
        'recurring_pattern' => 'array',
    ];

    /**
     * The attributes that should be hidden for arrays.
     */
    protected $hidden = [
        'notes',
    ];

    /**
     * Get the user who created this event.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the academic year this event belongs to.
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Scope to filter events by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter events by date range.
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate, $endDate])
              ->orWhereBetween('end_date', [$startDate, $endDate])
              ->orWhere(function ($subQuery) use ($startDate, $endDate) {
                  $subQuery->where('start_date', '<=', $startDate)
                           ->where('end_date', '>=', $endDate);
              });
        });
    }

    /**
     * Scope to filter upcoming events.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>=', now());
    }

    /**
     * Scope to filter past events.
     */
    public function scopePast($query)
    {
        return $query->where('end_date', '<', now());
    }

    /**
     * Scope to filter today's events.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('start_date', '<=', today())
                    ->whereDate('end_date', '>=', today());
    }

    /**
     * Scope to filter active events.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter events by academic year.
     */
    public function scopeForAcademicYear($query, $academicYearId)
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    /**
     * Get the formatted start date.
     */
    public function getFormattedStartDateAttribute(): string
    {
        if ($this->is_all_day) {
            return $this->start_date->format('M d, Y') . ' (All day)';
        }

        return $this->start_date->format('M d, Y g:i A');
    }

    /**
     * Get the formatted end date.
     */
    public function getFormattedEndDateAttribute(): string
    {
        if ($this->is_all_day) {
            return $this->end_date->format('M d, Y') . ' (All day)';
        }

        return $this->end_date->format('M d, Y g:i A');
    }

    /**
     * Get the duration of the event.
     */
    public function getDurationAttribute(): string
    {
        if ($this->is_all_day) {
            $days = $this->start_date->diffInDays($this->end_date) + 1;
            return $days === 1 ? 'All day' : "{$days} days";
        }

        $duration = $this->start_date->diff($this->end_date);

        if ($duration->days > 0) {
            return $duration->days . ' day(s) ' . $duration->format('%H:%I');
        }

        return $duration->format('%H:%I');
    }

    /**
     * Check if the event is happening now.
     */
    public function getIsHappeningNowAttribute(): bool
    {
        return now()->between($this->start_date, $this->end_date);
    }

    /**
     * Check if the event is in the future.
     */
    public function getIsUpcomingAttribute(): bool
    {
        return $this->start_date->isFuture();
    }

    /**
     * Check if the event is in the past.
     */
    public function getIsPastAttribute(): bool
    {
        return $this->end_date->isPast();
    }

    /**
     * Get the number of attendees.
     */
    public function getAttendeeCountAttribute(): int
    {
        return count($this->attendees ?? []);
    }

    /**
     * Check if registration is still open.
     */
    public function getIsRegistrationOpenAttribute(): bool
    {
        if (!$this->registration_required) {
            return false;
        }

        if ($this->registration_deadline && now() > $this->registration_deadline) {
            return false;
        }

        if ($this->max_attendees && $this->attendee_count >= $this->max_attendees) {
            return false;
        }

        return true;
    }

    /**
     * Check if a user is registered for this event.
     */
    public function isUserRegistered(int $userId): bool
    {
        if (!$this->registration_required || !$this->attendees) {
            return false;
        }

        return collect($this->attendees)->contains('user_id', $userId);
    }

    /**
     * Register a user for this event.
     */
    public function registerUser(int $userId, string $userName, string $userEmail, ?string $note = null): bool
    {
        if (!$this->is_registration_open || $this->isUserRegistered($userId)) {
            return false;
        }

        $attendees = $this->attendees ?? [];
        $attendees[] = [
            'user_id' => $userId,
            'user_name' => $userName,
            'user_email' => $userEmail,
            'registered_at' => now()->toISOString(),
            'note' => $note,
        ];

        $this->update(['attendees' => $attendees]);
        return true;
    }

    /**
     * Unregister a user from this event.
     */
    public function unregisterUser(int $userId): bool
    {
        if (!$this->attendees || !$this->isUserRegistered($userId)) {
            return false;
        }

        $attendees = collect($this->attendees)
            ->reject(fn($attendee) => $attendee['user_id'] === $userId)
            ->values()
            ->toArray();

        $this->update(['attendees' => $attendees]);
        return true;
    }
}
