<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Util\Base64Url;
use PHPUnit\Framework\TestCase;

class Base64UrlTest extends TestCase
{
    public function testRoundTripArbitraryBytes(): void
    {
        $samples = ['', "\x00", "\xff", 'hello', random_bytes(32), random_bytes(33)];

        foreach ($samples as $raw) {
            $encoded = Base64Url::encode($raw);
            $this->assertSame($raw, Base64Url::decode($encoded));
        }
    }

    public function testEncodingIsUrlSafeWithoutPadding(): void
    {
        // 0xFB 0xFF encodes with + and / in standard Base64
        $this->assertSame('-_8', Base64Url::encode("\xfb\xff"));
        $this->assertSame("\xfb\xff", Base64Url::decode('-_8'));
        $this->assertDoesNotMatchRegularExpression('/[+=\\/]/', Base64Url::encode(random_bytes(32)));
    }

    public function testEmptyStringRoundTrips(): void
    {
        $this->assertSame('', Base64Url::encode(''));
        $this->assertSame('', Base64Url::decode(''));
    }

    public function testInvalidAlphabetIsRejected(): void
    {
        foreach (['!!!', 'a b', 'ab=', 'ab+', 'ab/', "ab\n"] as $bad) {
            $this->assertNull(Base64Url::decode($bad));
        }
    }

    public function testLengthModFourOneIsRejected(): void
    {
        $this->assertNull(Base64Url::decode('A'));
        $this->assertNull(Base64Url::decode('ABCDE'));
    }

    public function testNonCanonicalSpellingIsRejected(): void
    {
        $this->assertSame("\x00", Base64Url::decode('AA'));
        $this->assertNull(Base64Url::decode('AB'));
    }
}
