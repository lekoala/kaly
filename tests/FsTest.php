<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\ErrorHandler;
use Kaly\Core\Paths;
use Kaly\Ex;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FsTest extends TestCase
{
    #[DataProvider('relativePaths')]
    public function testRelativePath(string $base, string $path, string $expected): void
    {
        $this->assertSame($expected, Fs::relativePath($base, $path));
    }

    /** @return array<string, array{string, string, string}> */
    public static function relativePaths(): array
    {
        return [
            'module source' => ['/app', '/app/modules/Foo/src', 'modules/Foo/src'],
            'base separator' => ['/app/', '/app/modules/Foo/src', 'modules/Foo/src'],
            'same directory' => ['/app/', '/app', ''],
            'directory boundary' => ['/app', '/application/src', '/application/src'],
            'outside' => ['/app', '/other/src', '/other/src'],
            'empty base' => ['', '/app/src', '/app/src'],
            'unix root' => ['/', '/image.png', 'image.png'],
            'drive root' => ['D:\\', 'D:\\image.png', 'image.png'],
            'mixed separators' => ['D:/app/', 'D:\\app\\modules\\Foo', 'modules\\Foo'],
            'unc root' => ['\\\\server\\share\\', '\\\\server\\share\\image.png', 'image.png'],
            'relative base' => ['app', 'app/src', 'src'],
        ];
    }

    public function testReadFallbacksWithKalyErrorHandler(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fs-' . uniqid();
        ErrorHandler::configureDefaults(false);
        try {
            $this->assertSame('', Fs::getFile($base . '/missing'));
            $this->assertSame('application/octet-stream', Fs::contentType($base . '/missing'));
            Fs::putFile($base . '/readable.txt', 'hello');
            $this->assertSame('hello', Fs::getFile($base . '/readable.txt'));
            $this->assertSame('text/plain', Fs::contentType($base . '/readable.txt'));
        } finally {
            ErrorHandler::restoreDefaults();
            TempDir::remove($base);
        }
    }

    #[DataProvider('directoryLinkLocations')]
    public function testRemoveDirDoesNotFollowDirectoryLinks(bool $nested): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fs-' . uniqid();
        $target = Fs::join($base, 'target');
        $tree = Fs::join($base, 'tree');
        $link = $nested ? Fs::join($tree, 'link') : $tree;
        try {
            Fs::putFile(Fs::join($target, 'keep.txt'), 'keep');
            Fs::ensureDir(dirname($link));
            if (DIRECTORY_SEPARATOR === '\\') {
                exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($target), $output, $status);
                if ($status !== 0) {
                    $this->markTestSkipped('Windows junctions are not available');
                }
            } elseif (!@symlink($target, $link)) {
                $this->markTestSkipped('Symlinks are not available');
            }
            if ($nested) {
                Fs::putFile(Fs::join($tree, 'child', 'remove.txt'), 'remove');
            }
            Fs::removeDir($tree . DIRECTORY_SEPARATOR);
            clearstatcache();
            $this->assertDirectoryDoesNotExist($tree);
            $this->assertSame('keep', Fs::getFile(Fs::join($target, 'keep.txt')));
        } finally {
            TempDir::remove($base);
        }
    }

    /** @return array<string, array{bool}> */
    public static function directoryLinkLocations(): array
    {
        return ['root link' => [false], 'nested link' => [true]];
    }

    public function testRemoveDirRemovesDanglingSymlink(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fs-' . uniqid();
        Fs::ensureDir($base);
        $link = Fs::join($base, 'link');
        try {
            if (!@symlink(Fs::join($base, 'missing'), $link)) {
                $this->markTestSkipped('Symlinks are not available');
            }
            Fs::removeDir($base);
            $this->assertDirectoryDoesNotExist($base);
        } finally {
            TempDir::remove($base);
        }
    }

    /** @param list<string> $segments */
    #[DataProvider('joinedPaths')]
    public function testJoin(array $segments, string $expected): void
    {
        $this->assertSame($expected, Fs::join(...$segments));
    }

    /** @return array<string, array{list<string>, string}> */
    public static function joinedPaths(): array
    {
        $sep = DIRECTORY_SEPARATOR;
        return [
            'no segments' => [[], ''],
            'empty segments' => [['', ''], ''],
            'zero preserved' => [['', '0', '', '0'], '0' . $sep . '0'],
            'relative boundaries' => [['resources///', 'media\\\\', 'file'], 'resources' . $sep . 'media' . $sep . 'file'],
            'unix absolute' => [['/var/www/', 'resources/', 'media'], '/var/www' . $sep . 'resources' . $sep . 'media'],
            'unix root' => [['/', ''], '/'],
            'unix root child' => [['', '/', 'file'], $sep . 'file'],
            'drive root' => [['C:\\', ''], 'C:\\'],
            'drive root child' => [['C:\\', 'file'], 'C:' . $sep . 'file'],
            'drive path' => [['C:\\www\\', 'media'], 'C:\\www' . $sep . 'media'],
            'drive slash root' => [['C:/'], 'C:/'],
            'drive relative' => [['C:', 'file'], 'C:file'],
            'unc root' => [['\\\\server\\share\\'], '\\\\server\\share'],
            'unc child' => [['\\\\server\\share\\', 'file'], '\\\\server\\share' . $sep . 'file'],
            'unc forward slashes' => [['//server/share/', 'file'], '//server/share' . $sep . 'file'],
            'interior separators' => [['a//b\\c/', 'd//e'], 'a//b\\c' . $sep . 'd//e'],
            'dot segments remain lexical' => [['missing', '.', '..', 'file'], 'missing' . $sep . '.' . $sep . '..' . $sep . 'file'],
        ];
    }

    #[DataProvider('rootedSegments')]
    public function testRejectsLaterRootedSegments(string $segment): void
    {
        $this->expectException(Ex::class);
        Fs::join('', 'base/', '', $segment);
    }

    /** @return array<string, array{string}> */
    public static function rootedSegments(): array
    {
        return [
            'unix' => ['/absolute'],
            'unix root' => ['/'],
            'windows rooted' => ['\\absolute'],
            'windows drive' => ['C:\\absolute'],
            'windows drive slash' => ['C:/absolute'],
            'drive relative' => ['C:relative'],
            'bare drive' => ['C:'],
            'unc' => ['\\\\server\\share'],
        ];
    }

    #[DataProvider('directories')]
    public function testDir(string $path, string $expected): void
    {
        $this->assertSame($expected, Fs::dir($path));
    }

    /** @return array<string, array{string, string}> */
    public static function directories(): array
    {
        return [
            'empty' => ['', ''],
            'zero' => ['0', '0'],
            'relative' => ['media/\\/', 'media'],
            'unix root' => ['/', '/'],
            'repeated root' => ['///', '/'],
            'windows rooted' => ['\\', '\\'],
            'drive root' => ['C:\\\\', 'C:\\'],
            'drive slash root' => ['C:///', 'C:/'],
            'drive relative' => ['C:', 'C:'],
            'unc root' => ['\\\\server\\share\\\\', '\\\\server\\share'],
            'unc slash root' => ['//server/share///', '//server/share'],
            'interior unchanged' => ['a//b\\c/', 'a//b\\c'],
        ];
    }

    public function testPathsPreserveTheirBaseRoot(): void
    {
        $paths = new Paths('/');
        $this->assertSame('/', $paths->base);
        $this->assertSame(DIRECTORY_SEPARATOR . 'resources', $paths->resources());
    }

    public function testEnsureDirCreatesRecursivelyAndAcceptsExistingDirectories(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fs-' . uniqid();
        try {
            $directory = Fs::join($base, 'nested', 'child');
            Fs::ensureDir($directory, 0o775);
            $this->assertDirectoryExists($directory);
            Fs::ensureDir($directory, 0o700);
            $this->assertDirectoryExists($directory);
        } finally {
            TempDir::remove($base);
        }
    }

    #[DataProvider('fileConflicts')]
    public function testEnsureDirRejectsFileConflicts(string $suffix): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fs-' . uniqid();
        try {
            $file = Fs::join($base, 'file');
            Fs::putFile($file, 'conflict');
            $this->expectException(Ex::class);
            $this->expectExceptionMessage("Cannot create directory '" . $file . $suffix . "'");
            Fs::ensureDir($file . $suffix);
        } finally {
            TempDir::remove($base);
        }
    }

    /** @return array<string, array{string}> */
    public static function fileConflicts(): array
    {
        return ['file at destination' => [''], 'file as parent' => [DIRECTORY_SEPARATOR . 'child']];
    }

    public function testEnsureDirPermissionsRespectUmaskAndLeaveExistingDirectoriesAlone(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows ignores mkdir permissions');
        }
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fs-' . uniqid();
        $previousMask = umask(0o027);
        try {
            Fs::ensureDir($base, 0o775);
            Fs::ensureDir($base, 0o700);
            clearstatcache(true, $base);
            $this->assertSame(0o750, fileperms($base) & 0o777);
        } finally {
            umask($previousMask);
            TempDir::remove($base);
        }
    }
}
