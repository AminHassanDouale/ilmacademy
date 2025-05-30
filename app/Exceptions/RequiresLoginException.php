<?php

namespace App\Exceptions;

use Exception;

/**
 * Forces inline login.
 *
 * See `app/exceptions/Handler.php`
 */
class RequiresLoginException extends Exception
{
    public function __construct(public string $redirect_url = '')
    {
        parent::__construct();
    }
}
