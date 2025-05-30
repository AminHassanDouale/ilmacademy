<?php

namespace App\Traits;

use App\Models\Product;

/**
 * Handles a redirect back for an intended action before user had logged in.
 * This allows guest users click on `like` and `add to cart` buttons and persist their intention after login.
 *
 * It pairs with `app/Traits/ForcesLogin.php`
 */
trait HandlesRedirectBackAction
{
    public function executePreviousIntendedAction(): void
    {
        [$action, $product_id] = str(request()->get('action'))->explode(',')->toArray() + [null, null];

        if ($action && $product_id) {
            $this->{$action}(Product::findOrFail($product_id));
        }
    }
}
