<?php

use App\Models\Order;
use App\Traits\HasUser;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    use HasUser;

    public function orders(): Collection
    {
        return Order::query()
            ->whereBelongsTo($this->user())
            ->isNotCart()
            ->with(['items.product', 'status'])
            ->get();
    }

    public function with(): array
    {
        return [
            'orders' => $this->orders()
        ];
    }
}; ?>

<div class="grid lg:grid-cols-8 gap-10">
    {{-- IMAGE--}}
    <div class="lg:col-span-2">
        <img src="/images/orders.png" width="300" class="mx-auto" />
    </div>

    @if($orders->count())
        <div class="lg:col-span-4">
            <x-header title="Orders" separator />

            {{-- ORDERS--}}
            @foreach($orders as $order)
                <x-card class="mb-5 !p-2">
                    <x-list-item :item="$order" link="/orders/{{ $order->id }}" no-separator>
                        {{-- NUMBER --}}
                        <x-slot:avatar>
                            <div class="rounded bg-base-300 p-3">
                                #{{ $order->id }}
                            </div>
                        </x-slot:avatar>
                        {{-- ITEM--}}
                        <x-slot:value>
                            {{ $order->items->first()->product->name }}

                            @if($order->items->count() > 1)
                                <span class="text-xs">+ {{  ($order->items->count() - 1)  }} item(s)</span>
                            @endif
                        </x-slot:value>
                        {{-- DETAILS--}}
                        <x-slot:subValue class="py-2 flex gap-5">
                            <div>{{ $order->created_at->format('Y-m-d') }}</div>
                            <div class="font-bold">${{ $order->total }}</div>
                        </x-slot:subValue>
                        {{-- STATUS--}}
                        <x-slot:actions>
                            <x-badge :value="$order->status->name" @class([$order->status->color, "hidden lg:inline-flex"]) />
                            <x-icon :name="$order->status->icon" @class(["block lg:hidden"]) />
                        </x-slot:actions>
                    </x-list-item>
                </x-card>
            @endforeach
        </div>
    @else
        <div class="lg:col-span-6">
            <div class="font-bold text-3xl mb-8">No orders</div>
            <x-button label="Browse products" icon-right="o-arrow-right" link="/" />
        </div>
    @endif
</div>
