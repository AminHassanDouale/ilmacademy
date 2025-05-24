<?php

namespace App\Traits;

use App\Models\Product;
use Livewire\Features\SupportRedirects\HandlesRedirects;
use Mary\Traits\Toast;

trait LikesProduct
{
    use Toast, ForcesLogin, HandlesRedirects;

    // You should place authorization here
    public function toggleLike(Product $product): void
    {
        // This action requires user log in, so we set the intended action here
        $this->forcesLogin("toggleLike,$product->id");

        $this->user()->likes()->toggle($product);

        $this->success('Wishlist updated', position: 'toast-bottom', css: 'bg-pink-500 text-base-100');
    }
}
