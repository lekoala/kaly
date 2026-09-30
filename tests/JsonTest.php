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

    public function testDecodeMapAcceptsAnEmptyObject(): void
    {
        $this->assertSame([], Json::decodeMap('{}'));
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

    public function testDecodeListRejectsObjectsThatLookLikeLists(): void
    {
        // `{"0": "x"}` and `{}` decode to shapes `array_is_list()` accepts,
        // only the raw outer shape tells them apart from a real array
        foreach (['{"0": "x"}', '{}'] as $json) {
            $thrown = false;
            try {
                Json::decodeList($json);
            } catch (JsonException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, 'Expected JsonException for ' . $json);
        }
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

    public function testDecodeRelaxedAcceptsInformalInput(): void
    {
        $this->assertSame(['a' => 1], Json::decodeRelaxed('{a: 1}'));
        $this->assertSame('x', Json::decodeRelaxed("'x'"));
    }

    public function testDecodeRelaxedLeavesValidJsonUntouched(): void
    {
        // `a:` inside a string is data, not a key: the strict path never rewrites
        $this->assertSame(['msg' => 'a: 1'], Json::decodeRelaxed('{"msg": "a: 1"}'));
    }

    public function testDecodeMapRelaxedAcceptsBareKeysAndSingleQuotes(): void
    {
        $this->assertSame(
            ['a' => 1, 'b' => 'x', 'c' => true, 'd' => null, 'e' => 1.5],
            Json::decodeMapRelaxed("a: 1, b: 'x', c: true, d: null, e: 1.5"),
        );
    }

    public function testDecodeMapRelaxedAcceptsBracedAndNestedInput(): void
    {
        $this->assertSame(['a' => ['b' => 2]], Json::decodeMapRelaxed('{a: {b: 2}}'));
        $this->assertSame(['a' => [['b' => 2]]], Json::decodeMapRelaxed('{a: [{b: 2}]}'));
    }

    public function testDecodeMapRelaxedAcceptsDashedKeysAndEscapedQuotes(): void
    {
        $this->assertSame(['data-key' => "it's"], Json::decodeMapRelaxed("data-key: 'it\\'s'"));
    }

    public function testDecodeMapRelaxedTreatsEmptyInputAsAnEmptyConfig(): void
    {
        $this->assertSame([], Json::decodeMapRelaxed(''));
        $this->assertSame([], Json::decodeMapRelaxed('  '));
    }

    public function testDecodeMapRelaxedRejectsBareValuesAndTrailingCommas(): void
    {
        // The relax step is not a grammar: `foo` stays bare and `,}` stays
        // malformed, the final decode rejects both
        foreach (['a: foo', 'a: 1,'] as $text) {
            $thrown = false;
            try {
                Json::decodeMapRelaxed($text);
            } catch (JsonException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, 'Expected JsonException for ' . $text);
        }
    }

    public function testDecodeMapRelaxedRejectsAList(): void
    {
        $this->expectException(JsonException::class);
        Json::decodeMapRelaxed('[1, 2]');
    }

    public function testDecodeListRelaxedAcceptsRelaxedMembers(): void
    {
        $this->assertSame([['a' => 1], 'x'], Json::decodeListRelaxed("[{a: 1}, 'x']"));
    }

    public function testDecodeListRelaxedRejectsAMap(): void
    {
        $this->expectException(JsonException::class);
        Json::decodeListRelaxed('{a: 1}');
    }
}
