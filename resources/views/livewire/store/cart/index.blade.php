<?php

use App\Traits\HasCart;
use App\Traits\HasUser;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast, HasUser, HasCart;

    public function with(): array
    {
        return [
            'cart' => $this->cart()
        ];
    }
}; ?>

<div class="grid lg:grid-cols-8 gap-10">
    {{-- IMAGE--}}
    <div class="lg:col-span-2">
        <img src="/images/cart.png" width="300" class="mx-auto" />
    </div>

    @if($cart?->items?->count())
        {{-- ITEMS--}}
        <div class="lg:col-span-3">
            <x-card title="Cart" separator>
                @foreach($cart->items as $item)
                    <x-list-item :item="$item" value="product.name" sub-value="price" avatar="product.cover" link="/products/{{ $item->product->id }}" no-separator>
                        <x-slot:actions>
                            <x-badge :value="$item->quantity" class="badge-neutral" />
                            <x-button icon="o-trash" wire:click="delete({{ $item->id }})" spinner="delete({{ $item->id }})" class="btn-sm btn-circle btn-ghost text-error" />
                        </x-slot:actions>
                    </x-list-item>
                @endforeach

                <hr class="my-5" />

                <div class="flex justify-between items-center mx-3">
                    <div>Total</div>
                    <div class="font-black text-lg">${{ $cart->total }}</div>
                </div>
            </x-card>
        </div>

        {{-- GO TO CHECKOUT--}}
        <div class="lg:col-span-3">
            <x-card title="I am done" separator>
                <x-button label="Checkout" icon="o-arrow-right" link="/checkout" class="btn-primary" />
            </x-card>
        </div>
    @else
        {{-- CART IS EMPTY --}}
        <div class="lg:col-span-6">
            <div class="font-bold text-3xl mb-8">Cart is empty</div>
            <x-button label="Browse products" icon-right="o-arrow-right" link="/" />
        </div>
    @endif

</div>

