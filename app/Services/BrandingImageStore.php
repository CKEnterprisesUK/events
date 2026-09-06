<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Shared store for branding/hero images (logos and posters).
 *
 * Encapsulates the storage semantics previously inlined in
 * {@see \App\Http\Controllers\BrandingController}: an uploaded image is stored
 * on the `public` filesystem disk under a caller-supplied directory, the stored
 * relative path is returned for persistence, and any previously stored file at
 * the same slot on the same disk is removed so old files do not accumulate.
 *
 * Keeping the exact disk (`public`), path format (the relative path returned by
 * {@see UploadedFile::store()}), and delete-old-file behaviour means callers
 * behave identically to the previous inline implementation. (Requirement 5.1)
 */
class BrandingImageStore
{
    /**
     * The filesystem disk branding images live on.
     */
    private const DISK = 'public';

    /**
     * Store an uploaded image on the `public` disk under the given directory and
     * return its relative path for persistence. Any previously stored file
     * (when non-null, non-empty and different from the new path) is removed from
     * the same disk.
     */
    public function store(UploadedFile $file, string $directory, ?string $previousPath = null): string
    {
        $path = $file->store($directory, self::DISK);

        if ($previousPath !== null && $previousPath !== '' && $previousPath !== $path) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        return $path;
    }

    /**
     * Remove a stored image from the `public` disk when it deletes without a
     * replacement. A null or empty path is a no-op.
     */
    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
