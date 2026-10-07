<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Fiber;
use Kaly\Auth\Authentication;
use Kaly\Auth\AuthView;
use Kaly\Http\Csp\Csp;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Csrf\CsrfView;
use Kaly\Http\Session\ArraySession;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translator;
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use PHPUnit\Framework\TestCase;

class RenderCapabilitiesTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function data(string $locale): array
    {
        $translator = (new Translator('en'))->addPath(__DIR__ . '/data/lang');
        return [
            'pageLocal' => 'foo',
            'i18n' => new LocalizedTranslator($translator, $locale),
            'url' => static fn(string $name): string => '/' . $name,
            'asset' => static fn(string $asset): string => '/assets/' . $asset,
            'auth' => new AuthView(new Authentication()),
            'csrf' => new CsrfView(new Csrf(), new ArraySession()),
            'csp' => new Csp(),
        ];
    }

    public function testAllCapabilitiesReachPartialsAndLayouts(): void
    {
        $renderer = new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl/caps'));

        $body = $renderer->render('page', $this->data('fr'));

        $this->assertStringContainsString('caps:Message de test:/home:/assets/app.css:no:csrf:ok', $body);
        $this->assertStringContainsString('page:foo', $body);
        $this->assertStringStartsWith('fr|', $body);
        $this->assertStringContainsString('layout-auth:no', $body);
        $this->assertStringContainsString('csp:nonce', $body);
    }

    public function testConcurrentRendersStayIsolated(): void
    {
        $renderer = new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl/caps'));
        $outputs = [];
        $fr = $this->data('fr');
        $en = $this->data('en');

        $fiberFr = new Fiber(static function () use ($renderer, $fr, &$outputs): void {
            $outputs['fr-first'] = $renderer->render('page', $fr);
            Fiber::suspend();
            $outputs['fr-second'] = $renderer->render('page', $fr);
        });
        $fiberEn = new Fiber(static function () use ($renderer, $en, &$outputs): void {
            $outputs['en'] = $renderer->render('page', $en);
        });

        $fiberFr->start();
        $fiberEn->start();
        $fiberFr->resume();

        $this->assertStringContainsString('Message de test', $outputs['fr-first']);
        $this->assertStringContainsString('Test message', $outputs['en']);
        $this->assertStringNotContainsString('Message de test', $outputs['en']);
        $this->assertSame($outputs['fr-first'], $outputs['fr-second']);
    }
}
