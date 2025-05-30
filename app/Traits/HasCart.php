<?php

namespace App\Traits;

use App\Actions\OrderRemoveItemAction;
use App\Actions\ToggleOrderProductAction;
use App\Actions\TrashCartAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Features\SupportEvents\HandlesEvents;
use Mary\Traits\Toast;

trait HasCart
{
    use Toast, HasUser, ForcesLogin, HandlesEvents;

    #[Computed]
    public function cart(): ?Order
    {
        $cart = $this->user()
            ->orders()
            ->isCart()
            ->with('items');

        // Allow guest users visit pages without creating a cart
        return $this->user()?->id ? $cart->firstOrCreate() : $cart->firstOrNew();
    }

    // Toggle item from cart
    public function toggleCartItem(Product $product): void
    {
        // This action requires user log in, so we set the intended action here
        $this->forcesLogin("toggleCartItem,$product->id");

        $toggleItem = new ToggleOrderProductAction($this->cart(), $product);
        $toggleItem->execute();

        $this->success('Cart updated', position: 'toast-bottom');
        $this->dispatch('cart-item-added');
    }

    // Delete a single item
    public function delete(OrderItem $item): void
    {
        $remove = new OrderRemoveItemAction($item);
        $remove->execute();

        $this->error('Removed from cart', position: 'toast-bottom toast-end');
        $this->dispatch('cart-item-removed');
    }

    // Trash entire cart
    public function trash(): void
    {
        $trash = new TrashCartAction($this->cart());
        $trash->execute();

        $this->error('Cart trashed', position: 'toast-bottom toast-end');
        $this->dispatch('cart-trashed');
    }

    #[On('cart-item-removed')]
    #[On('cart-trashed')]
    #[On('cart-item-added')]
    public function refresh(): void
    {
        // Nothing. Just refresh when this event happens somewhere.
        // Better way?
    }
}
