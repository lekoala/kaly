<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\ContentType;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentTypeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function knownExtensions(): array
    {
        return [
            'css' => ['app.css', ContentType::CSS],
            'js' => ['app.js', ContentType::JS],
            'mjs' => ['app.mjs', ContentType::JS],
            'html' => ['index.html', ContentType::HTML],
            'htm' => ['index.htm', ContentType::HTML],
            'json' => ['data.json', ContentType::JSON],
            'svg' => ['logo.svg', ContentType::SVG],
            'jpg' => ['photo.jpg', ContentType::JPEG],
            'jpeg' => ['photo.jpeg', ContentType::JPEG],
            'gif' => ['loop.gif', ContentType::GIF],
            'pdf' => ['invoice.pdf', ContentType::PDF],
            'woff' => ['font.woff', ContentType::WOFF],
            'woff2' => ['font.woff2', ContentType::WOFF],
            'xml' => ['feed.xml', ContentType::XML],
            'csv' => ['export.csv', ContentType::CSV],
        ];
    }

    #[DataProvider('knownExtensions')]
    public function testKnownWebExtensionIsDeterministic(string $filename, string $expected): void
    {
        $this->assertSame($expected, ContentType::forFile($filename));
        $this->assertSame($expected, ContentType::forFile(strtoupper($filename)));
    }

    public function testUnknownExtensionFallsBackToFileInfo(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-contenttype-' . uniqid();
        Fs::ensureDir($base);
        Fs::putFile($base . '/archive.bin', 'binary');
        Fs::putFile($base . '/extensionless', 'plain');

        try {
            $this->assertSame(Fs::contentType($base . '/archive.bin'), ContentType::forFile($base . '/archive.bin'));
            $this->assertNotSame('', ContentType::forFile($base . '/extensionless'));
        } finally {
            TempDir::remove($base);
        }
    }
}
