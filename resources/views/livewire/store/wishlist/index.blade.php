<?php

use App\Models\Product;
use App\Models\User;
use App\Traits\HasUser;
use App\Traits\LikesProduct;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast, HasUser, LikesProduct;

    public function products(): Collection
    {
        return $this->user()->likes()->get();
    }

    public function with(): array
    {
        return [
            'products' => $this->products()
        ];
    }
}; ?>

<div class="grid lg:grid-cols-8 gap-10">
    <div class="lg:col-span-2">
        <img src="/images/wishes.png" width="300" class="mx-auto" />
    </div>

    @if($products?->count())
        <div class="lg:col-span-3">
            <x-card title="Wishlist" separator>
                @foreach($products as $product)
                    <x-list-item :item="$product" value="name" sub-value="price" avatar="cover" link="/products/{{ $product->id }}" no-separator>
                        <x-slot:actions>
                            <x-button
                                wire:click="toggleLike({{ $product->id }})"
                                icon="o-trash"
                                tooltip="Remove from Wishlist"
                                spinner
                                class="btn-square btn-sm text-error"
                            />
                        </x-slot:actions>
                    </x-list-item>
                @endforeach
            </x-card>
        </div>
    @else
        <div class="lg:col-span-6">
            <div class="font-bold text-3xl mb-8">No wishes</div>
            <x-button label="Browse products" icon-right="o-arrow-right" link="/" />
        </div>
    @endif
</div>


