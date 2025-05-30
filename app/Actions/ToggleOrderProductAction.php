<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use DB;
use Exception;

class ToggleOrderProductAction
{
    public function __construct(private Order $order, private Product $product)
    {
    }

    // You should place authorization here
    public function execute(): void
    {
        try {
            // Remove item and update order total
            if ($item = $this->order->items()->where('product_id', $this->product->id)->first()) {
                $remove = new OrderRemoveItemAction($item);
                $remove->execute();

                return;
            }

            DB::beginTransaction();

            $item = new OrderItem();
            $item->product_id = $this->product->id;
            $item->price = $this->product->price;
            $item->total = $this->product->price;
            $item->quantity = 1;

            // Add item and update order total
            $this->order->items()->save($item);
            $this->order->update(['total' => $this->order->total + $item->total]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw  $e;
        }
    }
}
