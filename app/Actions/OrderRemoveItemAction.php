<?php

namespace App\Actions;

use App\Models\OrderItem;
use DB;
use Exception;

class OrderRemoveItemAction
{
    public function __construct(private OrderItem $item)
    {
    }

    // You should place authorization here
    public function execute(): void
    {
        try {
            DB::beginTransaction();

            $this->item->delete();
            $this->item->order->update(['total' => $this->item->order->total - $this->item->total]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw  $e;
        }
    }
}
