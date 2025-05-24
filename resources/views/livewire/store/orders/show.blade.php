<?php

use App\Actions\MoveOrderToNextStatusAction;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component {
    public Order $order;

    // You should place authorization here
    public function mount(): void
    {
        $this->order->load(['items.product', 'logs.status']);
    }

    // Get all available statuses, then merge order log statuses into them
    public function timeline(): Collection
    {
        $statuses = OrderStatus::where('id', '<>', OrderStatus::CART)->oldest('id')->get();

        return $statuses->merge($this->order->logs->map(function (OrderLog $item) {
            $status = $item->status;
            $status->date = $item->created_at;

            return $status;
        }));
    }

    // Pretend someone is working on your order and move it to next status
    public function next(): void
    {
        $next = new MoveOrderToNextStatusAction($this->order);
        $next->execute();
    }

    public function with(): array
    {
        return [
            'timeline' => $this->timeline()
        ];
    }
}; ?>

<div class="grid lg:grid-cols-8 gap-10">
    {{-- IMAGE--}}
    <div class="lg:col-span-2">
        <img src="/images/orders.png" width="300" class="mx-auto" />
    </div>

    {{-- ITEMS--}}
    <div class="lg:col-span-3">
        <x-card title="Order #{{ $order->id }}" separator>
            @foreach($order->items as $item)
                <x-list-item :item="$item" value="product.name" sub-value="price" avatar="product.cover" link="/products/{{ $item->product->id }}" no-separator>
                    <x-slot:actions>
                        <x-badge :value="$item->quantity" class="badge-neutral" />
                    </x-slot:actions>
                </x-list-item>
            @endforeach

            <hr class="my-5" />

            <div class="flex justify-between items-center mx-3">
                <div>Total</div>
                <div class="font-black text-lg">${{ $order->total }}</div>
            </div>
        </x-card>
    </div>

    {{-- TIMELINE --}}
    <div class="lg:col-span-3">
        <x-card title="Status" separator>
            <div class="px-8 py-5">
                @foreach($timeline as $item)
                    <x-timeline-item
                        :title="$item->name"
                        :subtitle="$item->date"
                        :description="$item->description"
                        :icon="$item->icon"
                        :first="$loop->first"
                        :last="$loop->last"
                        :pending="! $item->date"
                    />
                @endforeach
            </div>
        </x-card>

        @if($order->status_id != OrderStatus::DELIVERED)
            <div class="flex flex-wrap gap-5 mt-5">
                <x-icon name="o-light-bulb" label="Pretend someone is working on your order." />
                <x-button label="Click here to change status" wire:click="next" icon="o-cursor-arrow-rays" spinner />
            </div>
        @endif

    </div>
</div>

