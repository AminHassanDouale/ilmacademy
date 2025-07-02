<?php

use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Child')] class extends Component {
    use Toast;

    // Model instance
    public ChildProfile $childProfile;

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

    // Original data for change tracking
    protected array $originalData = [];

    public function mount(ChildProfile $childProfile): void
    {
        // Ensure the authenticated parent owns this child
        if ($childProfile->parent_id !== Auth::id()) {
            abort(403, 'You do not have permission to edit this child.');
        }

        $this->childProfile = $childProfile;

        Log::info('Parent Child Edit Component Mounted', [
            'parent_id' => Auth::id(),
            'child_id' => $childProfile->id,
            'child_name' => $childProfile->full_name,
            'ip' => request()->ip()
        ]);

        // Load current child data into form
        $this->loadChildData();

        // Store original data for change tracking
        $this->storeOriginalData();

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            "Accessed edit page for child: {$childProfile->full_name}",
            $childProfile,
            [
                'child_name' => $childProfile->full_name,
                'child_id' => $childProfile->id,
            ]
        );
    }

    // Load child data into form
    protected function loadChildData(): void
    {
        $this->first_name = $this->childProfile->first_name ?? '';
        $this->last_name = $this->childProfile->last_name ?? '';
        $this->date_of_birth = $this->childProfile->date_of_birth ? $this->childProfile->date_of_birth->format('Y-m-d') : '';
        $this->gender = $this->childProfile->gender ?? '';
        $this->email = $this->childProfile->email ?? '';
        $this->phone = $this->childProfile->phone ?? '';
        $this->address = $this->childProfile->address ?? '';
        $this->emergency_contact_name = $this->childProfile->emergency_contact_name ?? '';
        $this->emergency_contact_phone = $this->childProfile->emergency_contact_phone ?? '';
        $this->medical_conditions = $this->childProfile->medical_conditions ?? '';
        $this->allergies = $this->childProfile->allergies ?? '';
        $this->special_needs = $this->childProfile->special_needs ?? '';
        $this->additional_needs = $this->childProfile->additional_needs ?? '';
        $this->notes = $this->childProfile->notes ?? '';
    }

    // Store original data for change tracking
    protected function storeOriginalData(): void
    {
        $this->originalData = [
            'first_name' => $this->childProfile->first_name ?? '',
            'last_name' => $this->childProfile->last_name ?? '',
            'date_of_birth' => $this->childProfile->date_of_birth ? $this->childProfile->date_of_birth->format('Y-m-d') : '',
            'gender' => $this->childProfile->gender ?? '',
            'email' => $this->childProfile->email ?? '',
            'phone' => $this->childProfile->phone ?? '',
            'address' => $this->childProfile->address ?? '',
            'emergency_contact_name' => $this->childProfile->emergency_contact_name ?? '',
            'emergency_contact_phone' => $this->childProfile->emergency_contact_phone ?? '',
            'medical_conditions' => $this->childProfile->medical_conditions ?? '',
            'allergies' => $this->childProfile->allergies ?? '',
            'special_needs' => $this->childProfile->special_needs ?? '',
            'additional_needs' => $this->childProfile->additional_needs ?? '',
            'notes' => $this->childProfile->notes ?? '',
        ];
    }

    // Save the child
    public function save(): void
    {
        Log::info('Child Update Started', [
            'parent_id' => Auth::id(),
            'child_id' => $this->childProfile->id,
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
                'email' => 'nullable|email|max:255|unique:child_profiles,email,' . $this->childProfile->id,
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

            // Track changes for activity log
            $changes = $this->getChanges($validated);

            Log::info('Prepared Child Data', ['child_data' => collect($validated)->except(['notes', 'medical_conditions', 'allergies'])->toArray(), 'changes' => $changes]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Update child profile
            Log::debug('Updating Child Profile Record');
            $this->childProfile->update($validated);
            Log::info('Child Profile Updated Successfully', [
                'child_id' => $this->childProfile->id,
                'child_name' => $this->childProfile->full_name
            ]);

            // Log activity with changes
            if (!empty($changes)) {
                $changeDescription = "Updated child: {$this->childProfile->full_name}. Changes: " . implode(', ', $changes);

                ActivityLog::logActivity(
                    Auth::id(),
                    'update',
                    $changeDescription,
                    $this->childProfile,
                    [
                        'changes' => $changes,
                        'child_name' => $this->childProfile->full_name,
                        'child_id' => $this->childProfile->id,
                    ]
                );
            }

            DB::commit();
            Log::info('Database Transaction Committed');

            // Update original data for future comparisons
            $this->storeOriginalData();

            // Show success toast
            $this->success("Child '{$this->childProfile->full_name}' has been successfully updated.");
            Log::info('Success Toast Displayed');

            // Redirect to child show page
            Log::info('Redirecting to Child Show Page', [
                'child_id' => $this->childProfile->id,
                'route' => 'parent.children.show'
            ]);

            $this->redirect(route('parent.children.show', $this->childProfile->id));

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
            Log::error('Child Update Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'child_id' => $this->childProfile->id,
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

    // Get changes between original and new data
    protected function getChanges(array $newData): array
    {
        $changes = [];

        // Map form fields to human-readable names
        $fieldMap = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'date_of_birth' => 'Date of Birth',
            'gender' => 'Gender',
            'email' => 'Email',
            'phone' => 'Phone',
            'address' => 'Address',
            'emergency_contact_name' => 'Emergency Contact Name',
            'emergency_contact_phone' => 'Emergency Contact Phone',
            'medical_conditions' => 'Medical Conditions',
            'allergies' => 'Allergies',
            'special_needs' => 'Special Needs',
            'additional_needs' => 'Additional Needs',
            'notes' => 'Notes',
        ];

        foreach ($newData as $field => $newValue) {
            $originalValue = $this->originalData[$field] ?? '';

            if ($originalValue != $newValue) {
                $fieldName = $fieldMap[$field] ?? $field;

                if ($field === 'gender') {
                    $changes[] = "{$fieldName} from " . ucfirst($originalValue ?: 'unspecified') . " to " . ucfirst($newValue);
                } else {
                    $changes[] = "{$fieldName} updated";
                }
            }
        }

        return $changes;
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
    <x-header title="Edit Child: {{ $childProfile->full_name }}" separator>
        <x-slot:actions>
            <x-button
                label="View Child"
                icon="o-eye"
                link="{{ route('parent.children.show', $childProfile->id) }}"
                class="btn-ghost"
            />
            <x-button
                label="Back to List"
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
                                        <option value="{{ $value }}" {{ $gender == $value ? 'selected' : '' }}>{{ $label }}</option>
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
                            link="{{ route('parent.children.show', $childProfile->id) }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Update Child"
                            icon="o-check"
                            type="submit"
                            class="btn-primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Info and Preview -->
        <div class="space-y-6">
            <!-- Current Child Info -->
            <x-card title="Current Information">
                <div class="flex items-center mb-4 space-x-4">
                    <div class="avatar placeholder">
                        <div class="w-16 h-16 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                            <span class="text-lg font-bold">{{ $childProfile->initials }}</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-lg font-semibold">{{ $childProfile->full_name }}</div>
                        @if($childProfile->age)
                            <div class="text-sm text-gray-500">{{ $childProfile->age }} years old</div>
                        @endif
                        <div class="text-xs text-gray-400">ID: {{ $childProfile->id }}</div>
                    </div>
                </div>

                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Current Gender</div>
                        <div>{{ $childProfile->gender ? ucfirst($childProfile->gender) : 'Not specified' }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Current Email</div>
                        <div>{{ $childProfile->email ?: 'Not provided' }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $childProfile->created_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $childProfile->updated_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Updated Preview Card -->
            <x-card title="Updated Preview">
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
                            <div class="text-lg font-semibold">{{ $first_name || $last_name ? trim($first_name . ' ' . $last_name) : $childProfile->full_name }}</div>
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
                        <p class="text-gray-600">First name, last name, date of birth, gender, and emergency contact details are required fields.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Medical Information</div>
                        <p class="text-gray-600">Keep medical information up to date to ensure proper care and accommodations for your child.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Changes Tracking</div>
                        <p class="text-gray-600">All changes to your child's profile are logged for audit purposes. You can view the activity log on the child details page.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Data Security</div>
                        <p class="text-gray-600">All personal information is encrypted and stored securely in compliance with privacy regulations.</p>
                    </div>
                </div>
            </x-card>

            <!-- Important Notice -->
            <x-card title="Important Notice" class="border-yellow-200 bg-yellow-50">
                <div class="space-y-2 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Medical Information</div>
                            <p class="text-yellow-700">Please ensure all medical conditions, allergies, and special needs are accurately recorded for your child's safety.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Emergency Contact</div>
                            <p class="text-yellow-700">Make sure emergency contact information is current and the person is available during program hours.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-information-circle" class="w-5 h-5 text-yellow-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-yellow-800">Enrollments</div>
                            <p class="text-yellow-700">Changes to basic information may affect current enrollments. Contact support if you have concerns.</p>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
