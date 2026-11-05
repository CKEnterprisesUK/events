<?php

namespace App\Http\Controllers;

use App\Models\HelpCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The in-dashboard Help & Knowledge portal: a self-service knowledge base that
 * answers the common "how do I…?" and "why did…?" questions before a user needs
 * to raise a ticket.
 *
 * Deliberately open to every authenticated Company_User (any role) and to an
 * impersonating Super_Admin — the content is generic product guidance, not
 * tenant data, so no Company role gate applies and the route does not need the
 * tenant scope (mirroring {@see ProfileController}).
 *
 * The articles now live in the database (`help_categories` / `help_articles`),
 * authored by a Super_Admin and shared with the public Knowledge Base at `/help`
 * ({@see KnowledgeBaseController}): one source of truth for both surfaces. This
 * dashboard view renders the same published content inside the app shell, with
 * a prominent "Contact support" call to action.
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
            'categories' => HelpCategory::published(),
        ]);
    }
}
