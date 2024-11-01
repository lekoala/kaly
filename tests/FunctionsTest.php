<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Util\Arr;
use Kaly\Util\Str;
use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    public function testCamelize(): void
    {
        $arr = [
            'my_string' => "My_String",
            'my-string' => "MyString",
            'mystring' => "Mystring",
            'Mystring' => "Mystring",
            'MYSTRING' => "Mystring",
            'MySTRING' => "Mystring",
            // utf 8 support
            'MySTRINGÜ' => "Mystringü",
            'üstring' => "Üstring",
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
            $this->assertEquals($expected, Str::decamelize($str, false));
        }
    }

    public function testStrtotitle(): void
    {
        $str = "\"or else it doesn't, you know. the name of the song is called 'haddocks' eyes.'\"";
        $expected = "\"Or Else It Doesn't, You Know. The Name Of The Song Is Called 'Haddocks' Eyes.'\"";
        $this->assertEquals($expected, Str::ucWords($str));
    }

    public function testArrayMergeDistinct(): void
    {
        $arr1 = [
            'one'
        ];
        $arr2 = [
            'two'
        ];

        $res = Arr::mergeDistinct($arr1, $arr2);
        $this->assertEquals(['one', 'two'], $res);

        $arr1 = [
            'key' => 'wrong'
        ];
        $arr2 = [
            'key' => 'right'
        ];

        $res = Arr::mergeDistinct($arr1, $arr2);
        $this->assertEquals(['key' => 'right'], $res);

        $arr1 = [
            'key' => ['one']
        ];
        $arr2 = [
            'key' => ['two']
        ];

        $res = Arr::mergeDistinct($arr1, $arr2);
        $this->assertEquals(['key' => ['one', 'two']], $res);
    }
}
