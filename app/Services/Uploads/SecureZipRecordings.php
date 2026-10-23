<?php

namespace App\Services\Uploads;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

final class SecureZipRecordings
{
    /** @return array{entries: array, expanded_bytes: int, archive_entries: int} */
    public function prepare(string $archivePath, string $directory, int $remainingRecordings, int $remainingEntries, int $remainingBytes, int $deadline): array
    {
        if (!class_exists(ZipArchive::class))
        {
            throw new ArchiveUploadException('ZIP support is unavailable. Enable the PHP zip extension before uploading archives.');
        }

        $this->inspectDirectoryEnvelope($archivePath, $remainingEntries);
        $archive = new ZipArchive();

        if ($archive->open($archivePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true)
        {
            throw new ArchiveUploadException('This ZIP is corrupt, encrypted, or cannot be opened. Upload an ordinary unencrypted ZIP.');
        }

        try
        {
            $manifest = $this->inspect($archive, $remainingRecordings, $remainingEntries, $remainingBytes, $deadline);
            Storage::disk('local')->makeDirectory($directory);
            $actualBytes = 0;

            foreach ($manifest['entries'] as &$entry)
            {
                if ($entry['kind'] !== 'audio' || $entry['error'] !== null)
                {
                    continue;
                }

                $entry['path'] = $directory.'/'.Str::uuid().'.'.pathinfo($entry['name'], PATHINFO_EXTENSION);
                $this->copyEntry($archive, $entry, $actualBytes, $remainingBytes, $deadline);
            }

            unset($entry);

            return $manifest;
        }
        catch (Throwable $exception)
        {
            Storage::disk('local')->deleteDirectory($directory);
            throw $exception;
        }
        finally
        {
            $archive->close();
        }
    }

    private function inspectDirectoryEnvelope(string $path, int $remainingEntries): void
    {
        $input = @fopen($path, 'rb');

        if ($input === false)
        {
            throw new ArchiveUploadException('The staged ZIP cannot be read. Upload it again in a new batch.');
        }

        try
        {
            $size = fstat($input)['size'];
            $tailSize = min($size, 65557);
            fseek($input, $size - $tailSize);
            $tail = $this->readBytes($input, $tailSize);
            $position = strrpos($tail, "PK\x05\x06");

            if ($position === false || strlen($tail) - $position < 22)
            {
                throw new ArchiveUploadException('This ZIP has no valid directory. Recreate it as an ordinary ZIP archive.');
            }

            $end = unpack('vdisk/vdirectory_disk/ventries_disk/ventries/Vdirectory_bytes/Vdirectory_offset/vcomment_bytes', substr($tail, $position + 4, 18));
            $directoryEnd = $size - $tailSize + $position;

            if ($position + 22 + $end['comment_bytes'] !== $tailSize || $end['disk'] !== 0 || $end['directory_disk'] !== 0 || $end['entries_disk'] !== $end['entries'] || $end['entries'] === 65535 || $end['directory_bytes'] === 4294967295 || $end['directory_offset'] === 4294967295 || $directoryEnd !== $end['directory_offset'] + $end['directory_bytes'])
            {
                throw new ArchiveUploadException('Multi-volume, ZIP64, or inconsistent ZIP directories are not supported. Recreate an ordinary ZIP.');
            }

            if ($end['entries'] > $remainingEntries)
            {
                throw new ArchiveUploadException('This archive would exceed the 200-entry batch limit. Split it into smaller ZIPs.');
            }

            fseek($input, $end['directory_offset']);

            for ($index = 0; $index < $end['entries']; $index++)
            {
                if (ftell($input) + 46 > $directoryEnd)
                {
                    throw new ArchiveUploadException('This ZIP has an inconsistent directory. Recreate the archive before uploading it.');
                }

                $header = $this->readBytes($input, 46);
                $entry = unpack('vname_bytes/vextra_bytes/vcomment_bytes/vdisk', substr($header, 28, 8));
                $sizes = unpack('Vcompressed/Vexpanded', substr($header, 20, 8));
                $offset = unpack('Voffset', substr($header, 42, 4))['offset'];
                $next = ftell($input) + $entry['name_bytes'] + $entry['extra_bytes'] + $entry['comment_bytes'];

                if (substr($header, 0, 4) !== "PK\x01\x02" || $entry['disk'] !== 0 || $sizes['compressed'] === 4294967295 || $sizes['expanded'] === 4294967295 || $offset === 4294967295 || $next > $directoryEnd)
                {
                    throw new ArchiveUploadException('This ZIP uses an unsupported or inconsistent directory entry. Recreate an ordinary ZIP.');
                }

                fseek($input, $next);
            }

            if (ftell($input) !== $directoryEnd)
            {
                throw new ArchiveUploadException('The ZIP entry count does not match its directory. Recreate the archive before uploading it.');
            }
        }
        finally
        {
            fclose($input);
        }
    }

    /** @param resource $input */
    private function readBytes($input, int $length): string
    {
        if ($length === 0)
        {
            return '';
        }

        $bytes = fread($input, $length);

        if ($bytes === false || strlen($bytes) !== $length)
        {
            throw new ArchiveUploadException('This ZIP is truncated or unreadable. Recreate the archive before uploading it.');
        }

        return $bytes;
    }

    private function inspect(ZipArchive $archive, int $remainingRecordings, int $remainingEntries, int $remainingBytes, int $deadline): array
    {
        if ($archive->numFiles > $remainingEntries)
        {
            throw new ArchiveUploadException('This archive would exceed the 200-entry batch limit. Split it into smaller ZIPs.');
        }

        $entries = [];
        $names = [];
        $expandedBytes = 0;
        $recordings = 0;

        for ($index = 0; $index < $archive->numFiles; $index++)
        {
            $this->checkDeadline($deadline);
            $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);

            if (!$stat || !is_string($stat['name']) || !is_int($stat['size']) || $stat['size'] < 0 || !is_int($stat['crc']))
            {
                throw new ArchiveUploadException('An archive entry has invalid metadata. Recreate the ZIP before uploading it.');
            }

            $name = $this->safeEntryName($stat['name']);
            $nameKey = mb_strtolower(rtrim($name, '/'));

            if (isset($names[$nameKey]))
            {
                throw new ArchiveUploadException('The ZIP contains duplicate entry paths. Give each entry a unique path.');
            }

            $names[$nameKey] = true;
            $operations = 0;
            $attributes = 0;

            if (!$archive->getExternalAttributesIndex($index, $operations, $attributes, ZipArchive::FL_UNCHANGED))
            {
                throw new ArchiveUploadException('The archive entry type cannot be verified. Recreate the ZIP before uploading it.');
            }

            $type = ($attributes >> 16) & 0170000;

            if (!in_array($type, [0, 0100000, 0040000], true))
            {
                throw new ArchiveUploadException('ZIPs containing links or special files are not supported. Include ordinary audio files only.');
            }

            if (!isset($stat['encryption_method']) || $stat['encryption_method'] !== ZipArchive::EM_NONE)
            {
                throw new ArchiveUploadException('Encrypted ZIP entries are not supported. Upload an unencrypted archive.');
            }

            if (!in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true))
            {
                throw new ArchiveUploadException('This ZIP uses an unsupported compression method. Recreate it using standard ZIP compression.');
            }

            $expandedBytes += $stat['size'];

            if ($expandedBytes > $remainingBytes)
            {
                throw new ArchiveUploadException('This archive would exceed the 500 MiB expanded-data batch limit.');
            }

            if (str_ends_with($name, '/') || $type === 0040000)
            {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($extension === 'zip')
            {
                throw new ArchiveUploadException('The ZIP contains a nested archive. Remove nested ZIP files before uploading this archive.');
            }

            $isAudio = in_array($extension, config('qa.upload.extensions'), true);
            $error = $isAudio ? null : 'Not a supported audio recording.';

            if ($isAudio)
            {
                $recordings++;

                if ($recordings > $remainingRecordings)
                {
                    throw new ArchiveUploadException('This archive would exceed the 50-recording batch limit. Split it into smaller batches.');
                }

                if ($stat['size'] > config('qa.upload.max_kilobytes') * 1024)
                {
                    $error = 'This recording exceeds the 25 MiB audio limit and was not imported.';
                }
            }

            $entries[] = [
                'index' => $index, 'name' => $name, 'size' => $stat['size'], 'crc' => sprintf('%08x', $stat['crc']),
                'kind' => $isAudio ? 'audio' : 'ignored', 'path' => null,
                'error' => $error, 'status' => $isAudio ? ($error === null ? 'pending' : 'rejected') : 'skipped',
            ];
        }

        if ($recordings === 0)
        {
            throw new ArchiveUploadException('This ZIP contains no MP3, WAV, M4A, or OGG recordings. Nested ZIPs are not expanded.');
        }

        return ['entries' => $entries, 'expanded_bytes' => $expandedBytes, 'archive_entries' => $archive->numFiles];
    }

