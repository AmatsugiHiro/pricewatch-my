<?php

namespace App\Services\Ingestion;

use Generator;
use RuntimeException;

/**
 * Reads a CSV file one row at a time.
 *
 * The obvious implementations of this — file(), str_getcsv(explode(...)), or
 * Storage::get() — all materialise the whole file first. On a 48 MB PriceCatcher
 * month that means well over 500 MB of PHP arrays, because each row becomes a
 * hash table with its own header keys. Yielding one row at a time from an open
 * handle keeps memory flat regardless of file size, which is what
 * IngestionRun::peak_memory_bytes is there to prove.
 */
final class CsvStream
{
    /**
     * @return Generator<int, array<string, string>>
     */
    public static function rows(string $path): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV for reading: {$path}");
        }

        try {
            // PHP 8.4 deprecates fgetcsv()'s implicit escape character. Passing an
            // empty string opts into strict RFC 4180 parsing, so a backslash in an
            // address field is treated as data rather than an escape.
            $header = fgetcsv($handle, 0, ',', '"', '');

            if ($header === false) {
                return;
            }

            $header = array_map(
                static fn (?string $column): string => trim((string) $column),
                $header
            );

            // Strip a UTF-8 BOM from the first column name if the publisher added one.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

            $columnCount = count($header);

            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                // fgetcsv returns [null] for a blank line.
                if ($row === [null]) {
                    continue;
                }

                // A row with the wrong arity is malformed; the importer counts it as
                // quarantined rather than guessing at the intended alignment.
                if (count($row) !== $columnCount) {
                    continue;
                }

                yield array_combine($header, array_map(
                    static fn (?string $value): string => (string) $value,
                    $row
                ));
            }
        } finally {
            fclose($handle);
        }
    }
}
