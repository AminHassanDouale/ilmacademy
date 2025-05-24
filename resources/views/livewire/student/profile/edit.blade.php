<?php

use App\Models\User;
use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Rule;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Profile')] class extends Component {
    use Toast;
    use WithFileUploads;

    // User and profile models
    public User $user;
    public ?ChildProfile $childProfile = null;

    // Form fields - User
    #[Rule('required|string|max:255')]
    public $name;

    #[Rule('required|email|max:255')]
    public $email;

    #[Rule('nullable|string|max:20')]
    public $phone;

    #[Rule('nullable|string|max:500')]
    public $address;

    // Form fields - Child Profile
    #[Rule('nullable|date|before:today')]
    public $date_of_birth;

    #[Rule('nullable|in:male,female,other')]
    public $gender;

    #[Rule('nullable|string|max:1000')]
    public $medical_information;

    #[Rule('nullable|string|max:1000')]
    public $special_needs;

    #[Rule('nullable|string|max:1000')]
    public $additional_needs;

    // For photo upload
    #[Rule('nullable|image|max:2048')]
    public $photo;

    public $existingPhoto = null;
    public $removePhoto = false;

    // For password change
    #[Rule('nullable|min:8|confirmed')]
    public $password;
    public $password_confirmation;
    public $showPasswordForm = false;

    public function mount(): void
    {
        $this->user = Auth::user();

        // Check if user has a child profile
        $this->childProfile = ChildProfile::where('user_id', $this->user->id)->first();

        // Fill form fields - User
        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->phone = $this->user->phone;
        $this->address = $this->user->address;

        // Fill form fields - Child Profile
        if ($this->childProfile) {
            $this->date_of_birth = $this->childProfile->date_of_birth ? $this->childProfile->date_of_birth->format('Y-m-d') : null;
            $this->gender = $this->childProfile->gender;
            $this->medical_information = $this->childProfile->medical_information;
            $this->special_needs = $this->childProfile->special_needs;
            $this->additional_needs = $this->childProfile->additional_needs;

            // Get existing photo
            if ($this->childProfile->photo) {
                $this->existingPhoto = $this->childProfile->photo;
            }
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Student accessed profile edit page',
            User::class,
            Auth::id(),
            ['ip' => request()->ip()]
        );
    }

    // Save profile changes
    public function save(): void
    {
        // Validate form fields
        $this->validate();

        try {
            // Begin transaction
            \DB::beginTransaction();

            // Update user details
            $this->user->update([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'address' => $this->address,
            ]);

            // Update password if set
            if ($this->password) {
                $this->user->update([
                    'password' => bcrypt($this->password),
                ]);
            }

            // Update or create child profile
            if ($this->childProfile) {
                // Handle photo upload
                $photoPath = $this->handlePhotoUpload($this->childProfile->photo);

                // Update existing profile
                $this->childProfile->update([
                    'date_of_birth' => $this->date_of_birth,
                    'gender' => $this->gender,
                    'photo' => $photoPath,
                    'medical_information' => $this->medical_information,
                    'special_needs' => $this->special_needs,
                    'additional_needs' => $this->additional_needs,
                ]);
            }

            // Commit transaction
            \DB::commit();

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                'Student updated profile',
                User::class,
                Auth::id(),
                ['ip' => request()->ip()]
            );

            $this->success('Profile updated successfully.');

            // Reset password fields
            $this->password = null;
            $this->password_confirmation = null;
            $this->showPasswordForm = false;

            // Reset photo upload field
            $this->photo = null;
            $this->removePhoto = false;

            // Refresh existing photo value
            if ($this->childProfile && $this->childProfile->photo) {
                $this->existingPhoto = $this->childProfile->photo;
            } else {
                $this->existingPhoto = null;
            }
        } catch (\Exception $e) {
            // Rollback transaction on error
            \DB::rollBack();

            // Log error
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error updating profile: ' . $e->getMessage(),
                User::class,
                Auth::id(),
                ['ip' => request()->ip()]
            );

            $this->error('Failed to update profile: ' . $e->getMessage());
        }
    }

    // Handle photo upload or removal
    private function handlePhotoUpload(?string $currentPhoto): ?string
    {
        // If remove photo is checked, delete the current photo
        if ($this->removePhoto && $currentPhoto) {
            Storage::disk('public')->delete($currentPhoto);
            return null;
        }

        // If there's a new photo, store it and delete the old one
        if ($this->photo) {
            if ($currentPhoto) {
                Storage::disk('public')->delete($currentPhoto);
            }
            return $this->photo->store('profile-photos', 'public');
        }

        // Otherwise, keep the current photo
        return $currentPhoto;
    }

    // Toggle password form visibility
    public function togglePasswordForm(): void
    {
        $this->showPasswordForm = !$this->showPasswordForm;

        // Reset password fields when hiding the form
        if (!$this->showPasswordForm) {
            $this->password = null;
            $this->password_confirmation = null;
        }
    }
};
?>