    private function safeEntryName(string $name): string
    {
        $name = str_replace('\\', '/', $name);

        if ($name === '' || strlen($name) > 3000 || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 1000 || preg_match('/[\x00-\x1f\x7f:]/', $name) || str_starts_with($name, '/'))
        {
            throw new ArchiveUploadException('The ZIP contains an absolute, unreadable, or unsafe entry path. Recreate it with ordinary relative paths.');
        }

        $parts = explode('/', $name);

        if (in_array('..', $parts, true))
        {
            throw new ArchiveUploadException('The ZIP contains a parent-directory path. Unsafe archives are rejected without importing any entries.');
        }

        $parts = array_values(array_filter($parts, fn ($part) => $part !== '' && $part !== '.'));
        $normalized = implode('/', $parts);

        if ($normalized === '')
        {
            throw new ArchiveUploadException('The ZIP contains an empty entry path.');
        }

        return $normalized.(str_ends_with($name, '/') ? '/' : '');
    }

    private function copyEntry(ZipArchive $archive, array $entry, int &$actualBytes, int $remainingBytes, int $deadline): void
    {
        $input = $archive->getStreamIndex($entry['index'], ZipArchive::FL_UNCHANGED);

        if ($input === false)
        {
            throw new ArchiveUploadException('A ZIP entry cannot be read. No recordings from this archive were imported.');
        }

        $output = @fopen(Storage::disk('local')->path($entry['path']), 'xb');

        if ($output === false)
        {
            fclose($input);
            throw new ArchiveUploadException('A ZIP entry could not be staged. Check available disk space and upload the archive again.');
        }

        $bytes = 0;
        $checksum = hash_init('crc32b');

        try
        {
            while (!feof($input))
            {
                $this->checkDeadline($deadline);
                $chunk = @fread($input, 65536);

                if ($chunk === false || ($chunk === '' && !feof($input)))
                {
                    throw new ArchiveUploadException('An archive entry is truncated or corrupt. No recordings from this ZIP were imported.');
                }

                $length = strlen($chunk);
                $bytes += $length;
                $actualBytes += $length;

                if ($bytes > $entry['size'] || $bytes > config('qa.upload.max_kilobytes') * 1024 || $actualBytes > $remainingBytes)
                {
                    throw new ArchiveUploadException('The ZIP expands beyond its declared size or the permitted recording limits.');
                }

                hash_update($checksum, $chunk);
                $offset = 0;

                while ($offset < $length)
                {
                    $written = @fwrite($output, substr($chunk, $offset));

                    if ($written === false || $written === 0)
                    {
                        throw new ArchiveUploadException('An archive entry could not be saved. Check available disk space.');
                    }

                    $offset += $written;
                }
            }

            if ($bytes !== $entry['size'] || hash_final($checksum) !== $entry['crc'])
            {
                throw new ArchiveUploadException('An archive entry failed its size or checksum verification. No recordings from this ZIP were imported.');
            }
        }
        finally
        {
            fclose($input);
            fclose($output);
        }
    }

    private function checkDeadline(int $deadline): void
    {
        if (time() >= $deadline)
        {
            throw new PreparationDeadlineExceeded('Upload preparation reached its time limit. Retry to resume without duplicating accepted recordings.');
        }
    }
}
