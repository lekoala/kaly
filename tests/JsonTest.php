<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Util\Json;
use PHPUnit\Framework\TestCase;

class JsonTest extends TestCase
{
    public function testValidateAcceptsValidJson(): void
    {
        $this->assertTrue(Json::validate('{"a":1}'));
    }

    public function testValidateRejectsInvalidJson(): void
    {
        $this->assertFalse(Json::validate('{oops'));
        $this->assertFalse(Json::validate(null));
        $this->assertFalse(Json::validate(''));
    }

    public function testValidateRejectsInvalidUtf8UnlessIgnored(): void
    {
        $invalidUtf8 = '"' . chr(255) . '"';
        $this->assertFalse(Json::validate($invalidUtf8));
        $this->assertTrue(Json::validate($invalidUtf8, JSON_INVALID_UTF8_IGNORE));
    }

    public function testValidatePassesFlagsAndDepthInOrder(): void
    {
        // Depth 2 is too shallow for a two-level object
        $this->assertFalse(Json::validate('{"a":{"b":1}}', 0, 2));
        $this->assertTrue(Json::validate('{"a":{"b":1}}'));
    }
}
