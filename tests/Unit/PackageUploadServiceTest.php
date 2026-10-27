<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\PackageUploadException;
use Joomla\Component\Mcpserver\Administrator\Service\PackageUploadService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackageUploadServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mcp-upload-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        // Nested files first, so each directory is empty when it is removed.
        foreach ([...glob($this->tmp . '/*/*') ?: [], ...glob($this->tmp . '/*') ?: []] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->tmp);
    }

    public function testAZipIsStagedInTmpPath(): void
    {
        $upload = $this->phpUpload("PK\x03\x04rest of the archive");

        $stored = $this->service()->store(['error' => \UPLOAD_ERR_OK, 'tmp_name' => $upload]);

        $this->assertSame(realpath($this->tmp), dirname($stored['source_path']));
        $this->assertMatchesRegularExpression('/^mcp-upload-[0-9a-f]{32}\.zip$/', basename($stored['source_path']));
        $this->assertSame(23, $stored['size']);
        $this->assertFileDoesNotExist($upload, 'the PHP upload is moved, not copied');
    }

    public function testANonZipIsRefused(): void
    {
        $this->assertRefused(400, 'not a ZIP', ['error' => \UPLOAD_ERR_OK, 'tmp_name' => $this->phpUpload('<?php echo 1;')]);
        $this->assertSame([], glob($this->tmp . '/mcp-upload-*') ?: []);
    }

    public function testAMissingFieldIsABadRequest(): void
    {
        $this->assertRefused(400, 'multipart/form-data field "file"', null);
    }

    public function testABodyOverPostMaxSizeIsTooLarge(): void
    {
        // PHP empties $_FILES for such a body, so only Content-Length tells.
        $limit = PackageUploadService::iniBytes((string) ini_get('post_max_size'));
        if ($limit <= 0) {
            $this->markTestSkipped('post_max_size is unlimited');
        }

        $this->assertRefused(413, 'post_max_size', null, $limit + 1);
    }

    #[DataProvider('uploadErrors')]
    public function testPhpUploadErrors(int $error, int $status, string $message): void
    {
        $this->assertRefused($status, $message, ['error' => $error, 'tmp_name' => '']);
    }

    public static function uploadErrors(): array
    {
        return [
            'ini size' => [\UPLOAD_ERR_INI_SIZE, 413, 'upload_max_filesize'],
            'form size' => [\UPLOAD_ERR_FORM_SIZE, 413, 'upload_max_filesize'],
            'partial' => [\UPLOAD_ERR_PARTIAL, 400, 'interrupted'],
            'no file' => [\UPLOAD_ERR_NO_FILE, 400, 'field "file"'],
            'no tmp dir' => [\UPLOAD_ERR_NO_TMP_DIR, 500, 'PHP upload error 6'],
        ];
    }

    public function testAnUnusableTmpPathIsAServerError(): void
    {
        $service = new PackageUploadService($this->tmp . '/missing', 'rename');

        try {
            $service->store(['error' => \UPLOAD_ERR_OK, 'tmp_name' => $this->phpUpload("PK\x03\x04")]);
            $this->fail('expected a refusal');
        } catch (PackageUploadException $e) {
            $this->assertSame(500, $e->httpStatus);
            $this->assertStringContainsString('tmp_path', $e->getMessage());
        }
    }

    public function testConsumeDeletesOnlyStagedPackages(): void
    {
        $staged = $this->service()->store(['error' => \UPLOAD_ERR_OK, 'tmp_name' => $this->phpUpload("PK\x03\x04")])['source_path'];
        $ownersZip = $this->tmp . '/template.zip';
        touch($ownersZip);
        mkdir($this->tmp . '/sub');
        $nested = $this->tmp . '/sub/mcp-upload-' . str_repeat('a', 32) . '.zip';
        touch($nested);

        foreach ([$staged, $ownersZip, $nested] as $path) {
            $this->service()->consume($path);
        }

        $this->assertFileDoesNotExist($staged);
        $this->assertFileExists($ownersZip, 'a zip the site owner put in tmp_path is not ours to delete');
        $this->assertFileExists($nested, 'only tmp_path itself holds staged packages');
    }

    public function testExpiredPackagesArePurged(): void
    {
        $now = 1_800_000_000;
        $old = $this->tmp . '/mcp-upload-' . str_repeat('1', 32) . '.zip';
        $fresh = $this->tmp . '/mcp-upload-' . str_repeat('2', 32) . '.zip';
        $other = $this->tmp . '/mcp-upload-notours.zip';
        touch($old, $now - PackageUploadService::MAX_AGE - 1);
        touch($fresh, $now - 10);
        touch($other, $now - PackageUploadService::MAX_AGE - 1);

        $purged = (new PackageUploadService($this->tmp, 'rename', static fn (): int => $now))->purgeExpired();

        $this->assertSame(1, $purged);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($other);
    }

    #[DataProvider('iniSizes')]
    public function testIniBytes(string $value, int $bytes): void
    {
        $this->assertSame($bytes, PackageUploadService::iniBytes($value));
    }

    public static function iniSizes(): array
    {
        return [
            'plain' => ['1024', 1024],
            'kilo' => ['2K', 2048],
            'mega' => ['8M', 8 * 1024 ** 2],
            'giga' => ['1g', 1024 ** 3],
            'unlimited' => ['0', 0],
            'empty' => ['', 0],
        ];
    }

    private function service(): PackageUploadService
    {
        // rename() stands in for move_uploaded_file(), which accepts only real
        // HTTP uploads.
        return new PackageUploadService($this->tmp, 'rename');
    }

    private function phpUpload(string $bytes): string
    {
        $path = $this->tmp . '/php' . bin2hex(random_bytes(4));
        file_put_contents($path, $bytes);

        return $path;
    }

    private function assertRefused(int $status, string $message, mixed $file, int $contentLength = 0): void
    {
        try {
            $this->service()->store($file, $contentLength);
            $this->fail('expected a refusal');
        } catch (PackageUploadException $e) {
            $this->assertSame($status, $e->httpStatus);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
