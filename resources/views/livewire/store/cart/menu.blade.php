<?php

use App\Traits\HasCart;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use HasCart;

    public function with(): array
    {
        return [
            'cart' => $this->cart()
        ];
    }
}; ?>

<div>
    <x-dropdown right>
        {{-- TRIGER --}}
        <x-slot:trigger>
            <x-button label="Cart" icon="o-shopping-cart" :badge="$cart?->items?->count()" badge-classes="font-mono" class="btn-ghost btn-sm" responsive />
        </x-slot:trigger>

        @if($cart?->items?->count())
            {{-- ITEMS --}}
            <div class="w-80 lg:w-96 mb-2">
                @foreach($cart->items as $item)
                    <x-list-item :item="$item" value="product.name" sub-value="price" avatar="product.cover" link="/products/{{ $item->product->id }}">
                        <x-slot:actions>
                            <x-button
                                icon="o-trash"
                                wire:click.stop="delete({{ $item->id }})"
                                spinner="delete({{ $item->id }})"
                                class="btn-sm btn-circle btn-ghost text-error" />
                        </x-slot:actions>
                    </x-list-item>
                @endforeach
            </div>

            {{--  TOTAL --}}
            <div class="flex justify-between items-center p-3">
                <div>{{ count($cart->items) }} item(s)</div>
                <div class="font-bold text-right text-lg">
                    ${{ $cart->total }}
                </div>
            </div>

            <x-menu-separator />

            {{-- ACTIONS --}}
            <div class="flex justify-between p-3">
                <x-button label="Trash cart" icon="o-trash" wire:click.stop="trash" spinner class="btn-ghost btn-sm text-error" />
                <x-button label="Go to cart" icon-right="o-arrow-right" link="/cart" class="btn-primary btn-sm" />
            </div>
        @else
            {{--  EMPTY INFO --}}
            <div class="p-2">
                <x-icon name="o-ellipsis-horizontal" label="Cart is empty" />
            </div>
        @endif
    </x-dropdown>
</div>
