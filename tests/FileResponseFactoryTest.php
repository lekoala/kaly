<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Http\FileResponseFactory;
use Kaly\Util\Fs;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

class FileResponseFactoryTest extends TestCase
{
    private string $base;
    private FileResponseFactory $files;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fileresponse-' . uniqid();
        Fs::mkDir($this->base . '/storage');
        Fs::putFile($this->base . '/storage/invoice.pdf', 'AUDIT-INVOICE-BYTES');
        Fs::putFile($this->base . '/storage/source.php', '<?php /* AUDIT-PRIVATE-SOURCE */');

        $psr17 = new Psr17Factory();
        $this->files = new FileResponseFactory($psr17, $psr17);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->base);
    }

    /**
     * Delete a temp directory recursively (test-only helper).
     */
    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function testInlineByDefaultWithNoDisposition(): void
    {
        $response = $this->files->create($this->base . '/storage/invoice.pdf');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('AUDIT-INVOICE-BYTES', (string) $response->getBody());
        $this->assertSame((string) filesize($this->base . '/storage/invoice.pdf'), $response->getHeaderLine('Content-Length'));
        $this->assertNotSame('', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', $response->getHeaderLine('Content-Disposition'));
    }

    public function testAttachmentDerivesFilenameFromPath(): void
    {
        $response = $this->files->create($this->base . '/storage/invoice.pdf', attachment: true);

        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString('filename="invoice.pdf"', $disposition);
    }

    public function testCustomDownloadNameIsEncoded(): void
    {
        $response = $this->files->create($this->base . '/storage/invoice.pdf', downloadName: 'facturé 2026.pdf', attachment: true);

        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        // Exact UTF-8 name travels in filename*, the quoted fallback stays ASCII
        $this->assertStringContainsString("filename*=UTF-8''factur%C3%A9%202026.pdf", $disposition);
        $this->assertStringNotContainsString('é', explode(';', $disposition)[1]);
    }

    public function testFilenameCannotInjectHeaders(): void
    {
        $response = $this->files->create($this->base . '/storage/invoice.pdf', downloadName: "evil\"\r\nX-Injected: yes", attachment: true);

        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
    }

    public function testHeadReturnsHeadersOnly(): void
    {
        $response = $this->files->create($this->base . '/storage/invoice.pdf', method: 'HEAD');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame((string) filesize($this->base . '/storage/invoice.pdf'), $response->getHeaderLine('Content-Length'));
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->files->create($this->base . '/storage/missing.pdf', attachment: true);
    }

    public function testDirectoryThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->files->create($this->base . '/storage', attachment: true);
    }

    public function testPrivatePhpFileIsServableByThePrimitive(): void
    {
        // The forbidden-extensions policy belongs to the public FileServer:
        // an authorized private download may serve any extension.
        $response = $this->files->create($this->base . '/storage/source.php', attachment: true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('AUDIT-PRIVATE-SOURCE', (string) $response->getBody());
    }
}
