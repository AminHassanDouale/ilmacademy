<?php

namespace App\Actions;

use App\Exceptions\AppException;
use App\Models\Order;
use App\Models\OrderStatus;
use DB;
use Exception;

class PlaceOrderAction
{
    public function __construct(private Order $order, private string $card_number)
    {
    }

    // You should place authorization here
    public function execute(): Order
    {
        try {
            DB::beginTransaction();

            if ($this->order->items()->count() == 0) {
                throw new AppException("Cart is empty.");
            }

            // Call payment gateway
            // Queue some mail
            // Run any other stuffs
            sleep(1);

            // Transform the cart into an order itself
            $this->order->update(['status_id' => OrderStatus::ORDER_PLACED]);

            // Log status for timeline
            $this->order->logs()->create(['status_id' => OrderStatus::ORDER_PLACED]);

            DB::commit();

            return $this->order;
        } catch (Exception $e) {
            DB::rollBack();
            throw  $e;
        }
    }
}
