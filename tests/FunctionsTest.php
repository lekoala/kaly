<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Exception;
use Kaly\Util;
use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    public function testCamelize()
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
            $this->assertEquals($expected, camelize($str));
        }
    }

    public function testDecamelize()
    {
        $arr = [
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
            $this->assertEquals($expected, decamelize($str, false));
        }
    }

    public function testExceptionChain()
    {
        $prev = new Exception("prev");
        $ex = new Exception("test", 0, $prev);
        $this->assertCount(2, Util::getExceptionChain($ex));
    }

    public function testArrayMergeDistinct()
    {
        $arr1 = [
            'one'
        ];
        $arr2 = [
            'two'
        ];

        $res = array_merge_distinct($arr1, $arr2);
        $this->assertEquals(['one', 'two'], $res);

        $arr1 = [
            'key' => 'wrong'
        ];
        $arr2 = [
            'key' => 'right'
        ];

        $res = array_merge_distinct($arr1, $arr2);
        $this->assertEquals(['key' => 'right'], $res);

        $arr1 = [
            'key' => ['one']
        ];
        $arr2 = [
            'key' => ['two']
        ];

        $res = array_merge_distinct($arr1, $arr2);
        $this->assertEquals(['key' => ['one', 'two']], $res);
    }
}
