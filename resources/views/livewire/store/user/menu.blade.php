<?php

use App\Traits\HasUser;
use Livewire\Volt\Component;

new class extends Component {
    use HasUser;

    public function with(): array
    {
        return [
            'user' => $this->user()
        ];
    }
}; ?>

<div>
    @if($user->id)
        <x-dropdown right>
            <x-slot:trigger>
                <x-button icon="o-user-circle" :label="$user->firstName" class="btn-sm btn-ghost" responsive />
            </x-slot:trigger>

            <x-menu-item title="Wishlist" icon="o-heart" class="text-pink-500" link="/wishlist" />
            <x-menu-item title="My Orders" icon="o-gift" link="/orders" />

            <x-menu-separator />

            <x-menu-item title="Logout" icon="o-power" link="/logout" no-wire-navigate />
        </x-dropdown>
    @else
        <x-button icon="o-user-circle" label="Login" class="btn-sm btn-ghost" link="/login" responsive />
    @endif
</div>
