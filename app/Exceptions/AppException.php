<?php

namespace App\Exceptions;

use Exception;

/**
 * This custom domain exception will be converted into a Toast.
 *
 * See `app/exceptions/Handler.php`
 */
class AppException extends Exception
{
    public function __construct(string $message = "", public ?string $description = null)
    {
        parent::__construct($message);
    }
}
