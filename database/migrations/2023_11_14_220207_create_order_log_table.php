<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('orders_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('status_id')->constrained('orders_statuses');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders_logs');
    }
};
