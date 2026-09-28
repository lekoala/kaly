<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Finds the PSR-17 factories of an installed PSR-7 implementation.
 *
 * The core only depends on the PSR interfaces: apps just `composer require`
 * an implementation and Kaly binds it, no `bind()` boilerplate needed. An
 * explicit binding always wins over discovery.
 */
final class Psr17Discovery
{
    public const INTERFACES = [
        RequestFactoryInterface::class,
        ResponseFactoryInterface::class,
        ServerRequestFactoryInterface::class,
        StreamFactoryInterface::class,
        UploadedFileFactoryInterface::class,
        UriFactoryInterface::class,
    ];

    /**
     * Known implementations, by preference. A single class implementing
     * every factory, or one class per factory.
     *
     * @var list<string|array<class-string,string>>
     */
    private const CANDIDATES = [
        'Nyholm\Psr7\Factory\Psr17Factory',
        'GuzzleHttp\Psr7\HttpFactory',
        [
            RequestFactoryInterface::class => 'Laminas\Diactoros\RequestFactory',
            ResponseFactoryInterface::class => 'Laminas\Diactoros\ResponseFactory',
            ServerRequestFactoryInterface::class => 'Laminas\Diactoros\ServerRequestFactory',
            StreamFactoryInterface::class => 'Laminas\Diactoros\StreamFactory',
            UploadedFileFactoryInterface::class => 'Laminas\Diactoros\UploadedFileFactory',
            UriFactoryInterface::class => 'Laminas\Diactoros\UriFactory',
        ],
        [
            RequestFactoryInterface::class => 'HttpSoft\Message\RequestFactory',
            ResponseFactoryInterface::class => 'HttpSoft\Message\ResponseFactory',
            ServerRequestFactoryInterface::class => 'HttpSoft\Message\ServerRequestFactory',
            StreamFactoryInterface::class => 'HttpSoft\Message\StreamFactory',
            UploadedFileFactoryInterface::class => 'HttpSoft\Message\UploadedFileFactory',
            UriFactoryInterface::class => 'HttpSoft\Message\UriFactory',
        ],
    ];

    /**
     * The first installed implementation, as interface => concrete class.
     * Empty when no known implementation is installed.
     *
     * @return array<class-string,class-string>
     */
    public static function find(): array
    {
        foreach (self::CANDIDATES as $candidate) {
            $map = is_string($candidate) ? array_fill_keys(self::INTERFACES, $candidate) : $candidate;
            $found = [];
            foreach ($map as $interface => $class) {
                if (class_exists($class) && is_a($class, $interface, true)) {
                    $found[$interface] = $class;
                }
            }
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }
}
