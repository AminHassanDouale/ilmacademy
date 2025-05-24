<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Seeder;

class InvoiceItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $invoices = Invoice::all();

        if ($invoices->isEmpty()) {
            return;
        }

        foreach ($invoices as $invoice) {
            // Base tuition item
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'name' => 'Tuition Fee',
                'description' => "Tuition fee for {$invoice->curriculum->name}",
                'amount' => $invoice->amount * 0.8, // 80% of total is tuition
                'quantity' => 1,
            ]);

            // Materials fee
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'name' => 'Learning Materials',
                'description' => 'Books, online resources, and learning materials',
                'amount' => $invoice->amount * 0.1, // 10% of total is materials
                'quantity' => 1,
            ]);

            // Administrative fee
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'name' => 'Administrative Fee',
                'description' => 'Registration and administrative services',
                'amount' => $invoice->amount * 0.1, // 10% of total is admin fee
                'quantity' => 1,
            ]);

            // Add some optional items randomly
            if (fake()->boolean(30)) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'name' => 'Extra-curricular Activities',
                    'description' => 'Optional activities and clubs participation',
                    'amount' => rand(50, 200),
                    'quantity' => 1,
                ]);
            }
        }
    }
}
