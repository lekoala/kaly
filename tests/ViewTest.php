<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\View\Engine;
use Kaly\View\Escaper;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    private Engine $engine;

    public function setUp(): void
    {
        $this->engine = new Engine(__DIR__ . '/views');
        $this->engine->setGlobal('global', 'global');
        $this->engine->setGlobal('global_over', 'global_over');
    }

    public function testItRenders(): void
    {
        $html = $this->engine->render('test', [
            'world' => 'world',
            'global_over' => 'local'
        ]);
        $this->assertStringContainsString('hello world', $html);
        $this->assertStringContainsString('global world', $html);
        $this->assertStringContainsString('local world', $html);
    }

    public function testItRendersVars(): void
    {
        $user = new class {
            public string $firstname = 'Test';
            public string $lastname = 'Surname';
            public function fullname(): string
            {
                return $this->firstname . ' ' . $this->lastname;
            }
        };
        $html = $this->engine->render('mustache', [
            'world' => 'world',
            'country' => [
                'name' => 'Belgium',
                'code' => 'BE',
            ],
            'user' => $user,
        ]);
        $this->assertStringContainsString('hello world', $html);
        $this->assertStringContainsString('Belgium', $html);
        $this->assertStringContainsString("i'm Test", $html);
        $this->assertStringContainsString('Test Surname', $html);
    }

    public function testEscaper(): void
    {
        $escaper = new Escaper();
        $str = $escaper->escHtml(null);
        $this->assertEquals('', $str);
    }
}
