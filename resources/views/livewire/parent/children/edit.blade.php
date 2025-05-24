<?php

use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

new #[Title('Edit Child Profile')] class extends Component {
    use WithFileUploads;
    use Toast;

    public ChildProfile $childProfile;

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

    public function mount(ChildProfile $childProfile): void
    {
        // Ensure parent can only edit their own children
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile || $childProfile->parent_profile_id !== $parentProfile->id) {
            $this->error('You do not have permission to edit this child profile.');
            $this->redirect(route('parent.children.index'));
            return;
        }

        $this->childProfile = $childProfile->load('user');

        // Set form values
        $this->name = $childProfile->user->name;
        $this->date_of_birth = $childProfile->date_of_birth?->format('Y-m-d');
        $this->gender = $childProfile->gender ?? '';
        $this->medical_information = $childProfile->medical_information;
        $this->special_needs = $childProfile->special_needs;
        $this->additional_notes = $childProfile->additional_notes;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit form for child profile: {$this->name}",
            ChildProfile::class,
            $this->childProfile->id,
            ['ip' => request()->ip()]
        );
    }

    // Update child profile
    public function save(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            // Update user name
            $this->childProfile->user->update([
                'name' => $this->name
            ]);

            // Handle photo upload
            $photoPath = $this->childProfile->photo;
            if ($this->photo) {
                $photoPath = $this->photo->store('child-photos', 'public');
            }

            // Update child profile
            $this->childProfile->update([
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
                'update',
                "Updated child profile: {$this->name}",
                ChildProfile::class,
                $this->childProfile->id,
                [
                    'child_name' => $this->name,
                    'child_id' => $this->childProfile->id
                ]
            );

            DB::commit();

            // Show success notification and redirect
            $this->success("Child profile for {$this->name} has been successfully updated.", redirectTo: route('parent.children.show', $this->childProfile));

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("An error occurred while updating: {$e->getMessage()}");
        }
    }
};?>

<div>
    <x-header title="Edit Child Profile" separator>
        <x-slot:actions>
            <x-button label="Cancel" icon="o-x-mark" link="{{ route('parent.children.show', $childProfile) }}" />
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 md:grid-cols-3">
        <!-- CURRENT PROFILE CARD -->
        <div class="md:col-span-1">
            <x-card title="Current Profile" separator>
                <div class="flex flex-col items-center mb-6">
                    <div class="mb-4 avatar">
                        <div class="w-32 h-32 rounded-full">
                            @if ($childProfile->photo)
                                <img src="{{ asset('storage/' . $childProfile->photo) }}" alt="{{ $childProfile->user?->name ?? 'Child' }}">
                            @else
                                <img src="{{ $childProfile->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $childProfile->user?->name ?? 'Child' }}">
                            @endif
                        </div>
                    </div>
                    <h2 class="text-xl font-bold">{{ $childProfile->user?->name ?? 'Unknown' }}</h2>
                    <div class="badge badge-{{ match($childProfile->gender ?? '') {
                        'male' => 'info',
                        'female' => 'secondary',
                        'other' => 'warning',
                        default => 'ghost'
                    } }} mt-2">{{ ucfirst($childProfile->gender ?? 'Not specified') }}</div>
                </div>

                <div class="alert alert-info">
                    <x-icon name="o-information-circle" class="w-5 h-5" />
                    <span>Update your child's information using the form.</span>
                </div>
            </x-card>
        </div>

        <!-- EDIT FORM CARD -->
        <div class="md:col-span-2">
            <x-card title="Edit Information" separator>
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
                        <x-file label="New Photo (Optional)" wire:model="photo" accept="image/*" hint="Maximum size: 1MB. Leave empty to keep current photo." />

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
                        <x-button label="Cancel" link="{{ route('parent.children.show', $childProfile) }}" />
                        <x-button label="Save Changes" icon="o-check" class="btn-primary" type="submit" spinner="save" />
                    </x-slot:actions>
                </x-form>
            </x-card>
        </div>
    </div>
</div>
