<?php

namespace App\Traits;

use App\Exceptions\RequiresLoginException;

/**
 * Some actions require user log in,
 * so we set the intended action to get triggered when it is redirected back after login.
 *
 * Note this is a tricky approach:
 *   - The button is always displayed on screen.
 *   - If a guest user click it, it will be redirected to login page.
 *   - Then it will be redirected back and app executes the previous intended action.
 */
trait ForcesLogin
{
    use HasUser;

    public function forcesLogin(string $action): void
    {
        if (! $this->user()->id) {
            $previousURL = url()->previous();
            $action = str(url()->previous())->contains('=') ? "&action=$action" : "?action=$action";

            throw new RequiresLoginException($previousURL . $action);
        }
    }
}
