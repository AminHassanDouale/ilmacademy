<?php

use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Curriculum')] class extends Component {
    use Toast;

    // Form state
    public string $name = '';
    public string $code = '';
    public string $description = '';
    public bool $autoGenerateCode = true;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed curriculum creation page',
            Curriculum::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    /**
     * Auto-generate curriculum code based on name
     */
    public function updatedName(): void
    {
        if ($this->autoGenerateCode && !empty($this->name)) {
            $this->code = $this->generateCurriculumCode($this->name);
        }
    }

    /**
     * Generate a unique curriculum code
     */
    private function generateCurriculumCode(string $name): string
    {
        // Extract meaningful words (remove common words)
        $commonWords = ['and', 'or', 'the', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];
        $words = collect(explode(' ', strtolower($name)))
            ->filter(fn($word) => !in_array($word, $commonWords) && strlen($word) > 1)
            ->take(3); // Take max 3 words

        if ($words->isEmpty()) {
            // Fallback: use first 3 characters of name
            $baseCode = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 3));
        } else {
            // Create acronym from meaningful words
            $baseCode = $words->map(fn($word) => strtoupper(substr($word, 0, 2)))->join('');

            // Limit to 6 characters max
            $baseCode = substr($baseCode, 0, 6);
        }

        // Add year suffix
        $year = date('y'); // 2-digit year
        $codeWithYear = $baseCode . $year;

        // Check for uniqueness and add number if needed
        $finalCode = $codeWithYear;
        $counter = 1;

        while (Curriculum::where('code', $finalCode)->exists()) {
            $finalCode = $codeWithYear . sprintf('%02d', $counter);
            $counter++;

            // Prevent infinite loop
            if ($counter > 99) {
                $finalCode = $codeWithYear . rand(10, 99);
                break;
            }
        }

        return $finalCode;
    }

    /**
     * Toggle auto-generation and regenerate if enabled
     */
    public function updatedAutoGenerateCode(): void
    {
        if ($this->autoGenerateCode && !empty($this->name)) {
            $this->code = $this->generateCurriculumCode($this->name);
        }
    }

    /**
     * Manually regenerate code
     */
    public function regenerateCode(): void
    {
        if (!empty($this->name)) {
            $this->code = $this->generateCurriculumCode($this->name);
            $this->success('Code regenerated successfully!');
        } else {
            $this->warning('Please enter a curriculum name first.');
        }
    }

    /**
     * Form validation rules
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('curricula', 'code')],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom error messages
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'The curriculum name is required.',
            'code.required' => 'The curriculum code is required.',
            'code.unique' => 'This curriculum code is already in use.',
        ];
    }

    /**
     * Create a new curriculum
     */
    public function createCurriculum(): void
    {
        $this->validate();

        try {
            // Create curriculum
            $curriculum = Curriculum::create([
                'name' => $this->name,
                'code' => $this->code,
                'description' => $this->description,
            ]);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created new curriculum: {$this->name} ({$this->code})",
                Curriculum::class,
                $curriculum->id,
                [
                    'curriculum_name' => $this->name,
                    'curriculum_code' => $this->code
                ]
            );

            // Show success notification
            $this->success("Curriculum {$this->name} has been created successfully.");

            // Redirect to the curricula index page
            $this->redirect(route('admin.curricula.index'));
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    /**
     * Cancel and go back
     */
    public function cancel(): void
    {
        $this->redirect(route('admin.curricula.index'));
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create New Curriculum" separator>
        <x-slot:actions>
            <x-button
                label="Back to Curricula"
                icon="o-arrow-left"
                wire:click="cancel"
                class="btn-ghost"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Form card -->
    <x-card class="max-w-4xl mx-auto">
        <div class="p-4">
            <form wire:submit="createCurriculum">
                <div class="grid grid-cols-1 gap-6">
                    <!-- Basic information section -->
                    <div>
                        <h3 class="pb-2 mb-4 text-lg font-medium border-b">Basic Information</h3>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <!-- Name field -->
                            <div class="md:col-span-2">
                                <x-input
                                    label="Curriculum Name"
                                    wire:model.live.debounce.500ms="name"
                                    placeholder="Enter curriculum name"
                                    hint="e.g., General Science, Mathematics, Computer Science"
                                    required
                                />
                            </div>

                            <!-- Code field with auto-generation -->
                            <div class="md:col-span-2">
                                <div class="space-y-2">
                                    <x-input
                                        label="Curriculum Code"
                                        wire:model="code"
                                        placeholder="Auto-generated or enter custom code"
                                        hint="Unique identifier for the curriculum"
                                        required
                                    >
                                        <x-slot:append>
                                            <x-button
                                                icon="o-arrow-path"
                                                wire:click="regenerateCode"
                                                color="secondary"
                                                size="sm"
                                                tooltip="Regenerate code"
                                            />
                                        </x-slot:append>
                                    </x-input>

                                    <!-- Auto-generation toggle -->
                                    <div class="flex items-center gap-2">
                                        <x-checkbox
                                            wire:model.live="autoGenerateCode"
                                            label="Auto-generate code from name"
                                            size="sm"
                                        />
                                        @if($autoGenerateCode)
                                            <x-badge label="Auto" color="success" size="sm" />
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Description section -->
                    <div>
                        <h3 class="pb-2 mb-4 text-lg font-medium border-b">Description</h3>

                        <div>
                            <x-textarea
                                label="Curriculum Description"
                                wire:model="description"
                                placeholder="Enter curriculum description"
                                hint="Provide details about the curriculum's objectives, outcomes, and structure"
                                rows="6"
                            />
                        </div>
                    </div>

                    <!-- Code generation preview -->
                    @if($name && $autoGenerateCode)
                    <div class="p-4 border rounded-lg bg-gray-50">
                        <h4 class="mb-2 font-medium text-gray-700">Code Generation Preview</h4>
                        <div class="text-sm text-gray-600">
                            <strong>Name:</strong> {{ $name }}<br>
                            <strong>Generated Code:</strong>
                            <span class="px-2 py-1 font-mono text-blue-800 bg-blue-100 rounded">{{ $code ?: 'Enter name to see preview' }}</span>
                        </div>
                    </div>
                    @endif

                    <!-- Form actions -->
                    <div class="flex justify-end mt-6 space-x-2">
                        <x-button
                            label="Cancel"
                            wire:click="cancel"
                            class="btn-ghost"
                        />
                        <x-button
                            label="Create Curriculum"
                            icon="o-plus"
                            type="submit"
                            color="primary"
                            spinner="createCurriculum"
                        />
                    </div>
                </div>
            </form>
        </div>
    </x-card>
</div>
