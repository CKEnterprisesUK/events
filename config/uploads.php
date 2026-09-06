<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Blocked upload file extensions (denylist)
    |--------------------------------------------------------------------------
    |
    | A defence-in-depth denylist of file extensions that must never be accepted
    | by ANY upload surface, regardless of the per-field allow-list. These are
    | extensions that are executable, can carry active/scriptable content, or are
    | commonly abused to smuggle a payload past a naive MIME check.
    |
    | Enforce this list with the shared {@see \App\Rules\SafeUpload} rule. Per-
    | field rules should still specify their own tight `mimes:`/`image` allow-
    | list; this denylist is the belt-and-braces backstop so a new upload field
    | can never silently permit a dangerous type.
    |
    | Notes:
    |   - `svg` is blocked: SVGs are XML and can embed <script>, so an inline-
    |     served SVG is a stored-XSS vector. Raster image formats are unaffected.
    |   - `html`/`htm`/`xml`/`xhtml` are blocked for the same active-content reason.
    |   - Matching is case-insensitive and applied to the client extension.
    |
    */

    'blocked_extensions' => [
        // Scriptable / active markup
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'xht', 'shtml',

        // Server-side / interpreted code
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'pht', 'asp', 'aspx', 'jsp', 'jspx', 'cfm', 'cgi', 'pl', 'py', 'rb',

        // Client-side script
        'js', 'mjs', 'cjs', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'hta',

        // Executables / installers / scripts
        'exe', 'com', 'msi', 'bat', 'cmd', 'sh', 'bash', 'ps1', 'psm1',
        'jar', 'app', 'dmg', 'deb', 'rpm', 'bin', 'run', 'scr', 'dll', 'so',

        // Office macro-enabled documents
        'docm', 'xlsm', 'pptm', 'dotm', 'xltm', 'potm',

        // Misc risky
        'htaccess', 'htpasswd', 'ini', 'reg',
    ],

];
