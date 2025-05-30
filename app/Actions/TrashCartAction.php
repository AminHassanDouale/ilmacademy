<?php

namespace App\Actions;

use App\Models\Order;
use DB;
use Exception;

class TrashCartAction
{
    public function __construct(private Order $order)
    {
    }

    // You should place authorization here
    public function execute(): void
    {
        try {
            DB::beginTransaction();

            $this->order->items()->delete();
            $this->order->update(['total' => 0]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw  $e;
        }
    }
}
