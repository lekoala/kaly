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
}