{{--
    This Blade template is used to render the student profile edit page.
    It uses Volt under the hood to handle the component logic.
--}}
<div>
    <!-- Page header -->
    <x-header title="Edit Profile" separator progress-indicator>
        <x-slot:subtitle>
            Update your personal information and preferences
        </x-slot:subtitle>
    </x-header>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        <!-- Profile photo and basic info -->
        <div>
            <x-card title="Profile Photo">
                <div class="flex flex-col items-center space-y-4">
                    <div class="avatar">
                        <div class="w-32 h-32 rounded-full">
                            @if ($photo)
                                <img src="{{ $photo->temporaryUrl() }}" alt="{{ $name }}">
                            @elseif ($existingPhoto)
                                <img src="{{ Storage::url($existingPhoto) }}" alt="{{ $name }}">
                            @else
                                <img src="{{ $user->profile_photo_url }}" alt="{{ $name }}">
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-col items-center space-y-2">
                        <input type="file" wire:model="photo" id="photo" class="w-full max-w-xs file-input file-input-bordered" accept="image/*" />

                        @error('photo')
                            <div class="text-sm text-error">{{ $message }}</div>
                        @enderror

                        @if ($existingPhoto)
                            <div class="flex items-center gap-2">
                                <x-checkbox wire:model="removePhoto" />
                                <span class="text-sm">Remove photo</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="mt-4 space-y-4">
                    @if ($childProfile && $date_of_birth)
                        <div>
                            <span class="text-sm font-medium">Age:</span>
                            <span class="text-sm">
                                @php
                                    $age = \Carbon\Carbon::parse($date_of_birth)->age;
                                    echo $age . ' years';
                                @endphp
                            </span>
                        </div>
                    @endif

                    <div>
                        <span class="text-sm font-medium">Member since:</span>
                        <span class="text-sm">
                            @php
                                echo $user->created_at ? $user->created_at->format('d/m/Y') : '';
                            @endphp
                        </span>
                    </div>

                    @if ($user->last_login_at)
                        <div>
                            <span class="text-sm font-medium">Last login:</span>
                            <span class="text-sm">{{ $user->last_login_at->diffForHumans() }}</span>
                        </div>
                    @endif
                </div>
            </x-card>
        </div>

        <!-- Personal information -->
        <div class="md:col-span-2">
            <x-card title="Personal Information">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input
                            label="Full Name"
                            wire:model="name"
                            placeholder="Your full name"
                            required
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-input
                            label="Email Address"
                            type="email"
                            wire:model="email"
                            placeholder="Your email address"
                            required
                        />
                    </div>

                    <div>
                        <x-input
                            label="Phone Number"
                            wire:model="phone"
                            placeholder="Your phone number"
                        />
                    </div>

                    <div>
                        <x-input
                            label="Date of Birth"
                            type="date"
                            wire:model="date_of_birth"
                            max="{{ now()->format('Y-m-d') }}"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Gender"
                            placeholder="Select gender"
                            wire:model="gender"
                            :options="[
                                ['value' => 'male', 'label' => 'Male'],
                                ['value' => 'female', 'label' => 'Female'],
                                ['value' => 'other', 'label' => 'Other'],
                            ]"
                            option-label="label"
                            option-value="value"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-textarea
                            label="Address"
                            wire:model="address"
                            placeholder="Your address"
                            rows="2"
                        />
                    </div>
                </div>

                <div class="mt-6">
                    <h3 class="mb-4 text-lg font-medium">Additional Information</h3>

                    <div class="space-y-4">
                        <x-textarea
                            label="Medical Information"
                            wire:model="medical_information"
                            placeholder="Any medical conditions, allergies, or important health information"
                            rows="3"
                            hint="Please provide any health information that school staff should be aware of"
                        />

                        <x-textarea
                            label="Special Needs"
                            wire:model="special_needs"
                            placeholder="Any special educational needs or accommodations required"
                            rows="3"
                            hint="Information about learning needs, disabilities, or required accommodations"
                        />

                        <x-textarea
                            label="Additional Needs"
                            wire:model="additional_needs"
                            placeholder="Any other needs or preferences that should be considered"
                            rows="3"
                            hint="Other important information that may help improve your educational experience"
                        />
                    </div>
                </div>
            </x-card>

            <!-- Password section -->
            <x-card title="Security" class="mt-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-medium">Password</h3>
                        <p class="text-sm text-gray-500">Update your password to maintain security</p>
                    </div>
                    <x-button
                        label="{{ $showPasswordForm ? 'Cancel' : 'Change Password' }}"
                        icon="{{ $showPasswordForm ? 'o-x-mark' : 'o-key' }}"
                        wire:click="togglePasswordForm"
                        class="{{ $showPasswordForm ? 'btn-error' : 'btn-outline' }}"
                    />
                </div>

                @if ($showPasswordForm)
                    <div class="mt-4 space-y-4">
                        <x-input
                            label="New Password"
                            type="password"
                            wire:model="password"
                            placeholder="Enter new password"
                            hint="Password must be at least 8 characters long"
                        />

                        <x-input
                            label="Confirm Password"
                            type="password"
                            wire:model="password_confirmation"
                            placeholder="Confirm new password"
                        />
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    <!-- Save button -->
    <div class="flex justify-end mt-6">
        <x-button
            label="Save Changes"
            icon="o-check"
            wire:click="save"
            class="btn-primary"
        />
    </div>
</div>
