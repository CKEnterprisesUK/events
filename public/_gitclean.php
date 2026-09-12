<?php

/**
 * ONE-TIME, SELF-DELETING maintenance script — clears the cPanel Git
 * "uncommitted changes on the checked-out branch" block WITHOUT a terminal.
 *
 * WHY THIS EXISTS
 *   cPanel refuses to deploy when the host working tree is dirty (usually file
 *   permission/mode drift or EOL renormalization on the shared host). With no
 *   SSH/terminal there is no way to run `git checkout -- .` on the host. This
 *   page does exactly that, over HTTPS, then removes itself.
 *
 * SAFETY
 *   * Requires a secret token in the URL (set TOKEN below) so it cannot be run
 *     by anyone who stumbles on it. You are also behind Cloudflare access.
 *   * Runs ONLY `git config core.fileMode false` and `git checkout -- .`.
 *     It NEVER runs `git clean`, so untracked files (.env, storage, caches)
 *     are never deleted — only drift to TRACKED files is discarded.
 *   * Deletes itself at the end so it cannot linger as an attack surface.
 *
 * USAGE
 *   1. Deploy/upload so this file is at public/_gitclean.php on the host.
 *   2. Visit  https://preprod.events.ckent.uk/_gitclean.php?token=YOUR_TOKEN
 *   3. Read the output (git status before/after), then it self-deletes.
 *   4. Go back to cPanel Git → Deploy HEAD Commit — the block should be gone.
 *
 * REMOVE THIS FILE FROM THE REPO after the deploy pipeline is stable; it is a
 * deliberate, temporary escape hatch, not a permanent fixture.
 */

declare(strict_types=1);

// ---- CHANGE THIS before deploying -----------------------------------------
const TOKEN = 'CHANGE-ME-to-a-long-random-string';

// The app root is one level above public/.
$appRoot = dirname(__DIR__);

header('Content-Type: text/plain; charset=utf-8');

if (! hash_equals(TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    echo "Forbidden: missing or wrong ?token=\n";
    exit;
}

if (TOKEN === 'CHANGE-ME-to-a-long-random-string') {
    http_response_code(500);
    echo "Refusing to run: edit public/_gitclean.php and set a real TOKEN first.\n";
    exit;
}

if (! function_exists('shell_exec')) {
    http_response_code(500);
    echo "shell_exec is disabled on this host — cannot run git from PHP.\n";
    echo "Fall back to deleting and re-adding the repo in cPanel Git.\n";
    exit;
}

function git(string $appRoot, string $args): string
{
    $cmd = 'git -C '.escapeshellarg($appRoot).' '.$args.' 2>&1';

    return (string) shell_exec($cmd);
}

echo "App root: {$appRoot}\n\n";
echo "--- git status BEFORE ---\n";
echo git($appRoot, 'status --short') ?: "(clean)\n";

echo "\n--- ignoring file-mode changes ---\n";
echo git($appRoot, 'config core.fileMode false');
echo "core.fileMode set to false\n";

echo "\n--- discarding tracked-file drift (git checkout -- .) ---\n";
echo git($appRoot, 'checkout -- .') ?: "done\n";

echo "\n--- git status AFTER ---\n";
$after = git($appRoot, 'status --short');
echo $after === '' ? "(clean — cPanel can deploy now)\n" : $after;

// Self-delete so this escape hatch does not linger.
echo "\n--- self-delete ---\n";
if (@unlink(__FILE__)) {
    echo "Removed public/_gitclean.php from the host.\n";
} else {
    echo "WARNING: could not self-delete — delete public/_gitclean.php manually in File Manager.\n";
}
