<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The public privacy policy page. (Requirement 22.3)
 *
 * The Customer GDPR data-subject tools (export / delete-anonymise) now live on
 * the Customers dashboard surface — see {@see CustomerController} — so they act
 * on a Customer in context rather than a blindly-typed email. This controller
 * retains only the public privacy policy page, served at the reserved
 * `/privacy` prefix (no tenant, no auth), which always renders.
 */
class GdprController extends Controller
{
    /**
     * The public privacy policy page. No tenant, no auth — always renders.
     * (Requirement 22.3)
     */
    public function privacy(): View
    {
        return view('privacy');
    }
}
