<?php

namespace App\Actions;

use App\Models\Order;
use DB;
use Exception;

class MoveOrderToNextStatusAction
{
    public function __construct(private Order $order)
    {
    }

    public function execute(): Order
    {
        try {
            DB::beginTransaction();

            $this->order->increment('status_id');
            $this->order->logs()->create(['status_id' => $this->order->status_id]);

            DB::commit();

            return $this->order;
        } catch (Exception $e) {
            DB::rollBack();
            throw  $e;
        }
    }
}
