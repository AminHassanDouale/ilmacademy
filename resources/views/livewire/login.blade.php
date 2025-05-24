<?php

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.empty')]
#[Title('Login - IlmAcademie')]
class extends Component {
    public string $email = '';
    public string $password = '';
    public bool $remember = false;
    public string $error = '';

    public function login()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ], [
            'email.required' => 'Email address is required',
            'email.email' => 'Email address is not valid',
            'password.required' => 'Password is required',
        ]);

        // Authentication attempt
        if (Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            request()->session()->regenerate();

            // Get authenticated user
            $user = Auth::user();

            // Success message
            session()->flash('success', 'Login successful! Welcome ' . $user->name);

            // Redirect to dashboard
            return redirect()->intended('/dashboard');
        }

        // If authentication fails
        $this->error = 'The provided credentials do not match our records.';
    }
};

?>

<div class="flex items-center justify-center min-h-screen px-4 py-12 bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 sm:px-6 lg:px-8">
    <div class="w-full max-w-md space-y-8">
        <!-- Header -->
        <div class="text-center">
            <div class="flex items-center justify-center w-24 h-24 mx-auto bg-white rounded-full shadow-lg">
                <i class="text-3xl text-indigo-600 bi bi-database-gear"></i>
            </div>
            <h2 class="mt-6 text-3xl font-extrabold text-white">
                Ilm Academie
            </h2>
            <p class="mt-2 text-lg text-indigo-100">
                Secure Access Portal
            </p>
        </div>

        <!-- Login Card -->
        <div class="p-8 bg-white rounded-lg shadow-xl">
            <div class="space-y-6">
                <!-- Error/Success Messages -->
                @if($error)
                    <div class="p-4 border-l-4 border-red-400 rounded bg-red-50">
                        <div class="flex">
                            <i class="mr-3 text-red-400 bi bi-exclamation-triangle"></i>
                            <p class="text-sm text-red-700">{{ $error }}</p>
                        </div>
                    </div>
                @endif

                @if(session('success'))
                    <div class="p-4 border-l-4 border-green-400 rounded bg-green-50">
                        <div class="flex">
                            <i class="mr-3 text-green-400 bi bi-check-circle"></i>
                            <p class="text-sm text-green-700">{{ session('success') }}</p>
                        </div>
                    </div>
                @endif

                <!-- Login Form -->
                <form wire:submit="login" class="space-y-6">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">
                            <i class="mr-2 bi bi-envelope"></i>Email Address
                        </label>
                        <div class="mt-1">
                            <input
                                wire:model="email"
                                id="email"
                                name="email"
                                type="email"
                                autocomplete="email"
                                required
                                class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md appearance-none focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                placeholder="Enter your email"
                            >
                        </div>
                        @error('email')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">
                            <i class="mr-2 bi bi-key"></i>Password
                        </label>
                        <div class="relative mt-1">
                            <input
                                wire:model="password"
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                                class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md appearance-none focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                placeholder="Enter your password"
                            >
                            <button
                                type="button"
                                onclick="togglePassword()"
                                class="absolute inset-y-0 right-0 flex items-center pr-3"
                            >
                                <i class="text-gray-400 bi bi-eye" id="password-toggle"></i>
                            </button>
                        </div>
                        @error('password')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input
                                wire:model="remember"
                                id="remember"
                                name="remember"
                                type="checkbox"
                                class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
                            >
                            <label for="remember" class="block ml-2 text-sm text-gray-900">
                                Remember me
                            </label>
                        </div>

                        <div class="text-sm">
                            <a href="#" class="font-medium text-indigo-600 hover:text-indigo-500">
                                Forgot password?
                            </a>
                        </div>
                    </div>

                    <div>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:loading.class="cursor-not-allowed opacity-70"
                            class="relative flex justify-center w-full px-4 py-2 text-sm font-medium text-white transition duration-150 ease-in-out bg-indigo-600 border border-transparent rounded-md group hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <span wire:loading.remove class="flex items-center">
                                <i class="mr-2 bi bi-box-arrow-in-right"></i>
                                Login
                            </span>
                            <span wire:loading class="flex items-center">
                                <i class="mr-2 bi bi-arrow-repeat animate-spin"></i>
                                Logging in...
                            </span>
                        </button>
                    </div>
                </form>

                <!-- Registration Link (optional) -->
                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-600">
                        Don't have an account yet?
                        <a href="#" class="font-medium text-indigo-600 hover:text-indigo-500">
                            Create an account
                        </a>
                    </p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center">
            <p class="text-sm text-indigo-100">
                © 2025 CDRAPP. All rights reserved.
            </p>
            <div class="mt-2 space-x-4">
                <a href="#" class="text-xs text-indigo-200 hover:text-white">Terms of Use</a>
                <span class="text-indigo-300">•</span>
                <a href="#" class="text-xs text-indigo-200 hover:text-white">Privacy Policy</a>
                <span class="text-indigo-300">•</span>
                <a href="#" class="text-xs text-indigo-200 hover:text-white">Support</a>
            </div>
        </div>
    </div>
</div>

<script>
    function togglePassword() {
        const passwordField = document.getElementById('password');
        const toggleIcon = document.getElementById('password-toggle');

        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            toggleIcon.className = 'bi bi-eye-slash text-gray-400';
        } else {
            passwordField.type = 'password';
            toggleIcon.className = 'bi bi-eye text-gray-400';
        }
    }

    // Auto-focus on email field when loading
    document.addEventListener('livewire:initialized', () => {
        document.getElementById('email').focus();
    });
</script>
