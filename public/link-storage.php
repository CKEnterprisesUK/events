<?php

/**
 * ONE-TIME storage symlink helper for shared hosting without SSH.
 *
 * Creates the symlink  public/storage  ->  storage/app/public
 * (the same thing `php artisan storage:link` does), so uploaded logos
 * served from /storage/... resolve on the public site.
 *
 * HOW TO USE
 *   1. Upload this file into your app's `public/` folder (next to index.php).
 *   2. Visit it in your browser WITH the token below, e.g.
 *        https://your-domain.com/link-storage.php?token=CHANGE_ME_TOKEN
 *   3. Read the result. When it says "linked", DELETE this file.
 *
 * SECURITY: this file can create a symlink on your server. The token stops
 * random visitors running it, but the only real safeguard is removing the
 * file once you're done. Delete it immediately after use.
 */

// ---- 1. Change this to any hard-to-guess string, then use it in the URL. ----
const LINK_TOKEN = '27f5c84f79668e59';

header('Content-Type: text/plain; charset=utf-8');

// ---- 2. Token gate ----------------------------------------------------------
$provided = $_GET['token'] ?? '';
if (LINK_TOKEN === 'CHANGE_ME_TOKEN') {
    http_response_code(500);
    exit("Refusing to run: edit link-storage.php and set LINK_TOKEN to your own secret first.\n");
}
if (!hash_equals(LINK_TOKEN, (string) $provided)) {
    http_response_code(403);
    exit("Forbidden: missing or wrong ?token=...\n");
}

// ---- 3. Resolve paths relative to this file (public/) -----------------------
$publicDir = __DIR__;                              // .../public
$link      = $publicDir . '/storage';              // .../public/storage
$target    = dirname($publicDir) . '/storage/app/public'; // .../storage/app/public

echo "Public dir : {$publicDir}\n";
echo "Link       : {$link}\n";
echo "Target      : {$target}\n";
echo str_repeat('-', 40) . "\n";

// ---- 4. Sanity: target must exist -------------------------------------------
if (!is_dir($target)) {
    // storage/app/public ships in the repo, but create it defensively.
    if (!@mkdir($target, 0755, true) && !is_dir($target)) {
        http_response_code(500);
        exit("ERROR: target folder does not exist and could not be created:\n  {$target}\n");
    }
    echo "Created missing target folder.\n";
}

// ---- 5. Already linked? -----------------------------------------------------
if (is_link($link)) {
    $current = readlink($link);
    echo "Already a symlink -> {$current}\n";
    echo (realpath($link) === realpath($target))
        ? "OK: it already points at the right place. You can DELETE this file now.\n"
        : "WARNING: it points somewhere else. Remove it and re-run if needed.\n";
    exit;
}

if (file_exists($link)) {
    http_response_code(500);
    exit("ERROR: '{$link}' already exists and is not a symlink (a real file/folder is in the way).\n"
        . "Rename or remove it via File Manager, then re-run.\n");
}

// ---- 6. Create the symlink --------------------------------------------------
if (@symlink($target, $link)) {
    echo "SUCCESS: linked public/storage -> storage/app/public\n";
    echo "Now DELETE this file (link-storage.php) from the server.\n";
    exit;
}

// ---- 7. Fallback if symlink() is disabled by the host -----------------------
$err = error_get_last()['message'] ?? 'unknown error';
http_response_code(500);
echo "Could not create a symlink: {$err}\n\n";
echo "Your host may have disabled the symlink() function. Options:\n";
echo " - Ask support to run: php artisan storage:link (or create the symlink for you).\n";
echo " - Or copy files instead of linking (uploads won't auto-appear; needs a copy step).\n";
