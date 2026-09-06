<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Reusable defence-in-depth rule that rejects any uploaded file whose extension
 * is on the platform denylist ({@see config('uploads.blocked_extensions')}).
 *
 * This is a backstop, not a substitute for a per-field allow-list: fields should
 * still declare a tight `image`/`mimes:` rule. `SafeUpload` guarantees that even
 * if an allow-list is loosened or a new upload field forgets one, dangerous
 * extensions (svg, php, html, executables, macro-enabled office docs, ...) can
 * never be accepted. Both the client extension and the guessed extension (from
 * the detected MIME type) are checked, so renaming `evil.php` to `logo.png`
 * still trips the guessed-extension check when the content is PHP.
 *
 * Non-file values pass through untouched (let `file`/`image`/`required` own
 * that concern); a null/absent optional file is a no-op.
 */
class SafeUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $blocked = array_map(
            static fn (string $ext): string => Str::lower($ext),
            (array) Config::get('uploads.blocked_extensions', []),
        );

        $candidates = array_filter([
            Str::lower((string) $value->getClientOriginalExtension()),
            Str::lower((string) $value->guessExtension()),
        ]);

        foreach ($candidates as $extension) {
            if (in_array($extension, $blocked, true)) {
                $fail('The :attribute has a file type that is not allowed.');

                return;
            }
        }
    }
}
