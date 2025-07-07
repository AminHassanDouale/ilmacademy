<?php
// App/Models/ClientProfile.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'phone',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'relationship_to_children', // e.g., 'Parent', 'Guardian', 'Relative'
        'occupation',
        'company',
        'preferred_contact_method', // 'email', 'phone', 'sms'
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the client profile.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all children associated with this client/parent
     */
    public function children()
    {
        return $this->hasMany(ChildProfile::class, 'parent_id', 'user_id');
    }

    /**
     * Get invoices for this client (could be for their children)
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'client_profile_id');
    }

    /**
     * Get payments made by this client
     */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'client_profile_id');
    }

    /**
     * Get all enrollments for this client's children
     */
    public function childrenEnrollments()
    {
        return $this->hasManyThrough(
            ProgramEnrollment::class,
            ChildProfile::class,
            'parent_id', // Foreign key on child_profiles table
            'child_profile_id', // Foreign key on program_enrollments table
            'user_id', // Local key on client_profiles table
            'id' // Local key on child_profiles table
        );
    }

    /**
     * Get active enrollments for this client's children
     */
    public function activeChildrenEnrollments()
    {
        return $this->childrenEnrollments()
            ->where('status', 'Active')
            ->whereHas('academicYear', function($query) {
                $query->where('is_current', true);
            });
    }

    /**
     * Get total outstanding balance for this client
     */
    public function getTotalOutstandingAttribute()
    {
        if (!class_exists(Invoice::class)) {
            return 0;
        }

        return $this->invoices()
            ->where('status', '!=', 'paid')
            ->sum('amount');
    }

    /**
     * Get total paid amount by this client
     */
    public function getTotalPaidAttribute()
    {
        if (!class_exists(Payment::class)) {
            return 0;
        }

        return $this->payments()
            ->where('status', 'completed')
            ->sum('amount');
    }

    /**
     * Check if client has any active enrollments
     */
    public function hasActiveEnrollments()
    {
        return $this->activeChildrenEnrollments()->exists();
    }

    /**
     * Get all curricula this client's children are enrolled in
     */
    public function enrolledCurricula()
    {
        return Curriculum::whereHas('programEnrollments', function($query) {
            $query->whereIn('child_profile_id', $this->children()->pluck('id'))
                  ->where('status', 'Active')
                  ->whereHas('academicYear', function($q) {
                      $q->where('is_current', true);
                  });
        });
    }

    /**
     * Scopes
     */
    public function scopeWithActiveChildren($query)
    {
        return $query->whereHas('children', function($q) {
            $q->whereHas('programEnrollments', function($enrollment) {
                $enrollment->where('status', 'Active')
                    ->whereHas('academicYear', function($year) {
                        $year->where('is_current', true);
                    });
            });
        });
    }

    public function scopeSearch($query, $search)
    {
        return $query->whereHas('user', function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        })->orWhere('phone', 'like', "%{$search}%");
    }

    public function scopeByContactMethod($query, $method)
    {
        return $query->where('preferred_contact_method', $method);
    }
}
