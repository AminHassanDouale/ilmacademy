<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasRoles, HasFactory;

    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'phone',
        'last_login_at',
        'last_login_ip',
        'address'
    ];

    /**
     * Les attributs cachés pour les tableaux.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Les attributs à transformer.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
    ];

    /**
     * Accès au profil enseignant de l'utilisateur.
     *
     * @return HasOne
     */
    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    /**
     * Accès au profil parent de l'utilisateur.
     *
     * @return HasOne
     */
    public function parentProfile(): HasOne
    {
        return $this->hasOne(ParentProfile::class);
    }

    /**
     * Accès au profil client de l'utilisateur.
     *
     * @return HasOne
     */
    public function clientProfile(): HasOne
    {
        return $this->hasOne(ClientProfile::class);
    }

    /**
     * Get the child profile associated with this user (when user is a student).
     *
     * @return HasOne
     */
    public function childProfile(): HasOne
    {
        return $this->hasOne(ChildProfile::class, 'user_id');
    }

    /**
     * Get the children profiles for this user (when user is a parent).
     *
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(ChildProfile::class, 'parent_id');
    }

    /**
     * Accès aux journaux d'activité de l'utilisateur.
     *
     * @return HasMany
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Accès aux notifications de l'utilisateur.
     *
     * @return HasMany
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get program enrollments if user is a student with child profile
     */
    public function programEnrollments(): HasMany
    {
        return $this->hasManyThrough(
            ProgramEnrollment::class,
            ChildProfile::class,
            'user_id', // Foreign key on child_profiles table
            'child_profile_id', // Foreign key on program_enrollments table
            'id', // Local key on users table
            'id' // Local key on child_profiles table
        );
    }

    /**
     * Check if user is a parent
     */
    public function isParent(): bool
    {
        return $this->hasRole('parent');
    }

    /**
     * Check if user is a student
     */
    public function isStudent(): bool
    {
        return $this->hasRole('student');
    }

    /**
     * Check if user is a teacher
     */
    public function isTeacher(): bool
    {
        return $this->hasRole('teacher');
    }

    /**
     * Check if user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Obtenir l'URL de la photo de profil.
     *
     * @return Attribute
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&color=7F9CF5&background=EBF4FF'
        );
    }
}
