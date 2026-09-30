<?php

declare(strict_types=1);

namespace Kaly\Tests;

use JsonException;
use Kaly\Util\Json;
use PHPUnit\Framework\TestCase;

class JsonTest extends TestCase
{
    public function testEncodeRoundTrips(): void
    {
        $this->assertSame('{"a":1}', Json::encode(['a' => 1]));
    }

    public function testEncodeProducesRealJsonForScalars(): void
    {
        $this->assertSame('"foo"', Json::encode('foo'));
        $this->assertSame('null', Json::encode(null));
    }

    public function testDecodeAcceptsJsonNull(): void
    {
        $this->assertNull(Json::decode('null'));
    }

    public function testDecodeRejectsMalformedJson(): void
    {
        $this->expectException(JsonException::class);
        Json::decode('{bad');
    }

    public function testDecodeMapReturnsObject(): void
    {
        $this->assertSame(['reason' => 'consultation'], Json::decodeMap('{"reason": "consultation"}'));
    }

    public function testDecodeMapRejectsAJsonArray(): void
    {
        $this->expectException(JsonException::class);
        Json::decodeMap('[1, 2, 3]');
    }

    public function testDecodeMapRejectsNumericStringKeys(): void
    {
        // {"0": "x"} decodes to an int-keyed PHP array — not a map.
        $this->expectException(JsonException::class);
        Json::decodeMap('{"0": "x"}');
    }

    public function testDecodeMapRejectsScalarsAndMalformedJson(): void
    {
        foreach (['null', '"text"', '42', '{bad'] as $json) {
            $thrown = false;
            try {
                Json::decodeMap($json);
            } catch (JsonException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, 'Expected JsonException for ' . $json);
        }
    }

    public function testDecodeListReturnsArray(): void
    {
        $this->assertSame([1, 'two'], Json::decodeList('[1, "two"]'));
    }

    public function testDecodeListRejectsAJsonObject(): void
    {
        $this->expectException(JsonException::class);
        Json::decodeList('{"a": 1}');
    }

    public function testValidateAcceptsValidJson(): void
    {
        $this->assertTrue(Json::validate('{"a":1}'));
    }

    public function testValidateRejectsInvalidJson(): void
    {
        $this->assertFalse(Json::validate('{oops'));
        $this->assertFalse(Json::validate(''));
    }
}
