<?php

use App\Actions\PlaceOrderAction;
use App\Traits\HasCart;
use App\Traits\HasUser;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast, HasUser, HasCart;

    // You should place here a professional card number validation
    #[Validate('required')]
    public string $card_number = '';

    public function placeOrder(): void
    {
        $this->validate();

        $placeOrder = new PlaceOrderAction($this->cart(), $this->card_number);
        $order = $placeOrder->execute();

        $this->success('Order placed', position: 'toast-bottom', redirectTo: "/orders/$order->id");
    }

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
        <img src="/images/checkout.png" width="300" class="mx-auto" />
    </div>

    @if($cart?->items?->count())
        {{-- ITEMS--}}
        <div class="lg:col-span-3">
            <x-card title="Checkout" separator>
                @foreach($cart->items as $item)
                    <x-list-item :item="$item" value="product.name" sub-value="price" avatar="product.cover" link="/products/{{ $item->product->id }}" no-separator>
                        <x-slot:actions>
                            <x-badge :value="$item->quantity" class="badge-neutral" />
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

        {{-- PAYMENT--}}
        <div class="lg:col-span-3">
            <x-card title="Payment" separator>
                <x-form wire:submit="placeOrder">
                    <x-input placeholder="Card number / CVC" wire:model="card_number" prefix="Visa" x-mask="9999 9999 9999 9999 / 999" />

                    <x-slot:actions>
                        <x-button label="Place Order" icon="o-paper-airplane" class="btn-primary" type="submit" spinner="placeOrder" />
                    </x-slot:actions>
                </x-form>
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

