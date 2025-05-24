<?php

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Subjects')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $curriculum = '';

    #[Url]
    public string $level = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public bool $onlyMySubjects = false;

    #[Url]
    public array $sortBy = ['column' => 'name', 'direction' => 'asc'];

    // Load data
    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed subjects page',
            Subject::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Sort data
    public function sortBy(string $column): void
    {
        if ($this->sortBy['column'] === $column) {
            $this->sortBy['direction'] = $this->sortBy['direction'] === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy['column'] = $column;
            $this->sortBy['direction'] = 'asc';
        }
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->curriculum = '';
        $this->level = '';
        $this->onlyMySubjects = false;
        $this->resetPage();
    }

    // Get available curriculums
    public function curriculums()
    {
        return Curriculum::orderBy('name')->get()->map(function ($curriculum) {
            return [
                'id' => $curriculum->id,
                'name' => $curriculum->name
            ];
        });
    }

    // Get available levels
    public function levels()
    {
        return Subject::select('level')
            ->distinct()
            ->orderBy('level')
            ->pluck('level')
            ->filter()
            ->map(function ($level) {
                return [
                    'id' => $level,
                    'name' => $level
                ];
            })
            ->toArray();
    }

    // Request to teach a subject
    public function requestSubject($subjectId)
    {
        try {
            $teacherProfile = Auth::user()->teacherProfile;

            if (!$teacherProfile) {
                $this->error('Teacher profile not found. Please complete your profile first.');
                return;
            }

            // Check if the relationship exists
            if (method_exists($teacherProfile, 'subjectRequests')) {
                // Check if already requested
                $alreadyRequested = $teacherProfile->subjectRequests()->where('subject_id', $subjectId)->exists();

                if ($alreadyRequested) {
                    $this->info('You have already requested to teach this subject.');
                    return;
                }

                // Add the request
                $teacherProfile->subjectRequests()->create([
                    'subject_id' => $subjectId,
                    'status' => 'pending'
                ]);

                // Log activity
                ActivityLog::log(
                    Auth::id(),
                    'request',
                    'Teacher requested to teach a subject',
                    Subject::class,
                    $subjectId,
                    ['ip' => request()->ip()]
                );

                $this->success('Your request to teach this subject has been submitted.');
            } else {
                $this->error('Unable to process subject request. Please contact an administrator.');
            }
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Check if teacher is teaching a subject
    public function isTeachingSubject($subjectId)
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return false;
        }

        try {
            // Check if the relationship exists
            if (method_exists($teacherProfile, 'subjects')) {
                return $teacherProfile->subjects()->where('subjects.id', $subjectId)->exists();
            }
        } catch (\Exception $e) {
            // Log the error - likely a missing table
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error checking subject teaching status: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return false;
        }

        return false;
    }

    // Check if teacher has requested a subject
    public function hasRequestedSubject($subjectId)
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return false;
        }

        // Check if the relationship exists
        if (method_exists($teacherProfile, 'subjectRequests')) {
            return $teacherProfile->subjectRequests()->where('subject_id', $subjectId)->exists();
        }

        return false;
    }

    // View subject details
    public function viewSubject($subjectId)
    {
        return redirect()->route('teacher.subjects.show', $subjectId);
    }

    // Get subjects with filtering
    public function subjects()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        $query = Subject::query()
            ->with(['curriculum'])
            ->when($this->search, function (Builder $query) {
                $query->where(function($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('code', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->curriculum, function (Builder $query) {
                $query->where('curriculum_id', $this->curriculum);
            })
            ->when($this->level, function (Builder $query) {
                $query->where('level', $this->level);
            });

        // Filter by teacher's subjects if selected and relationship exists
        if ($this->onlyMySubjects && $teacherProfile && method_exists($teacherProfile, 'subjects')) {
            try {
                $subjectIds = $teacherProfile->subjects()->pluck('subjects.id')->toArray();
                $query->whereIn('id', $subjectIds);
            } catch (\Exception $e) {
                // If table doesn't exist, log error and turn off filter
                ActivityLog::log(
                    Auth::id(),
                    'error',
                    'Error filtering subjects: ' . $e->getMessage(),
                    TeacherProfile::class,
                    $teacherProfile->id,
                    ['ip' => request()->ip()]
                );

                $this->onlyMySubjects = false;
                $this->error('Unable to filter by your subjects. The required database table may not exist yet.');
            }
        }

        return $query->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Get subject statistics
    public function subjectStats()
    {
        $teacherProfile = Auth::user()->teacherProfile;
        $total = Subject::count();

        if (!$teacherProfile) {
            return [
                'total' => $total,
                'teaching' => 0,
                'requested' => 0,
                'available' => $total
            ];
        }

        $teaching = 0;
        $requested = 0;

        // Get count of subjects teacher is teaching
        if (method_exists($teacherProfile, 'subjects')) {
            try {
                $teaching = $teacherProfile->subjects()->count();
            } catch (\Exception $e) {
                // Log error but continue with default values
                ActivityLog::log(
                    Auth::id(),
                    'error',
                    'Error counting teaching subjects: ' . $e->getMessage(),
                    TeacherProfile::class,
                    $teacherProfile->id,
                    ['ip' => request()->ip()]
                );
            }
        }

        // Get count of subjects teacher has requested
        if (method_exists($teacherProfile, 'subjectRequests')) {
            try {
                $requested = $teacherProfile->subjectRequests()->count();
            } catch (\Exception $e) {
                // Log error but continue with default values
                ActivityLog::log(
                    Auth::id(),
                    'error',
                    'Error counting requested subjects: ' . $e->getMessage(),
                    TeacherProfile::class,
                    $teacherProfile->id,
                    ['ip' => request()->ip()]
                );
            }
        }

        return [
            'total' => $total,
            'teaching' => $teaching,
            'requested' => $requested,
            'available' => $total - $teaching - $requested
        ];
    }

    public function with(): array
    {
        return [
            'subjects' => $this->subjects(),
            'curriculums' => $this->curriculums(),
            'levels' => $this->levels(),
            'subjectStats' => $this->subjectStats(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Subjects" separator progress-indicator>
        <x-slot:subtitle>
            View and manage your teaching subjects
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search subjects..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$curriculum, $level, $onlyMySubjects]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- QUICK STATS PANEL -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-book-open" class="w-8 h-8" />
            </div>
            <div class="stat-title">Total Subjects</div>
            <div class="stat-value">{{ $subjectStats['total'] }}</div>
            <div class="stat-desc">Available in the curriculum</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-academic-cap" class="w-8 h-8" />
            </div>
            <div class="stat-title">Teaching</div>
            <div class="stat-value text-success">{{ $subjectStats['teaching'] }}</div>
            <div class="stat-desc">Currently teaching</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-warning">
                <x-icon name="o-clock" class="w-8 h-8" />
            </div>
            <div class="stat-title">Requested</div>
            <div class="stat-value text-warning">{{ $subjectStats['requested'] }}</div>
            <div class="stat-desc">Pending approval</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-plus-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Available</div>
            <div class="stat-value text-info">{{ $subjectStats['available'] }}</div>
            <div class="stat-desc">Available to request</div>
        </div>
    </div>

    <!-- FILTER TOGGLE -->
    <div class="flex items-center justify-end mb-4">
        <x-toggle
            label="Show only my subjects"
            wire:model.live="onlyMySubjects"
            hint="Toggle to show only subjects you're teaching"
        />
    </div>

    <!-- SUBJECTS TABLE -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('name')">
                            <div class="flex items-center">
                                Subject Name
                                @if ($sortBy['column'] === 'name')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('code')">
                            <div class="flex items-center">
                                Code
                                @if ($sortBy['column'] === 'code')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('level')">
                            <div class="flex items-center">
                                Level
                                @if ($sortBy['column'] === 'level')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Curriculum</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subjects as $subject)
                        <tr class="hover">
                            <td>{{ $subject->name }}</td>
                            <td>{{ $subject->code }}</td>
                            <td>{{ $subject->level }}</td>
                            <td>{{ $subject->curriculum?->name ?? 'N/A' }}</td>
                            <td>
                                @if ($this->isTeachingSubject($subject->id))
                                    <x-badge label="Teaching" color="success" />
                                @elseif ($this->hasRequestedSubject($subject->id))
                                    <x-badge label="Requested" color="warning" />
                                @else
                                    <x-badge label="Available" color="info" />
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View Subject Details"
                                        wire:click="viewSubject({{ $subject->id }})"
                                    />

                                    @if (!$this->isTeachingSubject($subject->id) && !$this->hasRequestedSubject($subject->id))
                                        <x-button
                                            icon="o-hand-raised"
                                            color="primary"
                                            size="sm"
                                            tooltip="Request to Teach"
                                            wire:click="requestSubject({{ $subject->id }})"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-book-open" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No subjects found</h3>
                                    <p class="text-gray-500">No records match your current filters</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $subjects->links() }}
        </div>
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search subjects"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Subject name or code..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by curriculum"
                    placeholder="All curriculums"
                    :options="$curriculums"
                    wire:model.live="curriculum"
                    option-label="name"
                    option-value="id"
                    empty-message="No curriculums found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by level"
                    placeholder="All levels"
                    :options="$levels"
                    wire:model.live="level"
                    option-label="name"
                    option-value="id"
                    empty-message="No levels found"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[10, 25, 50, 100]"
                    wire:model.live="perPage"
                />
            </div>

            <div>
                <x-toggle
                    label="Show only my subjects"
                    wire:model.live="onlyMySubjects"
                    hint="Toggle to show only subjects you're teaching"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
