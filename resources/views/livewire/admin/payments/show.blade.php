<?php
// resources/views/livewire/admin/payments/create.blade.php

use App\Models\Payment;
use App\Models\Student;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Payment')] class extends Component {
    use Toast;

    #[Validate('required|exists:students,id')]
    public string $student_id = '';

    #[Validate('required|in:tuition,registration,exam_fee,library_fee,activity_fee,other')]
    public string $type = '';

    #[Validate('required|numeric|min:0.01')]
    public string $amount = '';

    #[Validate('required|in:pending,completed,failed,refunded,cancelled')]
    public string $status = 'pending';

    #[Validate('nullable|string|max:255')]
    public string $description = '';

    #[Validate('nullable|string|max:255')]
    public string $payment_method = '';

    #[Validate('nullable|string|max:255')]
    public string $transaction_id = '';

    #[Validate('nullable|date')]
    public string $due_date = '';

    #[Validate('nullable|string')]
    public string $notes = '';

    public function mount(): void
    {
        $this->due_date = now()->addDays(30)->format('Y-m-d');
    }

    public function getStudentsProperty(): Collection
    {
        return Student::with(['user', 'curriculum'])
            ->get()
            ->map(fn($student) => [
                'id' => $student->id,
                'name' => $student->user->name ?? 'Unknown',
                'student_id' => $student->student_id,
                'email' => $student->user->email ?? '',
                'curriculum' => $student->curriculum->name ?? 'N/A'
            ])
            ->sortBy('name');
    }

    public function getPaymentTypesProperty(): array
    {
        return [
            'tuition' => 'Tuition Fee',
            'registration' => 'Registration Fee',
            'exam_fee' => 'Exam Fee',
            'library_fee' => 'Library Fee',
            'activity_fee' => 'Activity Fee',
            'other' => 'Other'
        ];
    }

    public function getStatusOptionsProperty(): array
    {
        return [
            'pending' => 'Pending',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            'cancelled' => 'Cancelled'
        ];
    }

    public function getPaymentMethodsProperty(): array
    {
        return [
            'cash' => 'Cash',
            'credit_card' => 'Credit Card',
            'debit_card' => 'Debit Card',
            'bank_transfer' => 'Bank Transfer',
            'check' => 'Check',
            'online_payment' => 'Online Payment',
            'mobile_payment' => 'Mobile Payment',
            'other' => 'Other'
        ];
    }

    public function generateReferenceNumber(): string
    {
        return 'PAY-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }

    public function save(): void
    {
        $this->validate();

        try {
            $referenceNumber = $this->generateReferenceNumber();

            $payment = Payment::create([
                'student_id' => $this->student_id,
                'reference_number' => $referenceNumber,
                'type' => $this->type,
                'amount' => $this->amount,
                'status' => $this->status,
                'description' => $this->description ?: null,
                'payment_method' => $this->payment_method ?: null,
                'transaction_id' => $this->transaction_id ?: null,
                'due_date' => $this->due_date ?: null,
                'notes' => $this->notes ?: null,
                'created_by' => auth()->id(),
            ]);

            // Log activity
            activity()
                ->performedOn($payment)
                ->causedBy(auth()->user())
                ->withProperties([
                    'payment_data' => $payment->fresh()->toArray(),
                    'student_name' => $payment->student->user->name ?? 'Unknown'
                ])
                ->log('Payment created');

            $this->success('Payment created successfully!');

            return redirect()->route('admin.payments.show', $payment);

        } catch (\Exception $e) {
            $this->error('Failed to create payment. Please try again.');
        }
    }

    public function cancel(): void
    {
        return redirect()->route('admin.payments.index');
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create Payment" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-x-mark"
                wire:click="cancel"
                class="btn-ghost"
            />
            <x-button
                label="Create Payment"
                icon="o-check"
                wire:click="save"
                spinner="save"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>

    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-6">
                <form wire:submit="save">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Student Selection -->
                        <div class="md:col-span-2">
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Student <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="student_id" class="w-full select select-bordered" required>
                                <option value="">Select a student...</option>
                                @foreach($this->students as $student)
                                    <option value="{{ $student['id'] }}">
                                        {{ $student['name'] }} ({{ $student['student_id'] }}) - {{ $student['curriculum'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('student_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Payment Type -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Payment Type <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="type" class="w-full select select-bordered" required>
                                <option value="">Select payment type...</option>
                                @foreach($this->paymentTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('type')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Amount -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Amount <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute text-gray-500 left-3 top-3">$</span>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    wire:model="amount"
                                    class="w-full pl-8 input input-bordered"
                                    placeholder="0.00"
                                    required
                                />
                            </div>
                            @error('amount')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Status <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="status" class="w-full select select-bordered" required>
                                @foreach($this->statusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Due Date -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Due Date
                            </label>
                            <input
                                type="date"
                                wire:model="due_date"
                                class="w-full input input-bordered"
                            />
                            @error('due_date')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Payment Method
                            </label>
                            <select wire:model="payment_method" class="w-full select select-bordered">
                                <option value="">Select payment method...</option>
                                @foreach($this->paymentMethods as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('payment_method')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Transaction ID -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Transaction ID
                            </label>
                            <input
                                type="text"
                                wire:model="transaction_id"
                                class="w-full input input-bordered"
                                placeholder="Enter transaction ID..."
                            />
                            @error('transaction_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Description -->
                        <div class="md:col-span-2">
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Description
                            </label>
                            <input
                                type="text"
                                wire:model="description"
                                class="w-full input input-bordered"
                                placeholder="Payment description..."
                            />
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Notes -->
                        <div class="md:col-span-2">
                            <label class="block mb-2 text-sm font-medium text-gray-700">
                                Notes
                            </label>
                            <textarea
                                wire:model="notes"
                                rows="3"
                                class="w-full textarea textarea-bordered"
                                placeholder="Additional notes or comments..."
                            ></textarea>
                            @error('notes')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Preview Section -->
                    @if($student_id && $type && $amount)
                        <div class="p-4 mt-8 rounded-lg bg-gray-50">
                            <h3 class="mb-4 text-lg font-medium text-gray-900">Payment Preview</h3>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="font-medium text-gray-700">Student:</span>
                                    @php
                                        $selectedStudent = $this->students->firstWhere('id', $student_id);
                                    @endphp
                                    {{ $selectedStudent['name'] ?? 'Unknown' }}
                                </div>
                                <div>
                                    <span class="font-medium text-gray-700">Type:</span>
                                    {{ $this->paymentTypes[$type] ?? $type }}
                                </div>
                                <div>
                                    <span class="font-medium text-gray-700">Amount:</span>
                                    ${{ number_format((float)$amount, 2) }}
                                </div>
                                <div>
                                    <span class="font-medium text-gray-700">Status:</span>
                                    {{ $this->statusOptions[$status] ?? $status }}
                                </div>
                                @if($due_date)
                                    <div>
                                        <span class="font-medium text-gray-700">Due Date:</span>
                                        {{ \Carbon\Carbon::parse($due_date)->format('M d, Y') }}
                                    </div>
                                @endif
                                @if($payment_method)
                                    <div>
                                        <span class="font-medium text-gray-700">Payment Method:</span>
                                        {{ $this->paymentMethods[$payment_method] ?? $payment_method }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </form>
            </div>
        </div>
    </div>
</div>
