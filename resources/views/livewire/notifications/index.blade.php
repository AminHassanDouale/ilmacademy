<?php

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Notifications')] class extends Component {
    use WithPagination, Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public int $perPage = 25;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Bulk actions
    public array $selectedNotifications = [];
    public bool $selectAll = false;

    // Stats
    public array $stats = [];

    public function mount(): void
    {
        // Load stats
        $this->loadStats();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed notifications page',
            DatabaseNotification::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    protected function loadStats(): void
    {
        try {
            $user = Auth::user();

            $this->stats = [
                'total' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
                'read' => $user->readNotifications()->count(),
                'today' => $user->notifications()->whereDate('created_at', today())->count(),
                'this_week' => $user->notifications()->whereBetween('created_at', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])->count(),
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total' => 0,
                'unread' => 0,
                'read' => 0,
                'today' => 0,
                'this_week' => 0,
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    // Handle select all checkbox
    public function updatedSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedNotifications = $this->getNotifications()->pluck('id')->toArray();
        } else {
            $this->selectedNotifications = [];
        }
    }

    // Mark notification as read
    public function markAsRead(string $notificationId): void
    {
        try {
            $notification = Auth::user()->notifications()->find($notificationId);

            if ($notification && is_null($notification->read_at)) {
                $notification->markAsRead();

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Marked notification as read",
                    DatabaseNotification::class,
                    $notificationId,
                    ['notification_type' => $notification->type]
                );

                $this->loadStats();
                $this->success('Notification marked as read.');
            }
        } catch (\Exception $e) {
            $this->error('Error marking notification as read: ' . $e->getMessage());
        }
    }

    // Mark notification as unread
    public function markAsUnread(string $notificationId): void
    {
        try {
            $notification = Auth::user()->notifications()->find($notificationId);

            if ($notification && !is_null($notification->read_at)) {
                $notification->update(['read_at' => null]);

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Marked notification as unread",
                    DatabaseNotification::class,
                    $notificationId,
                    ['notification_type' => $notification->type]
                );

                $this->loadStats();
                $this->success('Notification marked as unread.');
            }
        } catch (\Exception $e) {
            $this->error('Error marking notification as unread: ' . $e->getMessage());
        }
    }

    // Delete notification
    public function deleteNotification(string $notificationId): void
    {
        try {
            $notification = Auth::user()->notifications()->find($notificationId);

            if ($notification) {
                $notificationType = $notification->type;
                $notification->delete();

                ActivityLog::log(
                    Auth::id(),
                    'delete',
                    "Deleted notification",
                    DatabaseNotification::class,
                    $notificationId,
                    ['notification_type' => $notificationType]
                );

                $this->loadStats();
                $this->success('Notification deleted.');
            }
        } catch (\Exception $e) {
            $this->error('Error deleting notification: ' . $e->getMessage());
        }
    }

    // Bulk mark as read
    public function bulkMarkAsRead(): void
    {
        if (empty($this->selectedNotifications)) {
            $this->error('Please select notifications to mark as read.');
            return;
        }

        try {
            $updated = Auth::user()->notifications()
                ->whereIn('id', $this->selectedNotifications)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            ActivityLog::log(
                Auth::id(),
                'bulk_update',
                "Bulk marked {$updated} notifications as read",
                DatabaseNotification::class,
                null,
                [
                    'notification_ids' => $this->selectedNotifications,
                    'count' => $updated
                ]
            );

            $this->selectedNotifications = [];
            $this->selectAll = false;
            $this->loadStats();
            $this->success("Marked {$updated} notifications as read.");

        } catch (\Exception $e) {
            $this->error('Error marking notifications as read: ' . $e->getMessage());
        }
    }

    // Bulk delete
    public function bulkDelete(): void
    {
        if (empty($this->selectedNotifications)) {
            $this->error('Please select notifications to delete.');
            return;
        }

        try {
            $deleted = Auth::user()->notifications()
                ->whereIn('id', $this->selectedNotifications)
                ->delete();

            ActivityLog::log(
                Auth::id(),
                'bulk_delete',
                "Bulk deleted {$deleted} notifications",
                DatabaseNotification::class,
                null,
                [
                    'notification_ids' => $this->selectedNotifications,
                    'count' => $deleted
                ]
            );

            $this->selectedNotifications = [];
            $this->selectAll = false;
            $this->loadStats();
            $this->success("Deleted {$deleted} notifications.");

        } catch (\Exception $e) {
            $this->error('Error deleting notifications: ' . $e->getMessage());
        }
    }

    // Mark all as read
    public function markAllAsRead(): void
    {
        try {
            $updated = Auth::user()->unreadNotifications()->update(['read_at' => now()]);

            ActivityLog::log(
                Auth::id(),
                'bulk_update',
                "Marked all {$updated} notifications as read",
                DatabaseNotification::class,
                null,
                ['count' => $updated]
            );

            $this->loadStats();
            $this->success("Marked all {$updated} notifications as read.");

        } catch (\Exception $e) {
            $this->error('Error marking all notifications as read: ' . $e->getMessage());
        }
    }

    // Delete all read notifications
    public function deleteAllRead(): void
    {
        try {
            $deleted = Auth::user()->readNotifications()->delete();

            ActivityLog::log(
                Auth::id(),
                'bulk_delete',
                "Deleted all {$deleted} read notifications",
                DatabaseNotification::class,
                null,
                ['count' => $deleted]
            );

            $this->loadStats();
            $this->success("Deleted {$deleted} read notifications.");

        } catch (\Exception $e) {
            $this->error('Error deleting read notifications: ' . $e->getMessage());
        }
    }

    // Get filtered and paginated notifications
    protected function getNotifications(): LengthAwarePaginator
    {
        return Auth::user()->notifications()
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('data->title', 'like', "%{$this->search}%")
                      ->orWhere('data->message', 'like', "%{$this->search}%")
                      ->orWhere('type', 'like', "%{$this->search}%");
                });
            })
            ->when($this->typeFilter, function (Builder $query) {
                $query->where('type', 'like', "%{$this->typeFilter}%");
            })
            ->when($this->statusFilter, function (Builder $query) {
                if ($this->statusFilter === 'read') {
                    $query->whereNotNull('read_at');
                } elseif ($this->statusFilter === 'unread') {
                    $query->whereNull('read_at');
                }
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Get available notification types for filter
    protected function getNotificationTypes()
    {
        try {
            return Auth::user()->notifications()
                ->select('type')
                ->distinct()
                ->get()
                ->map(function ($notification) {
                    $type = class_basename($notification->type);
                    return [
                        'id' => $notification->type,
                        'name' => $this->formatNotificationType($type)
                    ];
                })
                ->sortBy('name')
                ->values();
        } catch (\Exception $e) {
            return collect();
        }
    }

    // Format notification type for display
    protected function formatNotificationType(string $type): string
    {
        // Convert CamelCase to readable format using modern Laravel Str helper
        return ucwords(str_replace(['_', '-'], ' ', Str::snake($type)));
    }

    // Get notification icon based on type
    public function getNotificationIcon(string $type): string
    {
        $baseType = class_basename($type);

        return match (true) {
            str_contains($baseType, 'User') => 'o-user',
            str_contains($baseType, 'Event') => 'o-calendar',
            str_contains($baseType, 'Payment') => 'o-credit-card',
            str_contains($baseType, 'Message') => 'o-chat-bubble-left',
            str_contains($baseType, 'System') => 'o-cog-6-tooth',
            str_contains($baseType, 'Welcome') => 'o-hand-raised',
            str_contains($baseType, 'Reminder') => 'o-bell',
            str_contains($baseType, 'Assignment') => 'o-document-text',
            str_contains($baseType, 'Grade') => 'o-academic-cap',
            default => 'o-information-circle'
        };
    }

    // Get notification color based on type and status
    public function getNotificationColor(string $type, bool $isRead): string
    {
        if ($isRead) {
            return 'text-gray-500';
        }

        $baseType = class_basename($type);

        return match (true) {
            str_contains($baseType, 'User') => 'text-blue-600',
            str_contains($baseType, 'Event') => 'text-purple-600',
            str_contains($baseType, 'Payment') => 'text-green-600',
            str_contains($baseType, 'Message') => 'text-indigo-600',
            str_contains($baseType, 'System') => 'text-orange-600',
            str_contains($baseType, 'Welcome') => 'text-emerald-600',
            str_contains($baseType, 'Reminder') => 'text-yellow-600',
            str_contains($baseType, 'Assignment') => 'text-red-600',
            str_contains($baseType, 'Grade') => 'text-cyan-600',
            default => 'text-gray-600'
        };
    }

    // Format notification data for display
    public function formatNotificationData($notification): array
    {
        $data = $notification->data;

        return [
            'title' => $data['title'] ?? $this->formatNotificationType(class_basename($notification->type)),
            'message' => $data['message'] ?? $data['body'] ?? 'No message content available',
            'action_text' => $data['action_text'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'metadata' => collect($data)->except(['title', 'message', 'body', 'action_text', 'action_url'])->toArray()
        ];
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->typeFilter = '';
        $this->statusFilter = '';
        $this->selectedNotifications = [];
        $this->selectAll = false;
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'notifications' => $this->getNotifications(),
            'notificationTypes' => $this->getNotificationTypes(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Notifications" subtitle="Manage your notifications and alerts" separator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search notifications..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$typeFilter, $statusFilter]))"
                @click="$wire.showFilters = true"
                class="btn-ghost"
            />

            @if($stats['unread'] > 0)
                <x-button
                    label="Mark All Read"
                    icon="o-check"
                    wire:click="markAllAsRead"
                    wire:confirm="Are you sure you want to mark all notifications as read?"
                    class="btn-primary"
                />
            @endif
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-5">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-bell" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total']) }}</div>
                        <div class="text-sm text-gray-500">Total</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-exclamation-circle" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['unread']) }}</div>
                        <div class="text-sm text-gray-500">Unread</div>
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
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['read']) }}</div>
                        <div class="text-sm text-gray-500">Read</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-calendar" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['today']) }}</div>
                        <div class="text-sm text-gray-500">Today</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-calendar-days" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['this_week']) }}</div>
                        <div class="text-sm text-gray-500">This Week</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Bulk Actions -->
    @if(count($selectedNotifications) > 0)
        <x-card class="mb-6">
            <div class="p-4 bg-blue-50">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-blue-800">
                        {{ count($selectedNotifications) }} notification(s) selected
                    </span>
                    <div class="flex gap-2">
                        <x-button
                            label="Mark as Read"
                            icon="o-check"
                            wire:click="bulkMarkAsRead"
                            class="btn-sm btn-success"
                        />
                        <x-button
                            label="Delete"
                            icon="o-trash"
                            wire:click="bulkDelete"
                            wire:confirm="Are you sure you want to delete the selected notifications?"
                            class="btn-sm btn-error"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    <!-- Quick Actions -->
    @if($stats['read'] > 0 || $stats['unread'] > 0)
        <x-card class="mb-6">
            <div class="p-4">
                <h3 class="mb-3 text-lg font-semibold">Quick Actions</h3>
                <div class="flex flex-wrap gap-2">
                    @if($stats['unread'] > 0)
                        <x-button
                            label="Mark All as Read ({{ $stats['unread'] }})"
                            icon="o-check-circle"
                            wire:click="markAllAsRead"
                            wire:confirm="Are you sure you want to mark all notifications as read?"
                            class="btn-outline btn-sm"
                        />
                    @endif

                    @if($stats['read'] > 0)
                        <x-button
                            label="Delete All Read ({{ $stats['read'] }})"
                            icon="o-trash"
                            wire:click="deleteAllRead"
                            wire:confirm="Are you sure you want to delete all read notifications? This action cannot be undone."
                            class="btn-outline btn-sm btn-error"
                        />
                    @endif
                </div>
            </div>
        </x-card>
    @endif

    <!-- Notifications List -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th>
                            <x-checkbox wire:model.live="selectAll" />
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('created_at')">
                            <div class="flex items-center">
                                Date
                                @if ($sortBy['column'] === 'created_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($notifications as $notification)
                        @php
                            $isRead = !is_null($notification->read_at);
                            $data = $this->formatNotificationData($notification);
                        @endphp
                        <tr class="hover {{ $isRead ? 'opacity-75' : 'bg-blue-50' }}">
                            <td>
                                <x-checkbox wire:model.live="selectedNotifications" value="{{ $notification->id }}" />
                            </td>
                            <td>
                                <div class="text-sm">{{ $notification->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $notification->created_at->format('g:i A') }}</div>
                                <div class="text-xs text-gray-400">{{ $notification->created_at->diffForHumans() }}</div>
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <x-icon
                                        name="{{ $this->getNotificationIcon($notification->type) }}"
                                        class="w-5 h-5 mr-2 {{ $this->getNotificationColor($notification->type, $isRead) }}"
                                    />
                                    <span class="text-sm {{ $isRead ? 'text-gray-500' : 'text-gray-900' }}">
                                        {{ $this->formatNotificationType(class_basename($notification->type)) }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="max-w-md">
                                    <div class="font-medium {{ $isRead ? 'text-gray-600' : 'text-gray-900' }}">
                                        {{ $data['title'] }}
                                    </div>
                                    <div class="text-sm {{ $isRead ? 'text-gray-500' : 'text-gray-700' }}">
                                        {{ Str::limit($data['message'], 100) }}
                                    </div>
                                    @if($data['action_url'])
                                        <a
                                            href="{{ $data['action_url'] }}"
                                            class="text-xs text-blue-600 hover:text-blue-800"
                                            wire:click="markAsRead('{{ $notification->id }}')"
                                        >
                                            {{ $data['action_text'] ?? 'View Details' }} →
                                        </a>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if($isRead)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <x-icon name="o-check" class="w-3 h-3 mr-1" />
                                        Read
                                    </span>
                                    <div class="text-xs text-gray-500 mt-1">
                                        {{ $notification->read_at->diffForHumans() }}
                                    </div>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <x-icon name="o-exclamation" class="w-3 h-3 mr-1" />
                                        Unread
                                    </span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    @if(!$isRead)
                                        <button
                                            wire:click="markAsRead('{{ $notification->id }}')"
                                            class="btn btn-ghost btn-xs"
                                            title="Mark as read"
                                        >
                                            <x-icon name="o-check" class="w-3 h-3" />
                                        </button>
                                    @else
                                        <button
                                            wire:click="markAsUnread('{{ $notification->id }}')"
                                            class="btn btn-ghost btn-xs"
                                            title="Mark as unread"
                                        >
                                            <x-icon name="o-envelope" class="w-3 h-3" />
                                        </button>
                                    @endif

                                    <button
                                        wire:click="deleteNotification('{{ $notification->id }}')"
                                        wire:confirm="Are you sure you want to delete this notification?"
                                        class="btn btn-ghost btn-xs text-error"
                                        title="Delete"
                                    >
                                        <x-icon name="o-trash" class="w-3 h-3" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-bell-slash" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No notifications found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $typeFilter || $statusFilter)
                                                No notifications match your current filters.
                                            @else
                                                You're all caught up! No notifications to display.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $typeFilter || $statusFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="resetFilters"
                                            class="btn-outline btn-sm"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($notifications->hasPages())
            <div class="mt-4">
                {{ $notifications->links() }}
            </div>
        @endif

        <!-- Results summary -->
        @if($notifications->count() > 0)
            <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
                Showing {{ $notifications->firstItem() ?? 0 }} to {{ $notifications->lastItem() ?? 0 }}
                of {{ $notifications->total() }} notifications
                @if($search || $typeFilter || $statusFilter)
                    (filtered from total)
                @endif
            </div>
        @endif
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Notification Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search notifications"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by title, message, or type..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by type"
                    :options="$notificationTypes->toArray()"
                    wire:model.live="typeFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All types"
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    :options="[
                        ['id' => '', 'name' => 'All notifications'],
                        ['id' => 'unread', 'name' => 'Unread only'],
                        ['id' => 'read', 'name' => 'Read only']
                    ]"
                    wire:model.live="statusFilter"
                    option-value="id"
                    option-label="name"
                />
            </div>

          <div>
                <x-select
                    label="Items per page"
                    :options="[
                        ['id' => 10, 'name' => '10 per page'],
                        ['id' => 25, 'name' => '25 per page'],
                        ['id' => 50, 'name' => '50 per page'],
                        ['id' => 100, 'name' => '100 per page']
                    ]"
                    option-value="id"
                    option-label="name"
                    wire:model.live="perPage"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button
                label="Reset"
                wire:click="resetFilters"
                class="btn-ghost"
            />
            <x-button
                label="Close"
                @click="$wire.showFilters = false"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-drawer>

    <!-- Loading states -->
    <div wire:loading.delay class="fixed top-4 right-4 z-50">
        <div class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg shadow-lg">
            <div class="flex items-center gap-2">
                <div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                Processing...
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for enhanced interactions -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide success messages after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            if (alert.classList.contains('alert-success')) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }
        });
    }, 5000);

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + A to select all
        if ((e.ctrlKey || e.metaKey) && e.key === 'a' && e.target.tagName !== 'INPUT') {
            e.preventDefault();
            @this.selectAll = !@this.selectAll;
            @this.updatedSelectAll();
        }

        // Escape to close filters
        if (e.key === 'Escape') {
            @this.showFilters = false;
        }

        // Ctrl/Cmd + R to mark all as read
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            if (@this.stats.unread > 0) {
                if (confirm('Mark all notifications as read?')) {
                    @this.markAllAsRead();
                }
            }
        }
    });
});

// Real-time notification updates (if using broadcasting)
window.addEventListener('notification-received', function(e) {
    // Refresh the component when new notifications arrive
    @this.$refresh();

    // Show a toast notification
    if (window.showToast) {
        window.showToast('New notification received!', 'info');
    }
});

// Smooth scroll to top when pagination changes
document.addEventListener('livewire:navigated', function() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});
</script>

<!-- Custom styles for enhanced UX -->
