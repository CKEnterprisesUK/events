<?php

namespace App\Http\Controllers;

use App\Support\HelpCentre;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The in-dashboard Help & Knowledge portal: a searchable, self-service
 * knowledge base that answers the common "how do I…?" and "why did…?"
 * questions before a user needs to raise a ticket.
 *
 * Deliberately open to every authenticated Company_User (any role) and to an
 * impersonating Super_Admin — the content is generic product guidance, not
 * tenant data, so no Company role gate applies and the route does not need the
 * tenant scope (mirroring {@see ProfileController}). The articles are authored
 * in {@see HelpCentre} rather than the database: they change with the product,
 * not per Company, so keeping them in code keeps them versioned alongside it.
 */
class HelpController extends Controller
{
    /**
     * Show the knowledge base: grouped articles plus a link to raise a ticket
     * when self-service has not resolved the issue.
     */
    public function index(Request $request): View
    {
        return view('dashboard.help.index', [
            'sections' => HelpCentre::sections(),
        ]);
    }
}
