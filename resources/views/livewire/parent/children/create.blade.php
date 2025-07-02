<?php

use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Add Child')] class extends Component {
    use Toast;

    // Form data
    public string $first_name = '';
    public string $last_name = '';
    public string $date_of_birth = '';
    public string $gender = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $emergency_contact_name = '';
    public string $emergency_contact_phone = '';
    public string $medical_conditions = '';
    public string $allergies = '';
    public string $special_needs = '';
    public string $additional_needs = '';
    public string $notes = '';

    // Options
    protected array $validGenders = ['male', 'female', 'other'];

    public function mount(): void
    {
        Log::info('Parent Child Create Component Mounted', [
            'parent_id' => Auth::id(),
            'ip' => request()->ip()
        ]);

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            'Accessed add child page'
        );
    }

    // Save the child
    public function save(): void
    {
        Log::info('Child Creation Started', [
            'parent_id' => Auth::id(),
            'form_data' => [
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => $this->email,
                'phone' => $this->phone,
                'gender' => $this->gender,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validated = $this->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'date_of_birth' => 'required|date|before:today',
                'gender' => 'required|string|in:' . implode(',', $this->validGenders),
                'email' => 'nullable|email|max:255|unique:child_profiles,email',
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
                'emergency_contact_name' => 'required|string|max:255',
                'emergency_contact_phone' => 'required|string|max:20',
                'medical_conditions' => 'nullable|string|max:1000',
                'allergies' => 'nullable|string|max:1000',
                'special_needs' => 'nullable|string|max:1000',
                'additional_needs' => 'nullable|string|max:1000',
                'notes' => 'nullable|string|max:1000',
            ], [
                'first_name.required' => 'Please enter the child\'s first name.',
                'last_name.required' => 'Please enter the child\'s last name.',
                'date_of_birth.required' => 'Please enter the child\'s date of birth.',
                'date_of_birth.before' => 'Date of birth must be in the past.',
                'gender.required' => 'Please select the child\'s gender.',
                'gender.in' => 'Please select a valid gender.',
                'email.email' => 'Please enter a valid email address.',
                'email.unique' => 'This email address is already registered for another child.',
                'emergency_contact_name.required' => 'Please enter an emergency contact name.',
                'emergency_contact_phone.required' => 'Please enter an emergency contact phone number.',
            ]);

            Log::info('Validation Passed', ['validated_data' => collect($validated)->except(['notes', 'medical_conditions', 'allergies'])->toArray()]);

            // Prepare data for DB
            $childData = array_merge($validated, [
                'parent_id' => Auth::id(),
            ]);

            Log::info('Prepared Child Data', ['child_data' => collect($childData)->except(['notes', 'medical_conditions', 'allergies'])->toArray()]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create child profile
            Log::debug('Creating Child Profile Record');
            $child = ChildProfile::create($childData);
            Log::info('Child Profile Created Successfully', [
                'child_id' => $child->id,
                'child_name' => $child->full_name
            ]);

            // Log activity
            Log::debug('Logging Activity');
            ActivityLog::logActivity(
                Auth::id(),
                'create',
                "Added child: {$child->full_name}",
                $child,
                [
                    'child_name' => $child->full_name,
                    'child_id' => $child->id,
                    'date_of_birth' => $validated['date_of_birth'],
                    'gender' => $validated['gender'],
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success toast
            $this->success("Child '{$child->full_name}' has been successfully added.");
            Log::info('Success Toast Displayed');

            // Redirect to child show page
            Log::info('Redirecting to Child Show Page', [
                'child_id' => $child->id,
                'route' => 'parent.children.show'
            ]);

            $this->redirect(route('parent.children.show', $child->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'first_name' => $this->first_name,
                    'last_name' => $this->last_name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'gender' => $this->gender,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Child Creation Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'form_data' => [
                    'first_name' => $this->first_name,
                    'last_name' => $this->last_name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'gender' => $this->gender,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get gender options for dropdown
    public function getGenderOptionsProperty(): array
    {
        return [
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
        ];
    }

    // Calculate age preview
    public function getAgePreviewProperty(): ?string
    {
        if (!$this->date_of_birth) {
            return null;
        }

        try {
            $birthDate = \Carbon\Carbon::parse($this->date_of_birth);
            $age = $birthDate->age;
            return $age . ' year' . ($age !== 1 ? 's' : '') . ' old';
        } catch (\Exception $e) {
            return null;
        }
    }

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Add New Child" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('parent.children.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Child Information">
                <form wire:submit="save" class="space-y-6">
                    <!-- Basic Information -->
                    <div>
                        <h3 class="mb-4 text-lg font-medium text-gray-900">Basic Information</h3>
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <!-- First Name -->
                            <div>
                                <x-input
                                    label="First Name"
                                    wire:model.live="first_name"
                                    placeholder="Enter child's first name"
                                    required
                                />
                                @error('first_name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Last Name -->
                            <div>
                                <x-input
                                    label="Last Name"
                                    wire:model.live="last_name"
                                    placeholder="Enter child's last name"
                                    required
                                />
                                @error('last_name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Date of Birth -->
                            <div>
                                <x-input
                                    label="Date of Birth"
                                    wire:model.live="date_of_birth"
                                    type="date"
                                    required
                                />
                                @if($this->agePreview)
                                    <p class="mt-1 text-sm text-green-600">Age: {{ $this->agePreview }}</p>
                                @endif
                                @error('date_of_birth')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Gender -->
                            <div>
                                <label class="block mb-2 text-sm font-medium text-gray-700">Gender *</label>
                                <select
                                    wire:model.live="gender"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                    required
                                >
                                    <option value="">Select gender</option>
                                    @foreach($this->genderOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('gender')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="pt-6 border-t">
                        <h3 class="mb-4 text-lg font-medium text-gray-900">Contact Information</h3>
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <!-- Email -->
                            <div>
                                <x-input
                                    label="Email Address"
                                    wire:model.live="email"
                                    type="email"
                                    placeholder="child@example.com (optional)"
                                />
                                @error('email')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Phone -->
                            <div>
                                <x-input
                                    label="Phone Number"
                                    wire:model.live="phone"
                                    placeholder="Optional phone number"
                                />
                                @error('phone')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Address -->
                            <div class="md:col-span-2">
                                <x-textarea
                                    label="Address"
                                    wire:model.live="address"
                                    placeholder="Child's address (optional)"
                                    rows="3"
                                />
                                @error('address')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Emergency Contact -->
                    <div class="pt-6 border-t">
                        <h3 class="mb-4 text-lg font-medium text-gray-900">Emergency Contact</h3>
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <!-- Emergency Contact Name -->
                            <div>
                                <x-input
                                    label="Emergency Contact Name"
                                    wire:model.live="emergency_contact_name"
                                    placeholder="Full name of emergency contact"
                                    required
                                />
                                @error('emergency_contact_name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Emergency Contact Phone -->
                            <div>
                                <x-input
                                    label="Emergency Contact Phone"
                                    wire:model.live="emergency_contact_phone"
                                    placeholder="Emergency contact phone number"
                                    required
                                />
                                @error('emergency_contact_phone')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Medical Information -->
                    <div class="pt-6 border-t">
                        <h3 class="mb-4 text-lg font-medium text-gray-900">Medical Information</h3>
                        <div class="space-y-6">
                            <!-- Medical Conditions -->
                            <div>
                                <x-textarea
                                    label="Medical Conditions"
                                    wire:model.live="medical_conditions"
                                    placeholder="List any medical conditions, medications, or ongoing treatments (optional)"
                                    rows="3"
                                />
                                @error('medical_conditions')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Allergies -->
                            <div>
                                <x-textarea
                                    label="Allergies"
                                    wire:model.live="allergies"
                                    placeholder="List any known allergies (food, environmental, medications, etc.)"
                                    rows="3"
                                />
                                @error('allergies')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Special Needs -->
                            <div>
                                <x-textarea
                                    label="Special Needs"
                                    wire:model.live="special_needs"
                                    placeholder="Describe any special needs or accommodations required"
                                    rows="3"
                                />
                                @error('special_needs')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Additional Needs -->
                            <div>
                                <x-textarea
                                    label="Additional Needs"
                                    wire:model.live="additional_needs"
                                    placeholder="Any other needs or requirements we should be aware of"
                                    rows="3"
                                />
                                @error('additional_needs')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Notes -->
                            <div>
                                <x-textarea
                                    label="Additional Notes"
                                    wire:model.live="notes"
                                    placeholder="Any other important information about your child"
                                    rows="3"
                                />
                                @error('notes')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-6">
                        <x-button
                            label="Cancel"
                            link="{{ route('parent.children.index') }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Add Child"
                            icon="o-user-plus"
                            type="submit"
                            class="btn-primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Help -->
        <div class="space-y-6">
            <!-- Child Preview Card -->
            <x-card title="Child Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="flex items-center mb-4">
                        <div class="mr-4 avatar placeholder">
                            <div class="w-16 h-16 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                <span class="text-lg font-bold">
                                    {{ $first_name ? strtoupper(substr($first_name, 0, 1)) : '?' }}{{ $last_name ? strtoupper(substr($last_name, 0, 1)) : '?' }}
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="text-lg font-semibold">{{ $first_name || $last_name ? trim($first_name . ' ' . $last_name) : 'Child Name' }}</div>
                            @if($this->agePreview)
                                <div class="text-sm text-gray-500">{{ $this->agePreview }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="space-y-3 text-sm">
                        @if($gender)
                            <div><strong>Gender:</strong> {{ ucfirst($gender) }}</div>
                        @endif

                        @if($email)
                            <div><strong>Email:</strong> {{ $email }}</div>
                        @endif

                        @if($phone)
                            <div><strong>Phone:</strong> {{ $phone }}</div>
                        @endif

                        @if($emergency_contact_name)
                            <div><strong>Emergency Contact:</strong> {{ $emergency_contact_name }}</div>
                        @endif

                        @if($emergency_contact_phone)
                            <div><strong>Emergency Phone:</strong> {{ $emergency_contact_phone }}</div>
                        @endif

                        @if($address)
                            <div><strong>Address:</strong> {{ $address }}</div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Required Information</div>
                        <p class="text-gray-600">First name, last name, date of birth, gender, and emergency contact details are required to add a child.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Medical Information</div>
                        <p class="text-gray-600">Please provide any medical conditions, allergies, or special needs so we can provide appropriate care and accommodations.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Emergency Contact</div>
                        <p class="text-gray-600">This should be someone other than yourself who can be reached in case of emergency. Include their full name and phone number.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Privacy</div>
                        <p class="text-gray-600">All information provided is kept confidential and is only used for educational and safety purposes.</p>
                    </div>
                </div>
            </x-card>

            <!-- Important Notice -->
            <x-card title="Important Notice" class="border-blue-200 bg-blue-50">
                <div class="space-y-2 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-information-circle" class="w-5 h-5 text-blue-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-blue-800">Next Steps</div>
                            <p class="text-blue-700">After adding your child, you'll be able to enroll them in programs and track their progress.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-blue-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-blue-800">Data Security</div>
                            <p class="text-blue-700">All personal information is encrypted and stored securely in compliance with privacy regulations.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-pencil" class="w-5 h-5 text-blue-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-blue-800">Update Anytime</div>
                            <p class="text-blue-700">You can update your child's information at any time from their profile page.</p>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
