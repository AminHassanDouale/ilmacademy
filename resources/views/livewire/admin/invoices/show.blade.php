<div>
    <!-- Page header -->
    <x-header title="Invoice #{{ $invoice->invoice_number }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Status Badge -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($invoice->status) }}">
                {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}
            </span>

            @if($this->isOverdue)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800 ml-2">
                    <x-icon name="o-exclamation-triangle" class="w-4 h-4 mr-1" />
                    {{ abs($this->daysUntilDue) }} days overdue
                </span>
            @elseif($this->daysUntilDue >= 0 && $this->daysUntilDue <= 7 && !in_array($invoice->status, ['paid', 'cancelled']))
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800 ml-2">
                    <x-icon name="o-clock" class="w-4 h-4 mr-1" />
                    Due in {{ $this->daysUntilDue }} day{{ $this->daysUntilDue === 1 ? '' : 's' }}
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <!-- Action buttons based on status -->
            @if($invoice->status === 'draft')
                <x-button
                    label="Mark as Sent"
                    icon="o-paper-airplane"
                    wire:click="markAsSent"
                    class="btn-primary"
                    wire:confirm="Are you sure you want to mark this invoice as sent?"
                />
            @endif

            @if(in_array($invoice->status, ['sent', 'pending', 'overdue']))
                <x-button
                    label="Mark as Paid"
                    icon="o-check"
                    wire:click="markAsPaid"
                    class="btn-success"
                    wire:confirm="Are you sure you want to mark this invoice as paid?"
                />
            @endif

            @if(!in_array($invoice->status, ['paid', 'cancelled']))
                <x-button
                    label="Cancel Invoice"
                    icon="o-x-mark"
                    wire:click="cancelInvoice"
                    class="btn-error"
                    wire:confirm="Are you sure you want to cancel this invoice? This action cannot be undone."
                />
            @endif

            <x-button
                label="Edit"
                icon="o-pencil"
                link="{{ route('admin.invoices.edit', $invoice->id) }}"
                class="btn-primary"
            />

            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('admin.invoices.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Invoice Details -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Invoice Information -->
            <x-card title="Invoice Information">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Invoice Number</div>
                        <div class="font-mono text-lg font-semibold">{{ $invoice->invoice_number }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Amount</div>
                        <div class="text-2xl font-bold text-green-600">${{ number_format($invoice->amount, 2) }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Invoice Date</div>
                        <div>{{ $this->formatDate($invoice->invoice_date) }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Due Date</div>
                        <div class="{{ $this->isOverdue ? 'text-red-600 font-medium' : '' }}">
                            {{ $this->formatDate($invoice->due_date) }}
                            @if($this->isOverdue)
                                <div class="text-xs text-red-500">Overdue by {{ abs($this->daysUntilDue) }} day{{ abs($this->daysUntilDue) === 1 ? '' : 's' }}</div>
                            @endif
                        </div>
                    </div>

                    @if($invoice->paid_date)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Paid Date</div>
                            <div class="text-green-600 font-medium">{{ $this->formatDate($invoice->paid_date) }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($invoice->status) }}">
                                {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}
                            </span>
                        </div>
                    </div>

                    @if($invoice->description)
                        <div class="md:col-span-2">
                            <div class="text-sm font-medium text-gray-500">Description</div>
                            <div>{{ $invoice->description }}</div>
                        </div>
                    @endif

                    @if($invoice->notes)
                        <div class="md:col-span-2">
                            <div class="text-sm font-medium text-gray-500">Notes</div>
                            <div class="p-3 bg-gray-50 rounded-md">{{ $invoice->notes }}</div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Student Information -->
            @if($invoice->student || $invoice->programEnrollment)
                <x-card title="Student Information">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Student Name</div>
                            <div class="font-semibold">
                                {{ $invoice->student ? $invoice->student->full_name :
                                   ($invoice->programEnrollment && $invoice->programEnrollment->childProfile ?
                                    $invoice->programEnrollment->childProfile->full_name : 'Unknown Student') }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Curriculum</div>
                            <div>
                                {{ $invoice->curriculum ? $invoice->curriculum->name :
                                   ($invoice->programEnrollment && $invoice->programEnrollment->curriculum ?
                                    $invoice->programEnrollment->curriculum->name : 'Unknown Curriculum') }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Academic Year</div>
                            <div class="flex items-center">
                                {{ $invoice->academicYear ? $invoice->academicYear->name :
                                   ($invoice->programEnrollment && $invoice->programEnrollment->academicYear ?
                                    $invoice->programEnrollment->academicYear->name : 'Unknown Academic Year') }}
                                @if(($invoice->academicYear && $invoice->academicYear->is_current) ||
                                    ($invoice->programEnrollment && $invoice->programEnrollment->academicYear && $invoice->programEnrollment->academicYear->is_current))
                                    <x-badge label="Current" color="success" class="ml-2 badge-xs" />
                                @endif
                            </div>
                        </div>

                        @if($invoice->programEnrollment)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Program Enrollment</div>
                                <div class="flex items-center">
                                    <span>Enrollment #{{ $invoice->programEnrollment->id }}</span>
                                    <x-button
                                        label="View"
                                        icon="o-eye"
                                        link="{{ route('admin.enrollments.show', $invoice->programEnrollment->id) }}"
                                        class="btn-ghost btn-xs ml-2"
                                    />
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Activity Log -->
            <x-card title="Activity Log">
                @if($activityLogs->count() > 0)
                    <div class="space-y-4">
                        @foreach($activityLogs as $log)
                            <div class="flex items-start space-x-4 pb-4 border-b border-gray-100 last:border-b-0">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center w-8 h-8 rounded-full bg-gray-100">
                                        <x-icon name="{{ $this->getActivityIcon($log->action) }}" class="w-4 h-4 {{ $this->getActivityColor($log->action) }}" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm">
                                        <span class="font-medium text-gray-900">
                                            {{ $log->user ? $log->user->name : 'System' }}
                                        </span>
                                        <span class="text-gray-600">{{ $log->description }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        {{ $log->created_at->diffForHumans() }} • {{ $log->created_at->format('M d, Y \a\t g:i A') }}
                                    </div>
                                    @if($log->additional_data && is_array($log->additional_data) && count($log->additional_data) > 0)
                                        <div class="mt-2">
                                            <details class="text-xs">
                                                <summary class="text-gray-500 cursor-pointer hover:text-gray-700">View details</summary>
                                                <div class="mt-2 p-2 bg-gray-50 rounded text-xs font-mono">
                                                    @foreach($log->additional_data as $key => $value)
                                                        @if(!in_array($key, ['ip', 'ip_address']))
                                                            <div><strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong>
                                                                @if(is_array($value) || is_object($value))
                                                                    {{ json_encode($value, JSON_PRETTY_PRINT) }}
                                                                @else
                                                                    {{ $value }}
                                                                @endif
                                                            </div>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            </details>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-8">
                        <x-icon name="o-document-text" class="w-12 h-12 text-gray-300 mx-auto" />
                        <div class="mt-2 text-sm text-gray-500">No activity logs available</div>
                    </div>
                @endif
            </x-card>
        </div>

        <!-- Right column (1/3) - Additional Info -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            @if(!in_array($invoice->status, ['paid', 'cancelled']))
                <x-card title="Quick Actions">
                    <div class="space-y-3">
                        @if($invoice->status === 'draft')
                            <x-button
                                label="Mark as Sent"
                                icon="o-paper-airplane"
                                wire:click="markAsSent"
                                class="btn-primary w-full"
                                wire:confirm="Are you sure you want to mark this invoice as sent?"
                            />
                        @endif

                        @if(in_array($invoice->status, ['sent', 'pending', 'overdue']))
                            <x-button
                                label="Mark as Paid"
                                icon="o-check"
                                wire:click="markAsPaid"
                                class="btn-success w-full"
                                wire:confirm="Are you sure you want to mark this invoice as paid?"
                            />
                        @endif

                        <x-button
                            label="Edit Invoice"
                            icon="o-pencil"
                            link="{{ route('admin.invoices.edit', $invoice->id) }}"
                            class="btn-outline w-full"
                        />

                        <x-button
                            label="Cancel Invoice"
                            icon="o-x-mark"
                            wire:click="cancelInvoice"
                            class="btn-error w-full"
                            wire:confirm="Are you sure you want to cancel this invoice? This action cannot be undone."
                        />
                    </div>
                </x-card>
            @endif

            <!-- Invoice Summary -->
            <x-card title="Invoice Summary">
                <div class="space-y-4">
                    <div class="flex justify-between">
                        <span class="text-sm font-medium text-gray-500">Subtotal</span>
                        <span class="font-mono">${{ number_format($invoice->amount, 2) }}</span>
                    </div>

                    <div class="flex justify-between">
                        <span class="text-sm font-medium text-gray-500">Tax</span>
                        <span class="font-mono">$0.00</span>
                    </div>

                    <div class="border-t pt-4">
                        <div class="flex justify-between">
                            <span class="text-base font-semibold">Total</span>
                            <span class="text-xl font-bold text-green-600">${{ number_format($invoice->amount, 2) }}</span>
                        </div>
                    </div>

                    @if($invoice->status === 'paid')
                        <div class="bg-green-50 p-3 rounded-md">
                            <div class="flex items-center">
                                <x-icon name="o-check-circle" class="w-5 h-5 text-green-600 mr-2" />
                                <span class="text-sm font-medium text-green-800">Paid in Full</span>
                            </div>
                            @if($invoice->paid_date)
                                <div class="text-xs text-green-600 mt-1">
                                    Paid on {{ $invoice->paid_date->format('M d, Y') }}
                                </div>
                            @endif
                        </div>
                    @elseif($invoice->status === 'cancelled')
                        <div class="bg-gray-50 p-3 rounded-md">
                            <div class="flex items-center">
                                <x-icon name="o-x-circle" class="w-5 h-5 text-gray-600 mr-2" />
                                <span class="text-sm font-medium text-gray-800">Cancelled</span>
                            </div>
                        </div>
                    @elseif($this->isOverdue)
                        <div class="bg-red-50 p-3 rounded-md">
                            <div class="flex items-center">
                                <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-red-600 mr-2" />
                                <span class="text-sm font-medium text-red-800">Overdue</span>
                            </div>
                            <div class="text-xs text-red-600 mt-1">
                                {{ abs($this->daysUntilDue) }} day{{ abs($this->daysUntilDue) === 1 ? '' : 's' }} past due date
                            </div>
                        </div>
                    @else
                        <div class="bg-blue-50 p-3 rounded-md">
                            <div class="flex items-center">
                                <x-icon name="o-clock" class="w-5 h-5 text-blue-600 mr-2" />
                                <span class="text-sm font-medium text-blue-800">
                                    {{ $this->daysUntilDue >= 0 ? 'Due in ' . $this->daysUntilDue . ' day' . ($this->daysUntilDue === 1 ? '' : 's') : 'Payment Expected' }}
                                </span>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- System Information -->
            <x-card title="System Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $this->formatDate($invoice->created_at) }}</div>
                        @if($invoice->createdBy)
                            <div class="text-xs text-gray-500">by {{ $invoice->createdBy->name }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $this->formatDate($invoice->updated_at) }}</div>
                    </div>

                    @if($invoice->payment_plan_id)
                        <div>
                            <div class="font-medium text-gray-500">Payment Plan</div>
                            <div>Plan ID: {{ $invoice->payment_plan_id }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="font-medium text-gray-500">Invoice ID</div>
                        <div class="font-mono text-xs">{{ $invoice->id }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Related Links -->
            <x-card title="Related">
                <div class="space-y-2">
                    @if($invoice->student)
                        <x-button
                            label="View Student Profile"
                            icon="o-user"
                            link="{{ route('admin.students.show', $invoice->student->id) }}"
                            class="btn-ghost btn-sm w-full justify-start"
                        />
                    @endif

                    @if($invoice->programEnrollment)
                        <x-button
                            label="View Program Enrollment"
                            icon="o-academic-cap"
                            link="{{ route('admin.enrollments.show', $invoice->programEnrollment->id) }}"
                            class="btn-ghost btn-sm w-full justify-start"
                        />
                    @endif

                    @if($invoice->curriculum)
                        <x-button
                            label="View Curriculum"
                            icon="o-book-open"
                            link="{{ route('admin.curricula.show', $invoice->curriculum->id) }}"
                            class="btn-ghost btn-sm w-full justify-start"
                        />
                    @endif

                    <x-button
                        label="All Invoices"
                        icon="o-document-text"
                        link="{{ route('admin.invoices.index') }}"
                        class="btn-ghost btn-sm w-full justify-start"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
