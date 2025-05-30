<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="grid lg:grid-cols-2 mt-10 gap-10 items-center">
    <div>
        <img src="/images/support-us.png" width="300" class="mx-auto" />
    </div>
    <div>
        <p class="text-3xl leading-10 mb-8 font-bold">
            MaryUI components <span class="underline decoration-pink-500 font-bold">are open source</span>
            <x-icon name="o-heart" class="w-10 h-10 text-pink-500" />
        </p>
        <p class="text-lg leading-7">
            Deep dive into the source code of this demo and
            <span class="bg-warning p-1 font-bold dark:text-white">get amazed</span>
            how much you can do with <span class="underline decoration-warning font-bold">minimal effort</span> learning by example.
        </p>
        <p class="text-lg leading-7 mt-5">
            Each demo contains <span class="underline decoration-warning font-bold">real world code</span> and straight approaches to get the most out of MaryUI and Livewire.
        </p>
        <p class="text-lg leading-7 mt-5">
            Support MaryUI buying this demo.
        </p>
        <div class="mt-8">
            <x-button label="Buy this demo" icon-right="o-arrow-right" link="https://mary-ui.lemonsqueezy.com/checkout/buy/e1ab8189-d5fa-4127-9f19-794c1f2c3468?discount=0"
                      class="btn-primary" external />
        </div>
    </div>
</div>
