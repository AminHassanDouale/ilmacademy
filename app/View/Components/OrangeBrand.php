<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class OrangeBrand extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return <<<'blade'
                <a href="/" wire:navigate>
                    <div class="flex items-center gap-1">
                        <img src="/images/orange.png" width="30" />
                        <span class="font-bold text-3xl mr-3 bg-gradient-to-r from-amber-500 to-amber-300 bg-clip-text text-transparent ">
                            orange.
                        </span>
                    </div>
                </a>
            blade;
    }
}
