<?php

declare(strict_types=1);

namespace Kaly\Middleware\Builtin;

use InvalidArgumentException;
use Kaly\Http\FileResponseFactory;
use Kaly\Util\Fs;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves public static files from a directory.
 *
 * This is the public-file policy on top of FileResponseFactory: URI to path
 * resolution, directory jail and forbidden extensions. Private downloads
 * (invoices, attachments, ...) belong in application code after an access
 * check, using FileResponseFactory directly.
 */
class FileServer implements MiddlewareInterface
{
    public function __construct(
        private string $publicDir,
        private FileResponseFactory $files,
    ) {}

    /**
     * Extensions that must never be served as static files.
     *
     * This policy belongs to the public directory only: a legitimate private
     * download may well be a `.zip`, an `archive.xml` or any other extension.
     */
    protected const FORBIDDEN_EXTENSIONS = [
        'php',
        'phtml',
        'phar',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'pht',
        'inc',
        'cgi',
        'pl',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only serve plain static files
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        $filename = Fs::toDir($this->publicDir, $path);

        // Reject path traversal, symlink escapes and non regular files
        if (!Fs::isInside($this->publicDir, $filename) || !is_file($filename)) {
            return $handler->handle($request);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            return $handler->handle($request);
        }

        try {
            return $this->files->create($filename, method: $request->getMethod());
        } catch (InvalidArgumentException) {
            // The file vanished or became unreadable between the checks above
            return $handler->handle($request);
        }
    }
}
