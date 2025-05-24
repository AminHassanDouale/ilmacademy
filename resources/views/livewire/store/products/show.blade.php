<?php

use App\Models\Product;
use App\Traits\HandlesRedirectBackAction;
use App\Traits\HasCart;
use App\Traits\HasUser;
use App\Traits\LikesProduct;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    use HasUser, HasCart, LikesProduct, HandlesRedirectBackAction;

    public Product $product;

    public function mount(): void
    {
        $this->executePreviousIntendedAction();
    }

    #[Computed]
    public function isInCart(): bool
    {
        return (bool) $this->cart()
            ->items()
            ->whereBelongsTo($this->product)
            ->first();
    }

    #[Computed]
    public function isLiked(): bool
    {
        return (bool) $this->user()
            ->likes()
            ->where('product_id', $this->product->id)
            ->first();
    }

    public function with(): array
    {
        return [
            'isInCart' => $this->isInCart(),
            'isLiked' => $this->isLiked()
        ];
    }
}; ?>

<div class="grid lg:grid-cols-2 gap-10">
    {{--  IMAGE  --}}
    <div>
        <img src="{{ $product->cover }}" width="500" class="rounded-lg mx-auto shadow-sm" />
    </div>
    <div>
        {{--  NAME  --}}
        <div class="font-bold text-2xl">
            {{ $product->name }}
        </div>
        {{--  PRICE  --}}
        <div class="mt-5 flex flex-wrap gap-3">
            <x-badge value="${{ $product->price }}" class="badge-neutral" />
        </div>
        {{--  BUTTONS --}}
        <div class="flex gap-3 mt-8">
            <x-button
                label="{{ $isInCart ? 'Remove from cart' : 'Add to cart' }}"
                wire:click="toggleCartItem({{ $product->id }})"
                icon="o-shopping-cart"
                spinner
                @class(["btn-primary", "btn-error btn-outline" => $isInCart])
            />

            <x-button
                wire:click="toggleLike({{ $product->id }})"
                icon="o-heart"
                tooltip="Wishlist"
                spinner
                @class(["btn-square", "text-pink-500" => $isLiked])
            />
        </div>
        {{--  DESCRIPTION  --}}
        <div class="my-8">
            {{ $product->description }}
        </div>
    </div>
</div>
