<?php

namespace App\Traits;

use App\Models\User;
use Livewire\Attributes\Computed;

trait HasUser
{
    #[Computed]
    public function user(): User
    {
        return auth()->user() ?? new User();
    }
}
