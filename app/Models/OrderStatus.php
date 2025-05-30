<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    use HasFactory;

    public const CART = 1;

    public const ORDER_PLACED = 2;

    public const PAYMENT_CONFIRMED = 3;

    public const SHIPPED = 4;

    public const DELIVERED = 5;

    protected $table = 'orders_statuses';
}
