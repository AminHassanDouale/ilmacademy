<?php

namespace Database\Seeders;

use App\Models\OrderStatus;
use Illuminate\Database\Seeder;

class OrderStatusSeeder extends Seeder
{
    public function run()
    {
        if (OrderStatus::count()) {
            return;
        }

        OrderStatus::insert([
            [
                'id' => OrderStatus::CART,
                'name' => 'Shopping cart',
                'description' => 'The order was not placed yet, it is just a shopping cart',
                'color' => 'bg-neutral/20',
                'icon' => 'o-shopping-bag'
            ],
            [
                'id' => OrderStatus::ORDER_PLACED,
                'name' => 'Order placed',
                'description' => 'We received the order, waiting for payment confirmation',
                'color' => 'bg-purple-500/20',
                'icon' => 'o-map-pin'
            ],
            [
                'id' => OrderStatus::PAYMENT_CONFIRMED,
                'name' => 'Payment confirmed',
                'description' => 'The payment was confirmed, preparing to ship.',
                'color' => 'bg-info/20',
                'icon' => 'o-credit-card'
            ],
            [
                'id' => OrderStatus::SHIPPED,
                'name' => 'Shipped',
                'description' => 'The order was sent to courier.',
                'color' => 'bg-warning/20',
                'icon' => 'o-paper-airplane'
            ],
            [
                'id' => OrderStatus::DELIVERED,
                'name' => 'Order delivered',
                'description' => 'The order was delivered. Enjoy!',
                'color' => 'bg-success/20',
                'icon' => 'o-gift'
            ]
        ]);
    }
}
