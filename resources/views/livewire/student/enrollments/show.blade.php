<?php

use App\Models\User;
use App\Models\ProgramEnrollment;
use App\Models\SubjectEnrollment;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Enrollment Details')] class extends Component {
    use Toast;

    // Current user and enrollment
    public User $user;
    public ProgramEnrollment $programEnrollment;

    // Related data
    public array $subjectEnrollments = [];
    public array $invoices = [];
    public array $payments = [];
    public array $stats = [];

    // Tab management
    public string $activeTab = 'overview';

    public function mount(ProgramEnrollment $programEnrollment): void
    {
        $this->user = Auth::user();
        $this->programEnrollment = $programEnrollment->load([
            'childProfile',
            'curriculum',
            'academicYear',
            'paymentPlan',
            'subjectEnrollments.subject'
        ]);

        // Check if user has access to this enrollment
        $this->checkAccess();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'view',
            "Viewed enrollment details for: {$this->programEnrollment->curriculum->name ?? 'Unknown Program'}",
            ProgramEnrollment::class,
            $this->programEnrollment->id,
            [
                'enrollment_id' => $this->programEnrollment->id,
                'program_name' => $this->programEnrollment->curriculum->name ?? 'Unknown',
                'student_name' => $this->programEnrollment->childProfile->full_name ?? 'Unknown',
                'ip' => request()->ip()
            ]
        );

        $this->loadRelatedData();
        $this->loadStats();
    }

    protected function checkAccess(): void
    {
        $hasAccess = false;

        if ($this->user->hasRole('student')) {
            // Student can only view their own enrollments
            $hasAccess = $this->programEnrollment->childProfile &&
                        $this->programEnrollment->childProfile->user_id === $this->user->id;
        } elseif ($this->user->hasRole('parent')) {
            // Parent can view their children's enrollments
            $hasAccess = $this->programEnrollment->childProfile &&
                        $this->programEnrollment->childProfile->parent_id === $this->user->id;
        }

        if (!$hasAccess) {
            abort(403, 'You do not have permission to view this enrollment.');
        }
    }

    protected function loadRelatedData(): void
    {
        try {
            // Load subject enrollments
            $this->subjectEnrollments = $this->programEnrollment->subjectEnrollments()
                ->with(['subject'])
                ->get()
                ->toArray();

            // Load invoices for this enrollment
            $this->invoices = $this->getEnrollmentInvoices();

            // Load payments for this enrollment
            $this->payments = $this->getEnrollmentPayments();

        } catch (\Exception $e) {
            $this->subjectEnrollments = [];
            $this->invoices = [];
            $this->payments = [];
        }
    }

    protected function getEnrollmentInvoices(): array
    {
        try {
            if (!class_exists(Invoice::class)) {
                return [];
            }

            // Try direct relationship first
            if (method_exists($this->programEnrollment, 'invoices')) {
                return $this->programEnrollment->invoices()
                    ->orderBy('invoice_date', 'desc')
                    ->get()
                    ->toArray();
            }

            // Fallback: Get invoices by child profile and academic year
            return Invoice::where('child_profile_id', $this->programEnrollment->child_profile_id)
                ->where('academic_year_id', $this->programEnrollment->academic_year_id)
                ->orderBy('invoice_date', 'desc')
                ->get()
                ->toArray();

        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getEnrollmentPayments(): array
    {
        try {
            if (!class_exists(Payment::class)) {
                return [];
            }

            // Get payments through invoices or directly by child profile
            return Payment::where('child_profile_id', $this->programEnrollment->child_profile_id)
                ->whereHas('invoice', function($query) {
                    $query->where('academic_year_id', $this->programEnrollment->academic_year_id);
                })
                ->orderBy('payment_date', 'desc')
                ->get()
                ->toArray();

        } catch (\Exception $e) {
            return [];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalSubjects = count($this->subjectEnrollments);
            $totalInvoices = count($this->invoices);
            $totalPayments = count($this->payments);

            // Calculate financial totals
            $totalInvoiceAmount = array_sum(array_column($this->invoices, 'amount'));
            $totalPaidAmount = array_sum(array_column($this->payments, 'amount'));
            $outstandingAmount = $totalInvoiceAmount - $totalPaidAmount;

            // Calculate enrollment progress (example calculation)
            $progressPercentage = $totalSubjects > 0 ? min(100, ($totalSubjects * 15)) : 0;

            // Count invoice statuses
            $paidInvoices = count(array_filter($this->invoices, fn($inv) => $inv['status'] === 'paid'));
            $pendingInvoices = count(array_filter($this->invoices, fn($inv) => in_array($inv['status'], ['pending', 'sent'])));

            $this->stats = [
                'total_subjects' => $totalSubjects,
                'total_invoices' => $totalInvoices,
                'total_payments' => $totalPayments,
                'total_invoice_amount' => $totalInvoiceAmount,
                'total_paid_amount' => $totalPaidAmount,
                'outstanding_amount' => $outstandingAmount,
                'progress_percentage' => $progressPercentage,
                'paid_invoices' => $paidInvoices,
                'pending_invoices' => $pendingInvoices,
                'enrollment_status' => $this->programEnrollment->status,
            ];

        } catch (\Exception $e) {
            $this->stats = [
                'total_subjects' => 0,
                'total_invoices' => 0,
                'total_payments' => 0,
                'total_invoice_amount' => 0,
                'total_paid_amount' => 0,
                'outstanding_amount' => 0,
                'progress_percentage' => 0,
                'paid_invoices' => 0,
                'pending_invoices' => 0,
                'enrollment_status' => $this->programEnrollment->status ?? 'Unknown',
            ];
        }
    }

    // Set active tab
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // Navigate to related pages
    public function redirectToSessions(): void
    {
        $this->redirect(route('student.sessions.index', ['enrollment' => $this->programEnrollment->id]));
    }

    public function redirectToExams(): void
    {
        $this->redirect(route('student.exams.index', ['enrollment' => $this->programEnrollment->id]));
    }

    public function redirectToInvoices(): void
    {
        $this->redirect(route('student.invoices.index', ['enrollment' => $this->programEnrollment->id]));
    }

    public function redirectToInvoice(int $invoiceId): void
    {
        $this->redirect(route('student.invoices.show', $invoiceId));
    }

    public function redirectToPayInvoice(int $invoiceId): void
    {
        $this->redirect(route('student.invoices.pay', $invoiceId));
    }

    // Helper functions
    public function getStatusColor(string $status): string
    {
        return match($status) {
            'Active' => 'bg-green-100 text-green-800',
            'Inactive' => 'bg-gray-100 text-gray-600',
            'Completed' => 'bg-blue-100 text-blue-800',
            'Suspended' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function getInvoiceStatusColor(string $status): string
    {
        return match($status) {
            'paid' => 'bg-green-100 text-green-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'overdue' => 'bg-red-100 text-red-800',
            'cancelled' => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function formatCurrency(float $amount): string
    {
        return ' . number_format($amount, 2);
    }

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>
<div>
    <!-- Page header -->
    <x-header title="Enrollment Details" separator>
        <x-slot:subtitle>
            {{ $programEnrollment->curriculum->name ?? 'Unknown Program' }} - {{ $programEnrollment->academicYear->name ?? 'Unknown Year' }}
        </x-slot:subtitle>

        <x-slot:actions>
            <x-button
                label="Back to Enrollments"
                icon="o-arrow-left"
                link="{{ route('student.enrollments.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <!-- Status and Progress Overview -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-4">
        <x-card>
            <div class="p-6 text-center">
                <div class="mb-3">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($stats['enrollment_status']) }}">
                        {{ $stats['enrollment_status'] }}
                    </span>
                </div>
                <div class="text-2xl font-bold text-gray-900">{{ $stats['progress_percentage'] }}%</div>
                <div class="text-sm text-gray-500">Progress</div>
                <div class="w-full h-2 mt-2 bg-gray-200 rounded-full">
                    <div class="h-2 transition-all duration-300 bg-blue-600 rounded-full" style="width: {{ $stats['progress_percentage'] }}%"></div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-blue-100 rounded-full w-fit">
                    <x-icon name="o-book-open" class="w-6 h-6 text-blue-600" />
                </div>
                <div class="text-2xl font-bold text-blue-600">{{ $stats['total_subjects'] }}</div>
                <div class="text-sm text-gray-500">Subjects</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-green-100 rounded-full w-fit">
                    <x-icon name="o-currency-dollar" class="w-6 h-6 text-green-600" />
                </div>
                <div class="text-2xl font-bold text-green-600">{{ $this->formatCurrency($stats['total_paid_amount']) }}</div>
                <div class="text-sm text-gray-500">Paid</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-orange-100 rounded-full w-fit">
                    <x-icon name="o-exclamation-triangle" class="w-6 h-6 text-orange-600" />
                </div>
                <div class="text-2xl font-bold text-orange-600">{{ $this->formatCurrency($stats['outstanding_amount']) }}</div>
                <div class="text-sm text-gray-500">Outstanding</div>
            </div>
        </x-card>
    </div>

    <!-- Tab Navigation -->
    <div class="mb-6">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px space-x-8" aria-label="Tabs">
                <button
                    wire:click="setActiveTab('overview')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'overview' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-home" class="inline w-4 h-4 mr-1" />
                    Overview
                </button>
                <button
                    wire:click="setActiveTab('subjects')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'subjects' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-book-open" class="inline w-4 h-4 mr-1" />
                    Subjects ({{ $stats['total_subjects'] }})
                </button>
                <button
                    wire:click="setActiveTab('financial')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'financial' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-currency-dollar" class="inline w-4 h-4 mr-1" />
                    Financial ({{ $stats['total_invoices'] }})
                </button>
                <button
                    wire:click="setActiveTab('activity')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'activity' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-clock" class="inline w-4 h-4 mr-1" />
                    Activity
                </button>
            </nav>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Overview Tab -->
        @if($activeTab === 'overview')
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Left Column - Main Details -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Program Information -->
                    <x-card title="Program Information">
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Program Name</label>
                                <p class="text-sm text-gray-900">{{ $programEnrollment->curriculum->name ?? 'N/A' }}</p>
                            </div>
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Program Code</label>
                                <p class="font-mono text-sm text-gray-900">{{ $programEnrollment->curriculum->code ?? 'N/A' }}</p>
                            </div>
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Academic Year</label>
                                <p class="text-sm text-gray-900">{{ $programEnrollment->academicYear->name ?? 'N/A' }}</p>
                            </div>
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Enrollment Status</label>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($programEnrollment->status) }}">
                                    {{ $programEnrollment->status }}
                                </span>
                            </div>
                            @if($programEnrollment->curriculum && $programEnrollment->curriculum->description)
                                <div class="md:col-span-2">
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Description</label>
                                    <p class="text-sm text-gray-900">{{ $programEnrollment->curriculum->description }}</p>
                                </div>
                            @endif
                        </div>
                    </x-card>

                    <!-- Student Information -->
                    @if($programEnrollment->childProfile)
                        <x-card title="Student Information">
                            <div class="flex items-center mb-4 space-x-4">
                                <div class="avatar">
                                    <div class="w-16 h-16 rounded-full">
                                        <img src="https://ui-avatars.com/api/?name={{ urlencode($programEnrollment->childProfile->full_name) }}&color=7F9CF5&background=EBF4FF" alt="{{ $programEnrollment->childProfile->full_name }}" />
                                    </div>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold">{{ $programEnrollment->childProfile->full_name }}</h3>
                                    @if($programEnrollment->childProfile->age)
                                        <p class="text-sm text-gray-500">Age: {{ $programEnrollment->childProfile->age }}</p>
                                    @endif
                                    @if($programEnrollment->childProfile->email)
                                        <p class="text-sm text-gray-500">{{ $programEnrollment->childProfile->email }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                @if($programEnrollment->childProfile->phone)
                                    <div>
                                        <label class="block mb-1 text-sm font-medium text-gray-700">Phone</label>
                                        <p class="text-sm text-gray-900">{{ $programEnrollment->childProfile->phone }}</p>
                                    </div>
                                @endif
                                @if($programEnrollment->childProfile->date_of_birth)
                                    <div>
                                        <label class="block mb-1 text-sm font-medium text-gray-700">Date of Birth</label>
                                        <p class="text-sm text-gray-900">{{ $programEnrollment->childProfile->date_of_birth->format('M d, Y') }}</p>
                                    </div>
                                @endif
                            </div>
                        </x-card>
                    @endif

                    <!-- Payment Plan -->
                    @if($programEnrollment->paymentPlan)
                        <x-card title="Payment Plan">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Plan Name</label>
                                    <p class="text-sm text-gray-900">{{ $programEnrollment->paymentPlan->name }}</p>
                                </div>
                                @if($programEnrollment->paymentPlan->amount)
                                    <div>
                                        <label class="block mb-1 text-sm font-medium text-gray-700">Amount</label>
                                        <p class="text-sm text-gray-900">{{ $this->formatCurrency($programEnrollment->paymentPlan->amount) }}</p>
                                    </div>
                                @endif
                            </div>
                        </x-card>
                    @endif
                </div>

                <!-- Right Column - Quick Actions and Summary -->
                <div class="space-y-6">
                    <!-- Quick Actions -->
                    <x-card title="Quick Actions">
                        <div class="space-y-3">
                            <x-button
                                label="View Sessions"
                                icon="o-calendar"
                                wire:click="redirectToSessions"
                                class="w-full btn-outline"
                            />
                            <x-button
                                label="View Exams"
                                icon="o-document-text"
                                wire:click="redirectToExams"
                                class="w-full btn-outline"
                            />
                            <x-button
                                label="View Invoices"
                                icon="o-currency-dollar"
                                wire:click="redirectToInvoices"
                                class="w-full btn-outline"
                            />
                            @if($stats['outstanding_amount'] > 0)
                                <x-button
                                    label="Make Payment"
                                    icon="o-credit-card"
                                    wire:click="redirectToInvoices"
                                    class="w-full btn-primary"
                                />
                            @endif
                        </div>
                    </x-card>

                    <!-- Financial Summary -->
                    <x-card title="Financial Summary">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Total Invoiced</span>
                                <span class="font-medium">{{ $this->formatCurrency($stats['total_invoice_amount']) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Total Paid</span>
                                <span class="font-medium text-green-600">{{ $this->formatCurrency($stats['total_paid_amount']) }}</span>
                            </div>
                            <div class="flex items-center justify-between pt-2 border-t">
                                <span class="text-sm font-medium text-gray-900">Outstanding</span>
                                <span class="font-bold {{ $stats['outstanding_amount'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                                    {{ $this->formatCurrency($stats['outstanding_amount']) }}
                                </span>
                            </div>
                        </div>
                    </x-card>

                    <!-- Enrollment Timeline -->
                    <x-card title="Important Dates">
                        <div class="space-y-3">
                            <div class="flex items-center text-sm">
                                <x-icon name="o-calendar-days" class="w-4 h-4 mr-2 text-blue-500" />
                                <div>
                                    <div class="font-medium">Enrolled</div>
                                    <div class="text-gray-500">{{ $programEnrollment->created_at->format('M d, Y') }}</div>
                                </div>
                            </div>
                            @if($programEnrollment->academicYear)
                                <div class="flex items-center text-sm">
                                    <x-icon name="o-play" class="w-4 h-4 mr-2 text-green-500" />
                                    <div>
                                        <div class="font-medium">Academic Year Start</div>
                                        <div class="text-gray-500">{{ $programEnrollment->academicYear->start_date ? $programEnrollment->academicYear->start_date->format('M d, Y') : 'Not set' }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center text-sm">
                                    <x-icon name="o-stop" class="w-4 h-4 mr-2 text-red-500" />
                                    <div>
                                        <div class="font-medium">Academic Year End</div>
                                        <div class="text-gray-500">{{ $programEnrollment->academicYear->end_date ? $programEnrollment->academicYear->end_date->format('M d, Y') : 'Not set' }}</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-card>
                </div>
            </div>
        @endif

        <!-- Subjects Tab -->
        @if($activeTab === 'subjects')
            <x-card title="Enrolled Subjects">
                @if(count($subjectEnrollments) > 0)
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        @foreach($subjectEnrollments as $subjectEnrollment)
                            <div class="p-4 transition-shadow border border-gray-200 rounded-lg hover:shadow-md">
                                <div class="flex items-start justify-between mb-3">
                                    <div>
                                        <h4 class="font-semibold text-gray-900">{{ $subjectEnrollment['subject']['name'] ?? 'Unknown Subject' }}</h4>
                                        @if($subjectEnrollment['subject']['code'])
                                            <p class="font-mono text-sm text-gray-500">{{ $subjectEnrollment['subject']['code'] }}</p>
                                        @endif
                                    </div>
                                    <x-icon name="o-book-open" class="w-5 h-5 text-blue-500" />
                                </div>

                                @if($subjectEnrollment['subject']['description'])
                                    <p class="mb-3 text-sm text-gray-600">{{ Str::limit($subjectEnrollment['subject']['description'], 100) }}</p>
                                @endif

                                <div class="flex items-center justify-between text-xs text-gray-500">
                                    <span>Enrolled: {{ \Carbon\Carbon::parse($subjectEnrollment['created_at'])->format('M d, Y') }}</span>
                                    @if($subjectEnrollment['subject']['credits'])
                                        <span>{{ $subjectEnrollment['subject']['credits'] }} Credits</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-book-open" class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                        <h3 class="mb-2 text-lg font-medium text-gray-900">No Subjects Enrolled</h3>
                        <p class="text-gray-500">No subjects have been assigned to this enrollment yet.</p>
                    </div>
                @endif
            </x-card>
        @endif

        <!-- Financial Tab -->
        @if($activeTab === 'financial')
            <div class="space-y-6">
                <!-- Financial Overview -->
                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <x-card>
                        <div class="p-6 text-center">
                            <div class="text-2xl font-bold text-blue-600">{{ $stats['total_invoices'] }}</div>
                            <div class="text-sm text-gray-500">Total Invoices</div>
                        </div>
                    </x-card>
                    <x-card>
                        <div class="p-6 text-center">
                            <div class="text-2xl font-bold text-green-600">{{ $stats['paid_invoices'] }}</div>
                            <div class="text-sm text-gray-500">Paid Invoices</div>
                        </div>
                    </x-card>
                    <x-card>
                        <div class="p-6 text-center">
                            <div class="text-2xl font-bold text-orange-600">{{ $stats['pending_invoices'] }}</div>
                            <div class="text-sm text-gray-500">Pending Invoices</div>
                        </div>
                    </x-card>
                </div>

                <!-- Invoices List -->
                <x-card title="Recent Invoices">
                    @if(count($invoices) > 0)
                        <div class="overflow-x-auto">
                            <table class="table w-full">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach(array_slice($invoices, 0, 5) as $invoice)
                                        <tr class="hover">
                                            <td>
                                                <button
                                                    wire:click="redirectToInvoice({{ $invoice['id'] }})"
                                                    class="font-mono text-sm text-blue-600 underline hover:text-blue-800"
                                                >
                                                    {{ $invoice['invoice_number'] }}
                                                </button>
                                            </td>
                                            <td>{{ \Carbon\Carbon::parse($invoice['invoice_date'])->format('M d, Y') }}</td>
                                            <td>{{ \Carbon\Carbon::parse($invoice['due_date'])->format('M d, Y') }}</td>
                                            <td class="font-medium">{{ $this->formatCurrency($invoice['amount']) }}</td>
                                            <td>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getInvoiceStatusColor($invoice['status']) }}">
                                                    {{ ucfirst($invoice['status']) }}
                                                </span>
                                            </td>
                                            <td>
                                                <div class="flex gap-2">
                                                    <button
                                                        wire:click="redirectToInvoice({{ $invoice['id'] }})"
                                                        class="p-1 text-gray-600 bg-gray-100 rounded hover:text-gray-900 hover:bg-gray-200"
                                                        title="View"
                                                    >
                                                        👁️
                                                    </button>
                                                    @if(in_array($invoice['status'], ['pending', 'sent', 'overdue']))
                                                        <button
                                                            wire:click="redirectToPayInvoice({{ $invoice['id'] }})"
                                                            class="p-1 text-green-600 bg-green-100 rounded hover:text-green-900 hover:bg-green-200"
                                                            title="Pay"
                                                        >
                                                            💳
                                                        </button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if(count($invoices) > 5)
                            <div class="mt-4 text-center">
                                <x-button
                                    label="View All Invoices ({{ count($invoices) }})"
                                    icon="o-arrow-right"
                                    wire:click="redirectToInvoices"
                                    class="btn-outline"
                                />
                            </div>
                        @endif
                    @else
                        <div class="py-8 text-center">
                            <x-icon name="o-currency-dollar" class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                            <h3 class="mb-2 text-lg font-medium text-gray-900">No Invoices</h3>
                            <p class="text-gray-500">No invoices have been generated for this enrollment yet.</p>
                        </div>
                    @endif
                </x-card>

                <!-- Recent Payments -->
                @if(count($payments) > 0)
                    <x-card title="Recent Payments">
                        <div class="overflow-x-auto">
                            <table class="table w-full">
                                <thead>
                                    <tr>
                                        <th>Payment Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach(array_slice($payments, 0, 5) as $payment)
                                        <tr class="hover">
                                            <td>{{ \Carbon\Carbon::parse($payment['payment_date'])->format('M d, Y') }}</td>
                                            <td class="font-medium text-green-600">{{ $this->formatCurrency($payment['amount']) }}</td>
                                            <td>{{ $payment['payment_method'] ?? 'N/A' }}</td>
                                            <td class="font-mono text-sm">{{ $payment['reference_number'] ?? 'N/A' }}</td>
                                            <td>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    {{ ucfirst($payment['status'] ?? 'completed') }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-card>
                @endif
            </div>
        @endif

        <!-- Activity Tab -->
        @if($activeTab === 'activity')
            <x-card title="Enrollment Activity">
                <div class="space-y-6">
                    <!-- Enrollment Timeline -->
                    <div class="flow-root">
                        <ul role="list" class="-mb-8">
                            <!-- Enrollment Created -->
                            <li>
                                <div class="relative pb-8">
                                    <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                    <div class="relative flex space-x-3">
                                        <div>
                                            <span class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full ring-8 ring-white">
                                                <x-icon name="o-plus" class="w-4 h-4 text-white" />
                                            </span>
                                        </div>
                                        <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                            <div>
                                                <p class="text-sm text-gray-500">
                                                    Enrollment created for
                                                    <span class="font-medium text-gray-900">{{ $programEnrollment->curriculum->name ?? 'Unknown Program' }}</span>
                                                </p>
                                            </div>
                                            <div class="text-sm text-right text-gray-500 whitespace-nowrap">
                                                <time datetime="{{ $programEnrollment->created_at->toISOString() }}">
                                                    {{ $programEnrollment->created_at->format('M d, Y') }}
                                                </time>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>

                            <!-- Subject Enrollments -->
                            @foreach(array_slice($subjectEnrollments, 0, 3) as $subjectEnrollment)
                                <li>
                                    <div class="relative pb-8">
                                        @if(!$loop->last || count($invoices) > 0 || count($payments) > 0)
                                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                        @endif
                                        <div class="relative flex space-x-3">
                                            <div>
                                                <span class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full ring-8 ring-white">
                                                    <x-icon name="o-book-open" class="w-4 h-4 text-white" />
                                                </span>
                                            </div>
                                            <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                                <div>
                                                    <p class="text-sm text-gray-500">
                                                        Enrolled in subject
                                                        <span class="font-medium text-gray-900">{{ $subjectEnrollment['subject']['name'] ?? 'Unknown Subject' }}</span>
                                                    </p>
                                                </div>
                                                <div class="text-sm text-right text-gray-500 whitespace-nowrap">
                                                    <time datetime="{{ $subjectEnrollment['created_at'] }}">
                                                        {{ \Carbon\Carbon::parse($subjectEnrollment['created_at'])->format('M d, Y') }}
                                                    </time>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach

                            <!-- Recent Invoices -->
                            @foreach(array_slice($invoices, 0, 2) as $invoice)
                                <li>
                                    <div class="relative pb-8">
                                        @if(!$loop->last || count($payments) > 0)
                                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                        @endif
                                        <div class="relative flex space-x-3">
                                            <div>
                                                <span class="flex items-center justify-center w-8 h-8 bg-yellow-500 rounded-full ring-8 ring-white">
                                                    <x-icon name="o-document-text" class="w-4 h-4 text-white" />
                                                </span>
                                            </div>
                                            <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                                <div>
                                                    <p class="text-sm text-gray-500">
                                                        Invoice
                                                        <span class="font-medium text-gray-900">{{ $invoice['invoice_number'] }}</span>
                                                        generated for {{ $this->formatCurrency($invoice['amount']) }}
                                                    </p>
                                                </div>
                                                <div class="text-sm text-right text-gray-500 whitespace-nowrap">
                                                    <time datetime="{{ $invoice['invoice_date'] }}">
                                                        {{ \Carbon\Carbon::parse($invoice['invoice_date'])->format('M d, Y') }}
                                                    </time>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach

                            <!-- Recent Payments -->
                            @foreach(array_slice($payments, 0, 2) as $payment)
                                <li>
                                    <div class="relative {{ !$loop->last ? 'pb-8' : '' }}">
                                        @if(!$loop->last)
                                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                        @endif
                                        <div class="relative flex space-x-3">
                                            <div>
                                                <span class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full ring-8 ring-white">
                                                    <x-icon name="o-currency-dollar" class="w-4 h-4 text-white" />
                                                </span>
                                            </div>
                                            <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                                <div>
                                                    <p class="text-sm text-gray-500">
                                                        Payment of
                                                        <span class="font-medium text-gray-900">{{ $this->formatCurrency($payment['amount']) }}</span>
                                                        received
                                                    </p>
                                                </div>
                                                <div class="text-sm text-right text-gray-500 whitespace-nowrap">
                                                    <time datetime="{{ $payment['payment_date'] }}">
                                                        {{ \Carbon\Carbon::parse($payment['payment_date'])->format('M d, Y') }}
                                                    </time>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <!-- Status Changes (if available) -->
                    @if($programEnrollment->updated_at != $programEnrollment->created_at)
                        <div class="pt-6 mt-8 border-t">
                            <h4 class="mb-4 text-sm font-medium text-gray-900">Status Changes</h4>
                            <div class="p-4 rounded-lg bg-gray-50">
                                <div class="flex items-center text-sm text-gray-600">
                                    <x-icon name="o-clock" class="w-4 h-4 mr-2" />
                                    <span>Last updated: {{ $programEnrollment->updated_at->format('M d, Y \a\t g:i A') }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>
        @endif
    </div>

    <!-- Quick Action Floating Button -->
    @if($stats['outstanding_amount'] > 0)
        <div class="fixed z-50 bottom-6 right-6">
            <x-button
                icon="o-credit-card"
                wire:click="redirectToInvoices"
                class="shadow-lg btn-circle btn-primary btn-lg"
                title="Make Payment"
            />
        </div>
    @endif
</div>
