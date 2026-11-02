<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Stages extension packages uploaded to task=rpc.upload in tmp_path, for
 * install_extension's source_path.
 *
 * MCP tool arguments are JSON the model writes out, so a package too large to
 * inline as base64 `content` had no way from the client's disk to the server
 * short of a public URL. A staged package is single-use: install_extension
 * deletes it once read (see consume()), and anything never installed is purged
 * after MAX_AGE seconds by the next upload.
 */
final class PackageUploadService
{
    public const MAX_AGE = 3600;

    private const FILE_PATTERN = '/^mcp-upload-[0-9a-f]{32}\.zip$/';

    /** @var callable(string, string): bool */
    private $mover;

    /** @var callable(): int */
    private $clock;

    /**
     * @param  null|callable(string, string): bool  $mover  Moves the PHP upload
     *                                                     into place; defaults to
     *                                                     move_uploaded_file().
     * @param  null|callable(): int                 $clock
     */
    public function __construct(
        private readonly string $tmpPath,
        ?callable $mover = null,
        ?callable $clock = null
    ) {
        $this->mover = $mover ?? static fn (string $from, string $to): bool => move_uploaded_file($from, $to);
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param  mixed  $file           One decoded $_FILES entry, or null when the
     *                                request carried no "file" field.
     * @param  int    $contentLength  The request's Content-Length: PHP drops every
     *                                field of a body over post_max_size, which is
     *                                otherwise indistinguishable from no upload.
     *
     * @return array{source_path: string, size: int}
     *
     * @throws PackageUploadException
     */
    public function store(mixed $file, int $contentLength = 0): array
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'])) {
            $postMax = self::iniBytes((string) ini_get('post_max_size'));
            if ($postMax > 0 && $contentLength > $postMax) {
                throw new PackageUploadException(
                    'Package exceeds the server\'s post_max_size (' . ini_get('post_max_size') . ')',
                    413
                );
            }

            throw new PackageUploadException('Send the package as the multipart/form-data field "file"', 400);
        }

        $this->assertUploaded((int) $file['error']);

        $tmpName = (string) $file['tmp_name'];
        $handle = @fopen($tmpName, 'rb');
        $signature = $handle !== false ? (string) fread($handle, 4) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        // Not a full consistency check: install_extension runs that. This only
        // turns away an obvious non-zip before it is kept around.
        if ($signature !== "PK\x03\x04" && $signature !== "PK\x05\x06") {
            throw new PackageUploadException('Package is not a ZIP archive', 400);
        }

        $dir = $this->stagingDir();
        $this->purgeExpired();

        $target = $dir . DIRECTORY_SEPARATOR . 'mcp-upload-' . bin2hex(random_bytes(16)) . '.zip';
        if (!($this->mover)($tmpName, $target)) {
            throw new PackageUploadException('Failed to store the package in tmp_path', 500);
        }

        return ['source_path' => $target, 'size' => (int) filesize($target)];
    }

    /**
     * Delete a staged package once it has been read. Any other path is left
     * alone: source_path may also name a zip the site owner put there.
     */
    public function consume(string $realPath): void
    {
        if ($this->isStaged($realPath)) {
            @unlink($realPath);
        }
    }

    public function isStaged(string $realPath): bool
    {
        $dir = realpath($this->tmpPath);

        return $dir !== false
            && dirname($realPath) === $dir
            && preg_match(self::FILE_PATTERN, basename($realPath)) === 1;
    }

    /**
     * @return int  the number of staged packages deleted
     */
    public function purgeExpired(): int
    {
        $dir = realpath($this->tmpPath);
        if ($dir === false) {
            return 0;
        }

        $cutoff = ($this->clock)() - self::MAX_AGE;
        $purged = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'mcp-upload-*.zip') ?: [] as $path) {
            if ($this->isStaged($path) && (int) @filemtime($path) < $cutoff && @unlink($path)) {
                $purged++;
            }
        }

        return $purged;
    }

    private function assertUploaded(int $error): void
    {
        match ($error) {
            \UPLOAD_ERR_OK => null,
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => throw new PackageUploadException(
                'Package exceeds the server\'s upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
                413
            ),
            \UPLOAD_ERR_PARTIAL => throw new PackageUploadException('The upload was interrupted; send it again', 400),
            \UPLOAD_ERR_NO_FILE => throw new PackageUploadException('Send the package as the multipart/form-data field "file"', 400),
            default => throw new PackageUploadException('The server could not receive the upload (PHP upload error ' . $error . ')', 500),
        };
    }

    private function stagingDir(): string
    {
        $dir = $this->tmpPath !== '' ? realpath($this->tmpPath) : false;
        if ($dir === false || !is_dir($dir) || !is_writable($dir)) {
            throw new PackageUploadException('Joomla tmp_path is not writable', 500);
        }

        return $dir;
    }

    /**
     * php.ini shorthand ("8M", "1G") to bytes; 0 means unlimited.
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $bytes = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}
