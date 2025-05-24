<?php

use App\Models\ExamResult;
use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Exams Dashboard')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $child = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $period = 'month';

    #[Url]
    public string $startDate = '';

    #[Url]
    public string $endDate = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public function mount(): void
    {
        // Default dates if not set
        if (empty($this->startDate)) {
            $this->startDate = Carbon::now()->subMonths(3)->format('Y-m-d');
        }

        if (empty($this->endDate)) {
            $this->endDate = Carbon::now()->format('Y-m-d');
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Parent accessed exams dashboard',
            ExamResult::class,
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

    // Set time period
    public function setPeriod(string $period): void
    {
        $this->period = $period;

        switch ($period) {
            case 'week':
                $this->startDate = Carbon::now()->startOfWeek()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfWeek()->format('Y-m-d');
                break;
            case 'month':
                $this->startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfMonth()->format('Y-m-d');
                break;
            case 'quarter':
                $this->startDate = Carbon::now()->startOfQuarter()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfQuarter()->format('Y-m-d');
                break;
            case 'semester':
                $this->startDate = Carbon::now()->subMonths(6)->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
                break;
            case 'year':
                $this->startDate = Carbon::now()->startOfYear()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfYear()->format('Y-m-d');
                break;
            case 'all':
                $this->startDate = Carbon::now()->subYears(3)->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
                break;
            case 'custom':
                // Keep existing dates
                break;
        }
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->child = '';
        $this->status = '';
        $this->period = 'month';
        $this->startDate = Carbon::now()->subMonths(3)->format('Y-m-d');
        $this->endDate = Carbon::now()->format('Y-m-d');
        $this->resetPage();
    }

    // Get children for this parent
    public function children()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return collect();
        }

        return ChildProfile::where('parent_profile_id', $parentProfile->id)
            ->with('user')
            ->get()
            ->map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->user?->name ?? 'Unknown'
                ];
            });
    }

    // Get filtered exam results
    public function examResults(): LengthAwarePaginator
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        return ExamResult::query()
            ->whereIn('child_profile_id', $childrenIds)
            ->with(['childProfile.user'])
            ->whereBetween('created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
            ->when($this->search, function (Builder $query) {
                $query->where(function($q) {
                    $q->where('title', 'like', '%' . $this->search . '%')
                      ->orWhere('comments', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->when($this->status, function (Builder $query) {
                if ($this->status === 'passed') {
                    $query->where('score', '>=', 60);
                } elseif ($this->status === 'failed') {
                    $query->where('score', '<', 60);
                }
            })
            ->when($this->sortBy['column'] === 'child', function (Builder $query) {
                $query->join('child_profiles', 'exam_results.child_profile_id', '=', 'child_profiles.id')
                    ->join('users', 'child_profiles.user_id', '=', 'users.id')
                    ->orderBy('users.name', $this->sortBy['direction'])
                    ->select('exam_results.*');
            }, function (Builder $query) {
                $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
            })
            ->paginate($this->perPage);
    }

    // Get exam statistics
    public function examStats()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'avgScore' => 0,
                'highestScore' => 0,
                'lowestScore' => 0,
                'passRate' => 0
            ];
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'avgScore' => 0,
                'highestScore' => 0,
                'lowestScore' => 0,
                'passRate' => 0
            ];
        }

        $results = ExamResult::query()
            ->whereIn('child_profile_id', $childrenIds)
            ->whereBetween('created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->get();

        $total = $results->count();

        if ($total === 0) {
            return [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'avgScore' => 0,
                'highestScore' => 0,
                'lowestScore' => 0,
                'passRate' => 0
            ];
        }

        $passed = $results->where('score', '>=', 60)->count();
        $failed = $total - $passed;
        $avgScore = round($results->avg('score'), 1);
        $highestScore = $results->max('score');
        $lowestScore = $results->min('score');
        $passRate = round(($passed / $total) * 100);

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'avgScore' => $avgScore,
            'highestScore' => $highestScore,
            'lowestScore' => $lowestScore,
            'passRate' => $passRate
        ];
    }

    // Get child-specific exam stats
    public function childExamStats()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return [];
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return [];
        }

        // Get all children with their users
        $children = ChildProfile::whereIn('id', $childrenIds)
            ->with('user')
            ->get();

        $stats = [];

        foreach ($children as $child) {
            $results = ExamResult::query()
                ->where('child_profile_id', $child->id)
                ->whereBetween('created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
                ->get();

            $total = $results->count();

            if ($total > 0) {
                $passed = $results->where('score', '>=', 60)->count();
                $failed = $total - $passed;
                $avgScore = round($results->avg('score'), 1);
                $highestScore = $results->max('score');
                $lowestScore = $results->min('score');
                $passRate = round(($passed / $total) * 100);

                $stats[] = [
                    'id' => $child->id,
                    'name' => $child->user?->name ?? 'Unknown',
                    'total' => $total,
                    'passed' => $passed,
                    'failed' => $failed,
                    'avgScore' => $avgScore,
                    'highestScore' => $highestScore,
                    'lowestScore' => $lowestScore,
                    'passRate' => $passRate
                ];
            }
        }

        return $stats;
    }

    public function with(): array
    {
        return [
            'examResults' => $this->examResults(),
            'children' => $this->children(),
            'examStats' => $this->examStats(),
            'childExamStats' => $this->childExamStats(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Exams Dashboard" separator progress-indicator>
        <x-slot:subtitle>
            View and track your children's exam results and academic performance
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search by exam title..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$child, $status, $period !== 'month']))"
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
                <x-icon name="o-academic-cap" class="w-8 h-8" />
            </div>
            <div class="stat-title">Exams Taken</div>
            <div class="stat-value">{{ $examStats['total'] }}</div>
            <div class="stat-desc">Total exams in period</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Pass Rate</div>
            <div class="stat-value text-success">{{ $examStats['passRate'] }}%</div>
            <div class="stat-desc">{{ $examStats['passed'] }} of {{ $examStats['total'] }} passed</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-chart-bar" class="w-8 h-8" />
            </div>
            <div class="stat-title">Average Score</div>
            <div class="stat-value text-info">{{ $examStats['avgScore'] }}%</div>
            <div class="stat-desc">Overall performance</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-secondary">
                <x-icon name="o-trophy" class="w-8 h-8" />
            </div>
            <div class="stat-title">Highest Score</div>
            <div class="stat-value text-secondary">{{ $examStats['highestScore'] }}%</div>
            <div class="stat-desc">Best performance</div>
        </div>
    </div>

    <!-- DATE RANGE SELECTOR -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex flex-wrap gap-2">
            <x-button
                label="This Week"
                @click="$wire.setPeriod('week')"
                class="{{ $period === 'week' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Month"
                @click="$wire.setPeriod('month')"
                class="{{ $period === 'month' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Quarter"
                @click="$wire.setPeriod('quarter')"
                class="{{ $period === 'quarter' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Last 6 Months"
                @click="$wire.setPeriod('semester')"
                class="{{ $period === 'semester' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Year"
                @click="$wire.setPeriod('year')"
                class="{{ $period === 'year' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="All Time"
                @click="$wire.setPeriod('all')"
                class="{{ $period === 'all' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
        </div>

        <div class="flex items-center gap-2">
            <x-input type="date" wire:model.live="startDate" />
            <span>to</span>
            <x-input type="date" wire:model.live="endDate" />
            <x-button
                label="Apply"
                icon="o-check"
                @click="$wire.setPeriod('custom')"
                class="btn-primary"
                size="sm"
            />
        </div>
    </div>

    <!-- EXAM RESULTS TABLE -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('created_at')">
                            <div class="flex items-center">
                                Date
                                @if ($sortBy['column'] === 'created_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('child')">
                            <div class="flex items-center">
                                Child
                                @if ($sortBy['column'] === 'child')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('title')">
                            <div class="flex items-center">
                                Exam
                                @if ($sortBy['column'] === 'title')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('score')">
                            <div class="flex items-center">
                                Score
                                @if ($sortBy['column'] === 'score')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Feedback</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($examResults as $result)
                        <tr class="hover">
                            <td>{{ $result->created_at->format('d/m/y') }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-10 h-10 mask mask-squircle">
                                            @if ($result->childProfile->photo)
                                                <img src="{{ asset('storage/' . $result->childProfile->photo) }}" alt="{{ $result->childProfile->user?->name ?? 'Child' }}">
                                            @else
                                                <img src="{{ $result->childProfile->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $result->childProfile->user?->name ?? 'Child' }}">
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        {{ $result->childProfile->user?->name ?? 'Unknown Child' }}
                                    </div>
                                </div>
                            </td>
                            <td>{{ $result->title ?? 'Untitled Exam' }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="text-lg font-bold {{ $result->score >= 60 ? 'text-success' : 'text-error' }}">
                                        {{ $result->score }}%
                                    </div>
                                    <x-badge
                                        label="{{ $result->score >= 60 ? 'Passed' : 'Failed' }}"
                                        color="{{ $result->score >= 60 ? 'success' : 'error' }}"
                                    />
                                </div>
                            </td>
                            <td>
                                @if($result->comments)
                                    <div class="tooltip" data-tip="{{ $result->comments }}">
                                        <x-button
                                            icon="o-document-text"
                                            color="ghost"
                                            size="sm"
                                            @click="$dispatch('openModal', { component: 'parent.exams.view-feedback-modal', arguments: { examId: {{ $result->id }} }})"
                                        />
                                    </div>
                                @else
                                    <span class="text-gray-400">No feedback</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-academic-cap" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No exam results found</h3>
                                    <p class="text-gray-500">No records match your current filters for the selected time period</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $examResults->links() }}
        </div>
    </x-card>

    <!-- PERFORMANCE BY CHILD -->
    @if(count($childExamStats) > 0)
        <div class="mt-8">
            <h2 class="mb-4 text-xl font-bold">Performance by Child</h2>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach($childExamStats as $childStat)
                    <x-card>
                        <div class="flex items-center gap-4 mb-4">
                            <div class="avatar">
                                <div class="w-16 h-16 mask mask-squircle">
                                    <!-- Use default avatar for now -->
                                    <img src="https://ui-avatars.com/api/?name={{ urlencode($childStat['name']) }}&color=7F9CF5&background=EBF4FF" alt="{{ $childStat['name'] }}">
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold">{{ $childStat['name'] }}</h3>
                                <div class="text-sm text-gray-500">{{ $childStat['total'] }} exams taken</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="p-3 border rounded-lg">
                                <div class="text-xl font-bold {{ $childStat['passRate'] >= 60 ? 'text-success' : 'text-error' }}">{{ $childStat['passRate'] }}%</div>
                                <div class="text-sm text-gray-500">Pass Rate</div>
                            </div>
                            <div class="p-3 border rounded-lg">
                                <div class="text-xl font-bold {{ $childStat['avgScore'] >= 60 ? 'text-success' : 'text-error' }}">{{ $childStat['avgScore'] }}%</div>
                                <div class="text-sm text-gray-500">Average Score</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-2 mb-4">
                            <div>
                                <div class="text-sm text-gray-500">Passed</div>
                                <div class="text-lg font-semibold text-success">{{ $childStat['passed'] }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Failed</div>
                                <div class="text-lg font-semibold text-error">{{ $childStat['failed'] }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Best Score</div>
                                <div class="text-lg font-semibold">{{ $childStat['highestScore'] }}%</div>
                            </div>
                        </div>

                        <div class="h-2 overflow-hidden bg-gray-200 rounded-full">
                            <div class="h-full bg-success" style="width: {{ $childStat['passRate'] }}%"></div>
                        </div>
                    </x-card>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by exam title"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by child"
                    placeholder="All children"
                    :options="$children"
                    wire:model.live="child"
                    option-label="name"
                    option-value="id"
                    empty-message="No children found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    placeholder="All statuses"
                    :options="[
                        ['label' => 'Passed', 'value' => 'passed'],
                        ['label' => 'Failed', 'value' => 'failed']
                    ]"
                    wire:model.live="status"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[10, 25, 50, 100]"
                    wire:model.live="perPage"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
