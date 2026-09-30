<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Util\Arr;
use Kaly\Util\Str;
use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase
{
    public function testCamelize(): void
    {
        $arr = [
            'my_string' => 'My_String',
            'my-string' => 'MyString',
            'mystring' => 'Mystring',
            'Mystring' => 'Mystring',
            'MYSTRING' => 'Mystring',
            'MySTRING' => 'Mystring',
            // utf 8 support
            'MySTRINGÜ' => 'Mystringü',
            'üstring' => 'Üstring',
        ];
        foreach ($arr as $str => $expected) {
            $this->assertEquals($expected, Str::camelize($str));
        }
    }

    public function testDecamelize(): void
    {
        $arr = [
            'My_String' => 'my_string',
            'myString' => 'my-string',
            'mySTRING' => 'my-string',
            'my_STR_ing' => 'my_str_ing',
            'mystring' => 'mystring',
            'Mystring' => 'mystring',
            'my-string' => 'my-string',
            // utf 8 support
            'my-stringÜ' => 'my-stringü',
            'ümy-string' => 'ümy-string',
        ];
        foreach ($arr as $str => $expected) {
            $this->assertEquals($expected, Str::decamelize($str));
        }
    }

    public function testStrtotitle(): void
    {
        $str = "\"or else it doesn't, you know. the name of the song is called 'haddocks' eyes.'\"";
        $expected = "\"Or Else It Doesn't, You Know. The Name Of The Song Is Called 'Haddocks' Eyes.'\"";
        $this->assertEquals($expected, Str::ucWords($str));
    }

    public function testCaseHelpersAreMultibyteSafe(): void
    {
        $this->assertSame('Éclair', Str::ucFirst('éclair'));
        $this->assertSame('ÉCLAIR', Str::upper('éclair'));
        $this->assertSame('éclair', Str::lower('Éclair'));
        $this->assertSame('', Str::ucFirst(null));
        // PHP's own helpers are byte-oriented and would cut the accent in half
        $this->assertSame('éclair', Str::lower('ÉCLAIR'));
    }

    public function testSlug(): void
    {
        $this->assertSame('my-page', Str::slug('My Page'));
        $this->assertSame('a-b', Str::slug('a  --  b'));
        $this->assertSame('', Str::slug(''));
        $this->assertSame('', Str::slug(null));
        // No slug carries a leading or trailing separator, whichever branch runs
        // (ext-intl is only suggested, the fallback already trimmed)
        $this->assertSame('my-page', Str::slug('  My Page  '));
        // An underscore is dropped rather than turned into a separator
        $this->assertSame('mypage', Str::slug('my_page'));
    }

    public function testEncodingHelpers(): void
    {
        $this->assertTrue(Str::isUtf8('déjà vu'));
        $this->assertFalse(Str::isUtf8("\xC3\x28"));
        $this->assertTrue(Str::isUtf8(''));
        $this->assertTrue(Str::isUtf8(null));

        // Valid utf-8 is returned untouched, never double encoded. The guard
        // runs before the conversion, so $fromEncoding is not consulted here.
        $this->assertSame('déjà', Str::toUtf8('déjà'));
        $this->assertSame('', Str::toUtf8(null));

        $latin = Str::convertEncoding('déjà', 'ISO-8859-1', 'UTF-8');
        $this->assertNotSame('déjà', $latin);
        $this->assertFalse(Str::isUtf8($latin));
        // toUtf8() reads as UTF-8, fromUtf8() writes as UTF-8
        $this->assertSame('déjà', Str::toUtf8($latin, 'ISO-8859-1'));
        $this->assertSame($latin, Str::fromUtf8('déjà', 'ISO-8859-1'));
    }

    public function testTruncate(): void
    {
        $this->assertSame('abc...', Str::truncate('abcdef', 3));
        $this->assertSame('abc', Str::truncate('abc', 10));
        $this->assertSame('', Str::truncate(null));
        $this->assertSame('ab!', Str::truncate('abcdef', 2, '!'));
    }

    /**
     * stringify() names a value for a debug dump, it does not format it.
     */
    public function testStringify(): void
    {
        $this->assertSame('text', Str::stringify('text'));
        $this->assertSame('int', Str::stringify(42));
        $this->assertSame('float', Str::stringify(3.5));
        $this->assertSame('(bool) true', Str::stringify(true));
        $this->assertSame('(bool) false', Str::stringify(false));
        $this->assertSame('null', Str::stringify(null));
        $this->assertSame('{"a":1}', Str::stringify(['a' => 1]));
        $this->assertSame('My\String', Str::stringify(new class {
            public function __toString(): string
            {
                return 'My\String';
            }
        }));
        $this->assertSame(self::class, Str::stringify($this));
    }

    public function testArrMapAssoc(): void
    {
        // keys are preserved, as the docblock example assumes
        $this->assertSame(['a' => 'a=1', 'b' => 'b=2'], Arr::mapAssoc(static fn(string $key, int $value): string => $key . '=' . $value, [
            'a' => 1,
            'b' => 2,
        ]));
    }

    public function testArrayMergeDistinct(): void
    {
        $arr1 = [
            'one',
        ];
        $arr2 = [
            'two',
        ];

        $res = Arr::mergeDistinct($arr1, $arr2);
        $this->assertEquals(['one', 'two'], $res);

        $arr1 = [
            'key' => 'wrong',
        ];
        $arr2 = [
            'key' => 'right',
        ];

        $res = Arr::mergeDistinct($arr1, $arr2);
        $this->assertEquals(['key' => 'right'], $res);

        $arr1 = [
            'key' => ['one'],
        ];
        $arr2 = [
            'key' => ['two'],
        ];

        $res = Arr::mergeDistinct($arr1, $arr2);
        $this->assertEquals(['key' => ['one', 'two']], $res);
    }
}
