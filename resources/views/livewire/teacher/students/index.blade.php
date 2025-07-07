<?php
// resources/views/livewire/teacher/parents/index.blade.php

use App\Models\ClientProfile;
use App\Models\ChildProfile;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Student Parents')] class extends Component {
    use Toast, WithPagination;

    public string $search = '';
    public string $contactMethod = '';
    public string $sortBy = 'name';
    public string $sortDirection = 'asc';

    public function mount(): void
    {
        //
    }

    public function getParentsProperty()
    {
        $teacherProfile = auth()->user()->teacherProfile;

        if (!$teacherProfile) {
            return ClientProfile::query()->where('id', 0); // Return empty query
        }

        // Get parents whose children are taught by this teacher
        $query = ClientProfile::with(['user', 'children.currentEnrollment.curriculum'])
            ->whereHas('children', function($childQuery) use ($teacherProfile) {
                $childQuery->whereHas('programEnrollments', function($enrollmentQuery) use ($teacherProfile) {
                    $enrollmentQuery->where('status', 'Active')
                        ->whereHas('academicYear', function($yearQuery) {
                            $yearQuery->where('is_current', true);
                        })
                        ->whereHas('curriculum.subjects.timetableSlots', function($slotQuery) use ($teacherProfile) {
                            $slotQuery->where('teacher_profile_id', $teacherProfile->id);
                        });
                });
            });

        if ($this->search) {
            $query->search($this->search);
        }

        if ($this->contactMethod) {
            $query->byContactMethod($this->contactMethod);
        }

        // Sorting
        if ($this->sortBy === 'name') {
            $query->join('users', 'client_profiles.user_id', '=', 'users.id')
                  ->orderBy('users.name', $this->sortDirection)
                  ->select('client_profiles.*');
        } else {
            $query->orderBy($this->sortBy, $this->sortDirection);
        }

        return $query->paginate(12);
    }

    public function getMyStudentsForParent($parentId): Collection
    {
        $teacherProfile = auth()->user()->teacherProfile;

        if (!$teacherProfile) {
            return collect();
        }

        $parent = ClientProfile::find($parentId);
        if (!$parent) {
            return collect();
        }

        return $parent->children()
            ->whereHas('programEnrollments', function($enrollmentQuery) use ($teacherProfile) {
                $enrollmentQuery->where('status', 'Active')
                    ->whereHas('academicYear', function($yearQuery) {
                        $yearQuery->where('is_current', true);
                    })
                    ->whereHas('curriculum.subjects.timetableSlots', function($slotQuery) use ($teacherProfile) {
                        $slotQuery->where('teacher_profile_id', $teacherProfile->id);
                    });
            })
            ->with('currentEnrollment.curriculum')
            ->get();
    }

    public function sortBy($field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedContactMethod(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->contactMethod = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Student Parents" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <!-- Search -->
                <div class="flex-1 max-w-md">
                    <x-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search parents..."
                        icon="o-magnifying-glass"
                        clearable
                    />
                </div>

                <!-- Contact Method Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="contactMethod"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Contact Methods</option>
                        <option value="email">Email</option>
                        <option value="phone">Phone</option>
                        <option value="sms">SMS</option>
                    </select>
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Reset Filters"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="btn-ghost btn-sm"
            />
            <x-button
                label="Export Contacts"
                icon="o-arrow-down-tray"
                class="btn-outline"
            />
        </x-slot:actions>
    </x-header>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-3">
        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Total Parents</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->parents->total() }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Phone Contacts</p>
                    <p class="text-lg font-semibold text-gray-900">
                        {{ $this->parents->filter(fn($p) => !empty($p->phone))->count() }}
                    </p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-purple-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Email Contacts</p>
                    <p class="text-lg font-semibold text-gray-900">
                        {{ $this->parents->filter(fn($p) => !empty($p->user->email))->count() }}
                    </p>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Parents Grid -->
    <div class="bg-white rounded-lg shadow">
        <div class="grid grid-cols-1 gap-6 p-6 md:grid-cols-2 lg:grid-cols-3">
            @forelse($this->parents as $parent)
                <div class="p-4 transition-shadow border border-gray-200 rounded-lg hover:shadow-md">
                    <!-- Parent Info -->
                    <div class="flex items-center mb-4">
                        <div class="flex items-center justify-center w-12 h-12 bg-green-100 rounded-full">
                            <span class="text-lg font-semibold text-green-600">
                                {{ strtoupper(substr($parent->user->name, 0, 2)) }}
                            </span>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-gray-900 truncate">
                                {{ $parent->user->name }}
                            </h3>
                            <p class="text-xs text-gray-500">
                                {{ $parent->relationship_to_children ?? 'Parent' }}
                            </p>
                        </div>
                    </div>

                    <!-- Contact Details -->
                    <div class="mb-4 space-y-2">
                        <div class="flex items-center text-xs text-gray-600">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            {{ $parent->user->email }}
                        </div>

                        @if($parent->phone)
                            <div class="flex items-center text-xs text-gray-600">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                </svg>
                                {{ $parent->phone }}
                            </div>
                        @endif

                        @if($parent->preferred_contact_method)
                            <div class="flex items-center text-xs text-gray-600">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                                </svg>
                                Prefers {{ ucfirst($parent->preferred_contact_method) }}
                            </div>
                        @endif
                    </div>

                    <!-- Children taught by this teacher -->
                    <div class="mb-4">
                        <h4 class="mb-2 text-xs font-medium text-gray-700">My Students:</h4>
                        <div class="space-y-1">
                            @foreach($this->getMyStudentsForParent($parent->id) as $child)
                                <div class="flex items-center justify-between text-xs text-gray-600">
                                    <span>{{ $child->full_name }}</span>
                                    @if($child->currentEnrollment && $child->currentEnrollment->curriculum)
                                        <span class="px-2 py-1 text-xs text-blue-800 bg-blue-100 rounded-full">
                                            {{ $child->currentEnrollment->curriculum->code ?? $child->currentEnrollment->curriculum->name }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex space-x-2">
                        <x-button
                            icon="o-envelope"
                            tooltip="Send Email"
                            class="btn-xs btn-ghost"
                        />
                        <x-button
                            icon="o-phone"
                            tooltip="Call"
                            class="btn-xs btn-ghost"
                        />
                        <x-button
                            icon="o-eye"
                            tooltip="View Details"
                            class="btn-xs btn-ghost"
                        />
                    </div>
                </div>
            @empty
                <div class="col-span-full">
                    <div class="py-12 text-center">
                        <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No parents found</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            @if($search || $contactMethod)
                                Try adjusting your search or filter criteria.
                            @else
                                No parent contacts available for your students.
                            @endif
                        </p>
                    </div>
                </div>
            @endforelse
        </div>

        <!-- Pagination -->
        @if($this->parents->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $this->parents->links() }}
            </div>
        @endif
    </div>
</div>
