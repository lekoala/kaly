<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Exception;
use Kaly\Util;
use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase
{
    public function testCamelize()
    {
        $arr = [
            'my_string' => "My_string",
            'my-string' => "MyString",
            'mystring' => "Mystring",
            'Mystring' => "Mystring",
        ];
        foreach ($arr as $str => $expected) {
            $this->assertEquals($expected, Util::camelize($str));
        }
        $arr = [
            'my_string' => "my_string",
            'my-string' => "myString",
            'mystring' => "mystring",
            'Mystring' => "mystring",
        ];
        foreach ($arr as $str => $expected) {
            $this->assertEquals($expected, Util::camelize($str, false));
        }
    }

    public function testExceptionChain()
    {
        $prev = new Exception("prev");
        $ex = new Exception("test", 0, $prev);
        $this->assertCount(2, Util::getExceptionChain($ex));
    }

    public function testMergeArray()
    {
        $arr1 = [
            'one'
        ];
        $arr2 = [
            'two'
        ];

        $res = Util::mergeArrays($arr1, $arr2);
        $this->assertEquals(['one', 'two'], $res);

        $arr1 = [
            'key' => 'wrong'
        ];
        $arr2 = [
            'key' => 'right'
        ];

        $res = Util::mergeArrays($arr1, $arr2);
        $this->assertEquals(['key' => 'right'], $res);

        $arr1 = [
            'key' => ['one']
        ];
        $arr2 = [
            'key' => ['two']
        ];

        $res = Util::mergeArrays($arr1, $arr2);
        $this->assertEquals(['key' => ['one', 'two']], $res);
    }
}
