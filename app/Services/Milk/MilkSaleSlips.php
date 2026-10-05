<?php

declare(strict_types=1);

namespace App\Services\Milk;

use App\Models\MilkSale;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The optional Mandali collection slip.
 *
 * Deliberately not a document-management subsystem: one file per sale, stored
 * privately, replaceable, removable, and downloadable only through an authorised
 * controller action. Anything more is a different feature.
 *
 * ## Why the stored name is randomised
 *
 * The filename a browser sends is attacker-controlled. It can contain path
 * separators, traversal sequences, a double extension, or simply a name that collides
 * with another Mandali's slip from the same day. So the stored name is a random
 * string with an extension derived from the file's *own* type, and whatever the user
 * called it survives only as metadata for the download header — never as part of a
 * filesystem path.
 *
 * ## Why the disk is private
 *
 * The `local` disk roots at `storage/app/private`, outside the document root, so
 * there is no URL that reaches these files. A collection slip is a commercial record
 * between the farm and a dairy; it is not published because it happened to be
 * uploaded. Access goes through a route that checks the same permission as viewing
 * the sale.
 */
class MilkSaleSlips
{
    /** The private disk. Rooted outside the document root — see the class docblock. */
    public const DISK = 'local';

    public const DIRECTORY = 'milk-sale-slips';

    /** Extensions a collection slip plausibly has. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /** Matching media types, checked independently of the extension. */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** Kilobytes. A photograph of a paper slip, not a scanned archive. */
    public const MAX_KILOBYTES = 5120;

    /**
     * Stores an uploaded slip and returns the columns to persist.
     *
     * @return array{slip_path: string, slip_name: string}
     */
    public function store(UploadedFile $file): array
    {
        /*
         * `Str::random()` for the name and the file's own guessed extension, so
         * nothing the uploader chose reaches the filesystem. `storeAs` on a private
         * disk keeps it out of any public path.
         */
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $name = Str::random(40).($extension !== '' ? '.'.$extension : '');

        $path = $file->storeAs(self::DIRECTORY, $name, self::DISK);

        return [
            'slip_path' => $path,
            // Trimmed to the column and stripped of any directory component, because
            // this is shown to a human and never resolved as a path.
            'slip_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
        ];
    }

    /**
     * Replaces a sale's slip, deleting the file it had.
     *
     * The delete happens after the new file is stored, so a failure partway through
     * leaves the old slip in place rather than none at all.
     *
     * @return array{slip_path: string, slip_name: string}
     */
    public function replace(MilkSale $sale, UploadedFile $file): array
    {
        $stored = $this->store($file);

        $this->deleteFile($sale->slip_path);

        return $stored;
    }

    /**
     * Removes a sale's slip and returns the columns that clear it.
     *
     * @return array{slip_path: null, slip_name: null}
     */
    public function remove(MilkSale $sale): array
    {
        $this->deleteFile($sale->slip_path);

        return ['slip_path' => null, 'slip_name' => null];
    }

    /**
     * Deletes a stored file, tolerating one that is already gone.
     *
     * A missing file must not block a sale correction: the record is the thing that
     * matters, and an orphaned-or-absent file is a smaller problem than a delivery
     * nobody can edit.
     */
    public function deleteFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    public function exists(MilkSale $sale): bool
    {
        return $sale->hasSlip() && Storage::disk(self::DISK)->exists($sale->slip_path);
    }

    /** The validation rules a Form Request applies to the upload. */
    public static function rules(): array
    {
        return [
            'file',
            'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
            // Checked as well as the extension, so a .pdf that is really something
            // else is refused rather than stored.
            'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES),
            'max:'.self::MAX_KILOBYTES,
        ];
    }
}
