<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderLogFactory extends Factory
{
    protected $model = OrderLog::class;

    public function definition()
    {
        return [
            'order_id' => Order::factory(),
            'status_id' => OrderStatus::ORDER_PLACED,
        ];
    }
}
