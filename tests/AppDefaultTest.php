<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Router\TrailingSlash;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AppDefaultTest extends TestCase
{
    /**
     * @return array{trailingSlash: TrailingSlash, localePrefixes: bool}
     */
    private function routingOf(App $app): array
    {
        $reflection = new ReflectionClass($app);
        $trailing = $reflection->getProperty('trailingSlash');
        $trailing->setAccessible(true);
        $prefixes = $reflection->getProperty('localePrefixes');
        $prefixes->setAccessible(true);

        $trailingSlash = $trailing->getValue($app);
        if (!$trailingSlash instanceof TrailingSlash) {
            $this->fail('App::$trailingSlash is not a TrailingSlash');
        }
        $localePrefixes = $prefixes->getValue($app);
        if (!is_bool($localePrefixes)) {
            $this->fail('App::$localePrefixes is not a bool');
        }

        return [
            'trailingSlash' => $trailingSlash,
            'localePrefixes' => $localePrefixes,
        ];
    }

    public function testCreateIsNeutral(): void
    {
        $routing = $this->routingOf(App::create(__DIR__, false));

        $this->assertSame(TrailingSlash::Preserve, $routing['trailingSlash']);
        $this->assertFalse($routing['localePrefixes']);
    }

    public function testDefaultIsTheRecommendedProfile(): void
    {
        $routing = $this->routingOf(App::default(__DIR__, false));

        $this->assertSame(TrailingSlash::Remove, $routing['trailingSlash']);
        $this->assertTrue($routing['localePrefixes']);
    }

    public function testLocalesNeverDecideTheTopologyByThemselves(): void
    {
        $neutral = App::create(__DIR__, false)->locales(['fr', 'en']);
        $this->assertFalse($this->routingOf($neutral)['localePrefixes']);

        $recommended = App::default(__DIR__, false)->locales(['fr', 'en']);
        $this->assertTrue($this->routingOf($recommended)['localePrefixes']);
        $this->assertSame(['fr', 'en'], $recommended->getLocales());
    }
}
