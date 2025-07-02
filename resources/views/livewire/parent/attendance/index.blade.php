<?php

use App\Models\Attendance;
use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Attendance Records')] class extends Component {
    use WithPagination;
    use Toast;

    // Search and filters
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $childFilter = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $perPage = 20;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $childOptions = [];

    public function mount(): void
    {
        // Set default date range (last 30 days)
        if (!$this->dateFrom) {
            $this->dateFrom = now()->subDays(30)->format('Y-m-d');
        }
        if (!$this->dateTo) {
            $this->dateTo = now()->format('Y-m-d');
        }

        // Pre-select child if provided in query
        if (request()->has('child')) {
            $childId = request()->get('child');
            $child = ChildProfile::where('id', $childId)
                ->where('parent_id', Auth::id())
                ->first();

            if ($child) {
                $this->childFilter = (string) $child->id;
            }
        }

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            'Accessed attendance records page'
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Status options
        $this->statusOptions = [
            ['id' => '', 'name' => 'All Statuses'],
            ['id' => 'present', 'name' => 'Present'],
            ['id' => 'absent', 'name' => 'Absent'],
            ['id' => 'late', 'name' => 'Late'],
            ['id' => 'excused', 'name' => 'Excused'],
        ];

        // Child options - only children of the authenticated parent
        try {
            $children = ChildProfile::where('parent_id', Auth::id())
                ->orderBy('first_name')
                ->get();

            $this->childOptions = [
                ['id' => '', 'name' => 'All Children'],
                ...$children->map(fn($child) => [
                    'id' => $child->id,
                    'name' => $child->full_name
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->childOptions = [['id' => '', 'name' => 'All Children']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $attendanceQuery = Attendance::query()
                ->whereHas('childProfile', function ($query) {
                    $query->where('parent_id', Auth::id());
                })
                ->when($this->dateFrom, function ($query) {
                    $query->whereDate('created_at', '>=', $this->dateFrom);
                })
                ->when($this->dateTo, function ($query) {
                    $query->whereDate('created_at', '<=', $this->dateTo);
                });

            $totalRecords = $attendanceQuery->count();
            $presentRecords = $attendanceQuery->where('status', 'present')->count();
            $absentRecords = $attendanceQuery->where('status', 'absent')->count();
            $lateRecords = $attendanceQuery->where('status', 'late')->count();
            $excusedRecords = $attendanceQuery->where('status', 'excused')->count();

            // Calculate attendance rate
            $attendedRecords = $presentRecords + $lateRecords; // Present and late count as attended
            $attendanceRate = $totalRecords > 0 ? round(($attendedRecords / $totalRecords) * 100, 1) : 0;

            // Get unique children with attendance
            $childrenWithAttendance = $attendanceQuery->distinct('child_profile_id')->count('child_profile_id');

            $this->stats = [
                'total_records' => $totalRecords,
                'present_records' => $presentRecords,
                'absent_records' => $absentRecords,
                'late_records' => $lateRecords,
                'excused_records' => $excusedRecords,
                'attendance_rate' => $attendanceRate,
                'children_with_attendance' => $childrenWithAttendance,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_records' => 0,
                'present_records' => 0,
                'absent_records' => 0,
                'late_records' => 0,
                'excused_records' => 0,
                'attendance_rate' => 0,
                'children_with_attendance' => 0,
            ];
        }
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
        $this->resetPage();
    }

    // Update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedChildFilter(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->childFilter = '';
        $this->dateFrom = now()->subDays(30)->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
        $this->resetPage();
        $this->loadStats();
    }

    // Get filtered and paginated attendance records
    public function attendanceRecords(): LengthAwarePaginator
    {
        return Attendance::query()
            ->whereHas('childProfile', function ($query) {
                $query->where('parent_id', Auth::id());
            })
            ->with(['childProfile', 'session.subject'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->whereHas('childProfile', function ($childQuery) {
                        $childQuery->where('first_name', 'like', "%{$this->search}%")
                                  ->orWhere('last_name', 'like', "%{$this->search}%");
                    })
                    ->orWhereHas('session.subject', function ($subjectQuery) {
                        $subjectQuery->where('name', 'like', "%{$this->search}%");
                    })
                    ->orWhere('remarks', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->childFilter, function (Builder $query) {
                $query->where('child_profile_id', $this->childFilter);
            })
            ->when($this->dateFrom, function (Builder $query) {
                $query->whereDate('created_at', '>=', $this->dateFrom);
            })
            ->when($this->dateTo, function (Builder $query) {
                $query->whereDate('created_at', '<=', $this->dateTo);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'present' => 'bg-green-100 text-green-800',
            'absent' => 'bg-red-100 text-red-800',
            'late' => 'bg-yellow-100 text-yellow-800',
            'excused' => 'bg-blue-100 text-blue-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get status icon
    private function getStatusIcon(string $status): string
    {
        return match($status) {
            'present' => 'o-check-circle',
            'absent' => 'o-x-circle',
            'late' => 'o-clock',
            'excused' => 'o-shield-check',
            default => 'o-question-mark-circle'
        };
    }

    public function with(): array
    {
        return [
            'attendanceRecords' => $this->attendanceRecords(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Attendance Records" subtitle="Track your children's attendance and participation" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search attendance..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-4">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-calendar" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_records']) }}</div>
                        <div class="text-sm text-gray-500">Total Records</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-check-circle" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['present_records']) }}</div>
                        <div class="text-sm text-gray-500">Present</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-x-circle" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['absent_records']) }}</div>
                        <div class="text-sm text-gray-500">Absent</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-chart-bar" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ $stats['attendance_rate'] }}%</div>
                        <div class="text-sm text-gray-500">Attendance Rate</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Secondary Stats -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-3">
        <x-card>
            <div class="p-4 text-center">
                <div class="text-xl font-bold text-yellow-600">{{ number_format($stats['late_records']) }}</div>
                <div class="text-sm text-gray-500">Late Arrivals</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-4 text-center">
                <div class="text-xl font-bold text-blue-600">{{ number_format($stats['excused_records']) }}</div>
                <div class="text-sm text-gray-500">Excused Absences</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-4 text-center">
                <div class="text-xl font-bold text-indigo-600">{{ number_format($stats['children_with_attendance']) }}</div>
                <div class="text-sm text-gray-500">Children Tracked</div>
            </div>
        </x-card>
    </div>

    <!-- Filters Row -->
    <div class="mb-6">
        <x-card>
            <div class="p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                    <div>
                        <x-select
                            label="Status"
                            :options="$statusOptions"
                            wire:model.live="statusFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Child"
                            :options="$childOptions"
                            wire:model.live="childFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-input
                            label="From Date"
                            wire:model.live="dateFrom"
                            type="date"
                        />
                    </div>

                    <div>
                        <x-input
                            label="To Date"
                            wire:model.live="dateTo"
                            type="date"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Per Page"
                            :options="[
                                ['id' => 10, 'name' => '10 per page'],
                                ['id' => 20, 'name' => '20 per page'],
                                ['id' => 50, 'name' => '50 per page'],
                                ['id' => 100, 'name' => '100 per page']
                            ]"
                            wire:model.live="perPage"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div class="flex items-end">
                        <x-button
                            label="Clear Filters"
                            icon="o-x-mark"
                            wire:click="clearFilters"
                            class="w-full btn-outline"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Attendance Records Table -->
    @if($attendanceRecords->count() > 0)
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
                            <th>Child</th>
                            <th>Subject/Session</th>
                            <th class="cursor-pointer" wire:click="sortBy('status')">
                                <div class="flex items-center">
                                    Status
                                    @if ($sortBy['column'] === 'status')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th>Remarks</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($attendanceRecords as $record)
                            <tr class="hover">
                                <td>
                                    <div class="font-medium">{{ $record->created_at->format('M d, Y') }}</div>
                                    <div class="text-sm text-gray-500">{{ $record->created_at->format('l') }}</div>
                                </td>
                                <td>
                                    <div class="flex items-center">
                                        <div class="mr-3 avatar placeholder">
                                            <div class="w-8 h-8 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                                <span class="text-xs font-bold">{{ $record->childProfile->initials ?? '??' }}</span>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="font-medium">{{ $record->childProfile->full_name ?? 'Unknown Child' }}</div>
                                            @if($record->childProfile && $record->childProfile->age)
                                                <div class="text-sm text-gray-500">{{ $record->childProfile->age }} years old</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if($record->session && $record->session->subject)
                                        <div class="font-medium">{{ $record->session->subject->name }}</div>
                                        @if($record->session->subject->code)
                                            <div class="text-sm text-gray-500">{{ $record->session->subject->code }}</div>
                                        @endif
                                    @else
                                        <span class="text-gray-500">No session info</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center">
                                        <x-icon name="{{ $this->getStatusIcon($record->status) }}" class="w-4 h-4 mr-2 {{ match($record->status) {
                                            'present' => 'text-green-600',
                                            'absent' => 'text-red-600',
                                            'late' => 'text-yellow-600',
                                            'excused' => 'text-blue-600',
                                            default => 'text-gray-600'
                                        } }}" />
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($record->status) }}">
                                            {{ ucfirst($record->status) }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    @if($record->remarks)
                                        <div class="max-w-xs truncate" title="{{ $record->remarks }}">
                                            {{ $record->remarks }}
                                        </div>
                                    @else
                                        <span class="text-gray-500">-</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="text-sm">{{ $record->created_at->format('g:i A') }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="mt-4">
                {{ $attendanceRecords->links() }}
            </div>

            <!-- Results summary -->
            <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
                Showing {{ $attendanceRecords->firstItem() ?? 0 }} to {{ $attendanceRecords->lastItem() ?? 0 }}
                of {{ $attendanceRecords->total() }} attendance records
                @if($search || $statusFilter || $childFilter || $dateFrom || $dateTo)
                    (filtered)
                @endif
            </div>
        </x-card>
    @else
        <!-- Empty State -->
        <x-card>
            <div class="py-12 text-center">
                <div class="flex flex-col items-center justify-center gap-4">
                    <x-icon name="o-calendar-x" class="w-20 h-20 text-gray-300" />
                    <div>
                        <h3 class="text-lg font-semibold text-gray-600">No attendance records found</h3>
                        <p class="mt-1 text-gray-500">
                            @if($search || $statusFilter || $childFilter || $dateFrom || $dateTo)
                                No attendance records match your current filters.
                            @else
                                Attendance records will appear here once your children start attending sessions.
                            @endif
                        </p>
                    </div>
                    @if($search || $statusFilter || $childFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            class="btn-secondary"
                        />
                    @endif
                </div>
            </div>
        </x-card>
    @endif

    <!-- Export Options (Future Enhancement) -->
    @if($attendanceRecords->count() > 0)
        <div class="mt-6">
            <x-card>
                <div class="p-4">
                    <h3 class="mb-4 text-lg font-medium text-gray-900">Export Options</h3>
                    <div class="flex gap-4">
                        <x-button
                            label="Export to PDF"
                            icon="o-document-arrow-down"
                            class="btn-outline"
                            disabled
                        />
                        <x-button
                            label="Export to Excel"
                            icon="o-table-cells"
                            class="btn-outline"
                            disabled
                        />
                        <x-button
                            label="Email Report"
                            icon="o-envelope"
                            class="btn-outline"
                            disabled
                        />
                    </div>
                    <p class="mt-2 text-sm text-gray-500">Export features coming soon!</p>
                </div>
            </x-card>
        </div>
    @endif
</div>
