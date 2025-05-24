<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Traits\HandlesRedirectBackAction;
use App\Traits\HasUser;
use App\Traits\LikesProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;
use Livewire\Attributes\Url;

new class extends Component {
    use HasUser, LikesProduct, HandlesRedirectBackAction;

    #[Url]
    public string $search = '';

    #[Url]
    public array $brands_id = [];

    #[Url]
    public array $categories_id = [];

    public function mount(): void
    {
        $this->executePreviousIntendedAction();
    }

    public function hasFilters(): bool
    {
        return count($this->brands_id) || count($this->categories_id) || $this->search;
    }

    public function clearFilters(): void
    {
        $this->reset();
    }

    public function products(): Collection
    {
        return Product::query()
            ->when($this->search, fn(Builder $q) => $q->where('name', 'like', "%$this->search%"))
            ->when($this->categories_id, fn(Builder $q) => $q->whereIn('category_id', $this->categories_id))
            ->when($this->brands_id, fn(Builder $q) => $q->whereIn('brand_id', $this->brands_id))
            ->when($this->user(), function (Builder $q) {
                $q->with(['likes' => fn($q) => $q->wherePivot('user_id', $this->user()->id)]);
                $q->with(['carts' => fn($q) => $q->whereRelation('order', 'user_id', $this->user()->id)->whereRelation('order', 'status_id', OrderStatus::CART)]);
            })
            ->take(50)
            ->get();
    }

    public function with(): array
    {
        return [
            'products' => $this->products(),
            'brands' => Brand::all(),
            'categories' => Category::all(),
            'hasFilters' => $this->hasFilters()
        ];
    }
}; ?>

<div>
    {{--   FILTERS --}}
    <div class="flex flex-wrap gap-5">

        {{-- SEARCH --}}
        <div class="w-full lg:w-auto">
            <x-input placeholder="Search ..." wire:model.live.debounce.500ms="search" icon="o-magnifying-glass" class="border-neutral text-sm" />
        </div>

        {{-- BRAND FILTER --}}
        <x-dropdown>
            <x-slot:trigger>
                <x-button label="Brand" icon-right="o-chevron-down" :badge="count($brands_id) ?  : null" class="btn-outline" />
            </x-slot:trigger>

            <x-menu-item title="Clear" icon="o-x-mark" @click="$wire.set('brands_id', [])" />

            <x-menu-separator />

            @foreach($brands as $brand)
                <x-menu-item @click.stop="">
                    <x-checkbox :label="$brand->name" :value="$brand->id" wire:model.live="brands_id" />
                </x-menu-item>
            @endforeach
        </x-dropdown>

        {{-- CATEGORY FILTER --}}
        <x-dropdown label="Category" class="btn-outline">
            <x-slot:trigger>
                <x-button label="Category" icon-right="o-chevron-down" :badge="count($categories_id) ? :  null" class="btn-outline" />
            </x-slot:trigger>

            <x-menu-item title="Clear" icon="o-x-mark" @click="$wire.set('categories_id', [])" />

            <x-menu-separator />

            @foreach($categories as $category)
                <x-menu-item @click.stop="">
                    <x-checkbox :label="$category->name" :value="$category->id" wire:model.live="categories_id" />
                </x-menu-item>
            @endforeach
        </x-dropdown>

        {{-- Clear filters --}}
        @if($hasFilters)
            <x-button label="Clear" icon="o-x-mark" wire:click="clearFilters" />
        @endif
    </div>

    <x-hr target="search,clearFilters,brands_id,categories_id" />

    {{-- PRODUCT LIST --}}
    <div class="grid md:grid-cols-2  lg:grid-cols-4 gap-8">
        @foreach($products as $product)
            <x-card shadow class="dark:border dark:border-gray-700">
                {{-- TITLE --}}
                <x-slot:title class="text-lg font-black">
                    ${{ $product->price }}
                </x-slot:title>

                {{-- FIGURE --}}
                <x-slot:figure class="bg-base-100 border border-base-200">
                    <a href="/products/{{ $product->id }}" wire:navigate>
                        <img src="{{ $product->cover }}" class="h-48 object-cover" />
                    </a>
                </x-slot:figure>

                {{-- MENU --}}
                <x-slot:menu>
                    <x-button
                        wire:click="toggleLike({{ $product->id }})"
                        icon="o-heart"
                        tooltip="Wishlist"
                        spinner
                        @class(["btn-square btn-sm", "text-pink-500" => $product->likes->count()])
                    />
                </x-slot:menu>

                <div class="line-clamp-1">{{ $product->name }}</div>
            </x-card>
        @endforeach
    </div>

    @if(! $products->count())
        <div class="flex gap-10 items-center mx-auto">
            <div>
                <img src="/images/no-results.png" width="300" />
            </div>
            <div class="text-lg font-medium">
                Sorry, no results for your search.
            </div>
        </div>
    @endif
</div>
