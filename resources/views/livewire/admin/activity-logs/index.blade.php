<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    // Filtres et options de recherche
    #[Url]
    public string $search = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $userId = '';

    #[Url]
    public string $date = '';

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public int $perPage = 25;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public function mount(): void
    {
        // Enregistrement de l'accès à cette page
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accès à la page des journaux d\'activité',
            ActivityLog::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Tri des données
    public function sortBy(string $column): void
    {
        if ($this->sortBy['column'] === $column) {
            $this->sortBy['direction'] = $this->sortBy['direction'] === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy['column'] = $column;
            $this->sortBy['direction'] = 'asc';
        }
    }

    // Récupération des journaux d'activité filtrés et paginés
    public function activityLogs(): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->with('user')
            ->when($this->search, function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->where('description', 'like', '%' . $this->search . '%')
                      ->orWhere('ip_address', 'like', '%' . $this->search . '%')
                      ->orWhereHas('user', function (Builder $userQuery) {
                          $userQuery->where('name', 'like', '%' . $this->search . '%')
                                   ->orWhere('email', 'like', '%' . $this->search . '%');
                      });
                });
            })
            ->when($this->action, function (Builder $query) {
                $query->where('action', $this->action);
            })
            ->when($this->userId, function (Builder $query) {
                $query->where('user_id', $this->userId);
            })
            ->when($this->date, function (Builder $query) {
                $date = \Carbon\Carbon::parse($this->date);
                $query->whereDate('created_at', $date);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Récupération des actions disponibles pour filtrage
    public function actions(): Collection
    {
        return ActivityLog::select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');
    }

    // Récupération des utilisateurs pour filtrage
    public function users(): Collection
    {
        return User::orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    // Remise à zéro des filtres
    public function resetFilters(): void
    {
        $this->search = '';
        $this->action = '';
        $this->userId = '';
        $this->date = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'activityLogs' => $this->activityLogs(),
            'actions' => $this->actions(),
            'users' => $this->users(),
        ];
    }
};

?>

<div>
    <!-- En-tête de la page -->
    <x-header title="Journaux d'Activité" separator progress-indicator>
        <!-- RECHERCHE -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Rechercher..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filtres"
                icon="o-funnel"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Tableau des journaux d'activité -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('id')">
                            <div class="flex items-center">
                                ID
                                @if ($sortBy['column'] === 'id')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('created_at')">
                            <div class="flex items-center">
                                Date
                                @if ($sortBy['column'] === 'created_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('user_id')">
                            <div class="flex items-center">
                                Utilisateur
                                @if ($sortBy['column'] === 'user_id')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('action')">
                            <div class="flex items-center">
                                Action
                                @if ($sortBy['column'] === 'action')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Description</th>
                        <th>Ressource</th>
                        <th>Adresse IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activityLogs as $log)
                        <tr class="hover">
                            <td>{{ $log->id }}</td>
                            <td>{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                            <td>
                                @if ($log->user)
                                    <div class="flex items-center gap-2">
                                        <div class="avatar">
                                            <div class="w-8 h-8 mask mask-squircle">
                                                <img src="{{ $log->user->profile_photo_url }}" alt="{{ $log->user->name }}">
                                            </div>
                                        </div>
                                        <div>
                                            <div class="font-medium">{{ $log->user->name }}</div>
                                            <div class="text-xs opacity-70">{{ $log->user->email }}</div>
                                        </div>
                                    </div>
                                @else
                                    <span class="opacity-70">Système</span>
                                @endif
                            </td>
                            <td>
                                <x-badge
                                    label="{{ match($log->action) {
                                        'create' => 'Création',
                                        'read' => 'Lecture',
                                        'update' => 'Modification',
                                        'delete' => 'Suppression',
                                        'access' => 'Accès',
                                        'login' => 'Connexion',
                                        'logout' => 'Déconnexion',
                                        default => ucfirst($log->action)
                                    } }}"
                                    color="{{ match($log->action) {
                                        'create' => 'success',
                                        'read' => 'info',
                                        'update' => 'warning',
                                        'delete' => 'error',
                                        'access' => 'secondary',
                                        'login' => 'success',
                                        'logout' => 'info',
                                        default => 'neutral'
                                    } }}"
                                />
                            </td>
                            <td>{{ $log->description }}</td>
                            <td>
                                @if ($log->loggable_type)
                                    <div class="text-sm">
                                        {{ class_basename($log->loggable_type) }}
                                        @if ($log->loggable_id)
                                            #{{ $log->loggable_id }}
                                        @endif
                                    </div>
                                @else
                                    <span class="text-sm opacity-70">-</span>
                                @endif
                            </td>
                            <td>
                                <span class="text-sm">{{ $log->ip_address }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-face-frown" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">Aucun journal d'activité trouvé</h3>
                                    <p class="text-gray-500">Modifiez vos filtres ou attendez que des activités soient enregistrées</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $activityLogs->links() }}
        </div>
    </x-card>

    <!-- Drawer de filtres -->
    <x-drawer wire:model="showFilters" title="Filtres avancés" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Recherche par description ou IP"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Rechercher..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filtrer par action"
                    placeholder="Toutes les actions"
                    :options="$actions->map(fn($action) => [
                        'label' => match($action) {
                            'create' => 'Création',
                            'read' => 'Lecture',
                            'update' => 'Modification',
                            'delete' => 'Suppression',
                            'access' => 'Accès',
                            'login' => 'Connexion',
                            'logout' => 'Déconnexion',
                            default => ucfirst($action)
                        },
                        'value' => $action
                    ])->toArray()"
                    wire:model.live="action"
                    option-label="label"
                    option-value="value"
                    empty-message="Aucune action trouvée"
                />
            </div>

            <div>
                <x-select
                    label="Filtrer par utilisateur"
                    placeholder="Tous les utilisateurs"
                    :options="$users->map(fn($user) => [
                        'label' => $user->name . ' (' . $user->email . ')',
                        'value' => $user->id
                    ])->toArray()"
                    wire:model.live="userId"
                    option-label="label"
                    option-value="value"
                    searchable
                    empty-message="Aucun utilisateur trouvé"
                />
            </div>

            <div>
                <x-input
                    type="date"
                    label="Filtrer par date"
                    wire:model.live="date"
                />
            </div>

            <div>
                <x-select
                    label="Éléments par page"
                    :options="[10, 25, 50, 100]"
                    wire:model.live="perPage"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Réinitialiser" icon="o-x-mark" wire:click="resetFilters" />
            <x-button label="Appliquer" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
