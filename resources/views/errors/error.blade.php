<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-base-200 font-sans antialiased">
<x-main full-width>
    <x-slot:content>
        <div class="text-center">
            <x-icon name="o-code-bracket" class="w-20 h-20 bg-red-500 text-base-100 p-2 rounded-full" />

            <div class="text-2xl font-bold mt-5">{{ $title }}</div>
            <div class="text-lg mt-3">{{ $detail }}</div>

            <div class="grid lg:flex gap-3 justify-center mt-16">
                <x-button :label="$isLivewire ? 'Close' : 'Reload'"
                          :icon="$isLivewire ? 'o-x-mark' : 'o-arrow-path'"
                          :onclick="$isLivewire ? 'window.parent.document.getElementById(\'livewire-error\').remove()' : 'window.location.reload()'"
                          class="btn-primary" />
                <x-button label="Go Home" icon="o-home" onclick="window.parent.location.href = '/'" class="btn-outline" />
            </div>
        </div>
    </x-slot:content>
</x-main>
</body>
</html>
