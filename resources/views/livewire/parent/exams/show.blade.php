<?php

use App\Models\ExamResult;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public ExamResult $exam;

    public function mount(ExamResult $exam): void
    {
        // Ensure parent can only view their own children's exams
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            $this->error('Parent profile not found.');
            $this->redirect(route('parent.exams.index'));
            return;
        }

        // Get all children IDs for this parent
        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        // Check if the exam belongs to one of the parent's children
        if (!in_array($exam->child_profile_id, $childrenIds)) {
            $this->error('You do not have permission to view this exam result.');
            $this->redirect(route('parent.exams.index'));
            return;
        }

        $this->exam = $exam->load(['childProfile.user']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed exam result details: {$this->exam->title} for {$this->exam->childProfile->user->name}",
            ExamResult::class,
            $this->exam->id,
            ['ip' => request()->ip()]
        );
    }

    #[Title('Exam Result Details')]
    public function title(): string
    {
        return "Exam: " . ($this->exam->title ?? 'Untitled Exam');
    }

    // Calculate pass status
    public function passStatus()
    {
        $score = $this->exam->score;

        if ($score >= 90) {
            return [
                'status' => 'Excellent',
                'color' => 'success',
                'icon' => 'o-star',
                'message' => 'Outstanding performance!'
            ];
        } elseif ($score >= 80) {
            return [
                'status' => 'Very Good',
                'color' => 'success',
                'icon' => 'o-check-circle',
                'message' => 'Great achievement!'
            ];
        } elseif ($score >= 70) {
            return [
                'status' => 'Good',
                'color' => 'success',
                'icon' => 'o-check',
                'message' => 'Well done!'
            ];
        } elseif ($score >= 60) {
            return [
                'status' => 'Passed',
                'color' => 'info',
                'icon' => 'o-check',
                'message' => 'Satisfactory result.'
            ];
        } else {
            return [
                'status' => 'Failed',
                'color' => 'error',
                'icon' => 'o-x-circle',
                'message' => 'Needs improvement.'
            ];
        }
    }
};?>

<div>
    <!-- Page header -->
    <x-header :title="$exam->title ?? 'Exam Result Details'" separator>
        <x-slot:subtitle>
            {{ $exam->childProfile->user->name }}
        </x-slot:subtitle>

        <x-slot:actions>
            <x-button label="Back to Exams" icon="o-arrow-left" link="{{ route('parent.exams.index') }}" />
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 md:grid-cols-3">
        <!-- EXAM RESULT CARD -->
        <div class="md:col-span-2">
            <x-card title="Exam Result" separator>
                <div class="flex flex-col items-center mb-8">
                    <!-- Score Display -->
                    <div class="relative mb-6">
                        <div class="radial-progress text-{{ $passStatus()['color'] }}" style="--value:{{ $exam->score }}; --size:12rem; --thickness:1rem;">
                            <span class="text-3xl font-bold">{{ $exam->score }}%</span>
                        </div>
                        <div class="absolute -top-2 -right-2">
                            <div class="badge badge-{{ $passStatus()['color'] }} p-3">
                                <x-icon name="{{ $passStatus()['icon'] }}" class="w-5 h-5 mr-1" />
                                {{ $passStatus()['status'] }}
                            </div>
                        </div>
                    </div>

                    <div class="mb-2 text-xl font-bold">{{ $passStatus()['message'] }}</div>

                    <div class="mt-1 badge badge-ghost">
                        Taken on {{ $exam->created_at->format('d/m/y') }}
                    </div>
                </div>

                <!-- Feedback Section -->
                @if($exam->comments)
                    <div class="mb-6">
                        <h3 class="mb-2 text-lg font-semibold">Feedback</h3>
                        <div class="p-4 rounded-lg bg-base-200">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0">
                                    <x-icon name="o-chat-bubble-left-right" class="w-6 h-6 text-primary" />
                                </div>
                                <div>
                                    {{ $exam->comments }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Strengths and Areas for Improvement -->
                @if(isset($exam->details) && is_array($exam->details))
                    <div class="grid gap-6 md:grid-cols-2">
                        <!-- Strengths -->
                        @if(isset($exam->details['strengths']))
                            <div>
                                <h3 class="mb-2 text-lg font-semibold">Strengths</h3>
                                <ul class="pl-5 space-y-1 list-disc">
                                    @foreach($exam->details['strengths'] as $strength)
                                        <li>{{ $strength }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <!-- Areas for Improvement -->
                        @if(isset($exam->details['improvements']))
                            <div>
                                <h3 class="mb-2 text-lg font-semibold">Areas for Improvement</h3>
                                <ul class="pl-5 space-y-1 list-disc">
                                    @foreach($exam->details['improvements'] as $improvement)
                                        <li>{{ $improvement }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            </x-card>
        </div>

        <!-- EXAM & CHILD INFO CARD -->
        <div class="md:col-span-1">
            <x-card title="Information" separator>
                <!-- Child Information -->
                <div class="mb-6">
                    <h3 class="mb-1 text-sm text-gray-500">Student</h3>
                    <div class="flex items-center gap-3">
                        <div class="avatar">
                            <div class="w-12 h-12 mask mask-squircle">
                                @if ($exam->childProfile->photo)
                                    <img src="{{ asset('storage/' . $exam->childProfile->photo) }}" alt="{{ $exam->childProfile->user->name }}">
                                @else
                                    <img src="{{ $exam->childProfile->user->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $exam->childProfile->user->name }}">
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="font-semibold">{{ $exam->childProfile->user->name }}</div>
                        </div>
                    </div>
                </div>

                <!-- Exam Details -->
                <div class="space-y-4">
                    <div>
                        <h3 class="mb-1 text-sm text-gray-500">Exam Title</h3>
                        <p class="font-medium">{{ $exam->title ?? 'Untitled Exam' }}</p>
                    </div>

                    <div>
                        <h3 class="mb-1 text-sm text-gray-500">Date</h3>
                        <p class="font-medium">{{ $exam->created_at->format('d/m/Y') }}</p>
                    </div>

                    @if($exam->subject)
                        <div>
                            <h3 class="mb-1 text-sm text-gray-500">Subject</h3>
                            <p class="font-medium">{{ $exam->subject }}</p>
                        </div>
                    @endif

                    <div>
                        <h3 class="mb-1 text-sm text-gray-500">Status</h3>
                        <x-badge label="{{ $passStatus()['status'] }}" color="{{ $passStatus()['color'] }}" />
                    </div>

                    @if($exam->grade)
                        <div>
                            <h3 class="mb-1 text-sm text-gray-500">Grade</h3>
                            <p class="font-medium">{{ $exam->grade }}</p>
                        </div>
                    @endif
                </div>

                @if($exam->resources)
                    <div class="my-6 divider"></div>

                    <!-- Additional Resources -->
                    <div>
                        <h3 class="mb-3 font-semibold">Additional Resources</h3>
                        <div class="space-y-2">
                            @foreach($exam->resources as $resource)
                                <a href="{{ $resource['url'] }}" target="_blank" class="flex items-center p-2 transition-colors rounded-lg hover:bg-base-200">
                                    <x-icon name="o-document-text" class="w-5 h-5 mr-2 text-primary" />
                                    <span>{{ $resource['title'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</div>
