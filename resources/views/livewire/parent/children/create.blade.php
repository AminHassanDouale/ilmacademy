<?php

use App\Models\ChildProfile;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

new #[Title('Register New Child')] class extends Component {
    use WithFileUploads;
    use Toast;

    // Form fields
    #[Rule('required|min:3|max:100')]
    public string $name = '';

    #[Rule('required|date|before:today')]
    public ?string $date_of_birth = null;

    #[Rule('required|in:male,female,other')]
    public string $gender = '';

    #[Rule('nullable|image|max:1024')] // 1MB Max
    public $photo = null;

    #[Rule('nullable|min:5|max:500')]
    public ?string $medical_information = null;

    #[Rule('nullable|min:5|max:500')]
    public ?string $special_needs = null;

    #[Rule('nullable|min:5|max:500')]
    public ?string $additional_notes = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Parent accessed child registration page',
            ChildProfile::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Create a new child
    public function save(): void
    {
        $this->validate();

        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            $this->error('Parent profile not found. Please contact support.');
            return;
        }

        try {
            DB::beginTransaction();

            // Create user account for the child
            $user = User::create([
                'name' => $this->name,
                'email' => Str::slug($this->name) . '-' . time() . '@child.account', // Placeholder email
                'password' => Hash::make(Str::random(16)), // Random password as children don't login
                'account_type' => 'child'
            ]);

            // Handle photo upload
            $photoPath = null;
            if ($this->photo) {
                $photoPath = $this->photo->store('child-photos', 'public');
            }

            // Create child profile
            $childProfile = ChildProfile::create([
                'user_id' => $user->id,
                'parent_profile_id' => $parentProfile->id,
                'date_of_birth' => $this->date_of_birth,
                'gender' => $this->gender,
                'photo' => $photoPath,
                'medical_information' => $this->medical_information,
                'special_needs' => $this->special_needs,
                'additional_notes' => $this->additional_notes
            ]);

            // Log the action
            ActivityLog::log(
                Auth::id(),
                'create',
                "Registered new child: {$this->name}",
                ChildProfile::class,
                $childProfile->id,
                [
                    'child_name' => $this->name,
                    'child_id' => $childProfile->id
                ]
            );

            DB::commit();

            // Show success notification and redirect
            $this->success("Child {$this->name} has been successfully registered.", redirectTo: route('parent.children.index'));

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("An error occurred during registration: {$e->getMessage()}");
        }
    }
};?>

<div>
    <x-header title="Register New Child" separator>
        <x-slot:actions>
            <x-button label="Back to Children" icon="o-arrow-left" link="{{ route('parent.children.index') }}" />
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 md:grid-cols-3">
        <!-- INTRO CARD -->
        <div class="md:col-span-1">
            <x-card>
                <div class="space-y-4">
                    <img src="{{ asset('images/child-registration.png') }}" alt="Child Registration" class="w-full rounded-lg">

                    <h3 class="text-lg font-semibold">Child Registration</h3>

                    <p>Please provide accurate information about your child. This information will help us provide the best experience for your child.</p>

                    <p class="text-sm opacity-70">Fields marked with * are required.</p>

                    <div class="alert alert-info">
                        <x-icon name="o-information-circle" class="w-5 h-5" />
                        <span>Your child's profile will be reviewed by our staff.</span>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- FORM CARD -->
        <div class="md:col-span-2">
            <x-card title="Child Information" separator>
                <x-form wire:submit="save">
                    <!-- Basic Information -->
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <x-input label="Full Name *" wire:model="name" placeholder="Enter child's full name" />
                        </div>

                        <div>
                            <x-input
                                label="Date of Birth *"
                                type="date"
                                wire:model="date_of_birth"
                                placeholder="DD/MM/YYYY"
                                hint="Format: Day/Month/Year"
                                x-data="{}"
                                x-init="$el.querySelector('input').setAttribute('pattern', '(0[1-9]|[12][0-9]|3[01])/(0[1-9]|1[012])/[0-9]{4}')"
                            />
                        </div>

                        <div>
                            <x-select
                                label="Gender *"
                                placeholder="Select gender"
                                :options="[
                                    ['label' => 'Male', 'value' => 'male'],
                                    ['label' => 'Female', 'value' => 'female'],
                                    ['label' => 'Other', 'value' => 'other']
                                ]"
                                wire:model="gender"
                                option-label="label"
                                option-value="value"
                            />
                        </div>
                    </div>

                    <!-- Photo Upload -->
                    <div class="mt-4">
                        <x-file label="Photo" wire:model="photo" accept="image/*" hint="Maximum size: 1MB" />

                        @if ($photo)
                            <div class="mt-2">
                                <p class="mb-1 text-sm text-gray-500">Preview:</p>
                                <img src="{{ $photo->temporaryUrl() }}" alt="Preview" class="object-cover w-24 h-24 rounded-lg">
                            </div>
                        @endif
                    </div>

                    <!-- Additional Information -->
                    <div class="mt-4 space-y-4">
                        <x-textarea label="Medical Information" wire:model="medical_information" placeholder="Any medical conditions, allergies, or medications..." rows="3" />

                        <x-textarea label="Special Needs" wire:model="special_needs" placeholder="Any special needs or accommodations required..." rows="3" />

                        <x-textarea label="Additional Notes" wire:model="additional_notes" placeholder="Any other information that may be helpful..." rows="3" />
                    </div>

                    <x-slot:actions>
                        <x-button label="Cancel" link="{{ route('parent.children.index') }}" />
                        <x-button label="Register Child" icon="o-user-plus" class="btn-primary" type="submit" spinner="save" />
                    </x-slot:actions>
                </x-form>
            </x-card>
        </div>
    </div>

    <!-- JavaScript to format the date -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Format date as DD/MM/YYYY
            const dateInput = document.querySelector('input[type="date"]');
            if (dateInput) {
                dateInput.addEventListener('input', function(e) {
                    const value = e.target.value;
                    const parts = value.split('-');
                    if (parts.length === 3) {
                        // Convert YYYY-MM-DD to DD/MM/YYYY
                        const formattedDate = `${parts[2]}/${parts[1]}/${parts[0]}`;
                        e.target.dataset.formattedValue = formattedDate;
                    }
                });
            }
        });
    </script>
</div>
