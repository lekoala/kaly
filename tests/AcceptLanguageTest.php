<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\AcceptLanguage;
use PHPUnit\Framework\TestCase;

class AcceptLanguageTest extends TestCase
{
    private function parse(string $header): AcceptLanguage
    {
        return AcceptLanguage::parse($header);
    }

    public function testWeightsAndClientOrder(): void
    {
        $languages = $this->parse('fr;Q=0.8, en;q=0.9, fr-CA;q=0.8');
        $this->assertSame('en', $languages->best());
        // The client's earlier fr entry wins the tie; the server list only
        // decides which candidate a single entry matches
        $this->assertSame('fr', $languages->best(['fr', 'fr-CA']));
        $this->assertSame('en', $languages->best(['en', 'fr']));
    }

    public function testWhitespaceAroundParameters(): void
    {
        $languages = $this->parse('en; q=0.5');
        $this->assertSame(0.5, $languages->qualityFor('en'));
    }

    public function testRefusedLanguageIsNeverAMatch(): void
    {
        // q=0 means "I do not accept it", even when it is the only candidate
        $languages = $this->parse('en;q=0.8, fr;q=0');
        $this->assertNull($languages->best(['fr']));
        $this->assertSame('en', $languages->best(['en', 'fr']));
    }

    public function testRefusalByDefault(): void
    {
        $this->assertSame(0.0, $this->parse('fr')->qualityFor('en'));
        $this->assertSame(0.0, $this->parse('en;q=0')->qualityFor('en'));
        $this->assertNull($this->parse('en;q=0')->best());
        $this->assertNull($this->parse('en;q=0')->best(['en']));
    }

    public function testMalformedWeightMeansRefusal(): void
    {
        $this->assertSame(0.0, $this->parse('en;q=abc')->qualityFor('en'));
        $this->assertSame(0.0, $this->parse('en;q=2')->qualityFor('en'));
        $this->assertNull($this->parse('en;q=2')->best(['en']));
    }

    public function testWildcardFillsTheGaps(): void
    {
        $languages = $this->parse('*, fr;q=0');
        $this->assertSame(0.0, $languages->qualityFor('fr'));
        $this->assertNull($languages->best(['fr']));
        $this->assertSame('de', $languages->best(['de']));
        // The wildcard never overrides an explicit entry
        $this->assertSame(0.4, $this->parse('fr;q=0.4, *;q=0.9')->qualityFor('fr'));
        // The client refuses a language through the wildcard weight too
        $this->assertNull($this->parse('*;q=0')->best(['de']));
    }

    public function testPrefixMatchingIsForgivingBothWays(): void
    {
        $languages = $this->parse('en-US');
        $this->assertSame('en', $languages->best(['en']));
        $this->assertSame('en', $languages->best(['en', 'en-GB']));
        $this->assertNull($languages->best(['de']));
        // A sibling range does not match: en-US is not en-GB
        $this->assertNull($languages->best(['en-GB']));
        // A range matches a less specific candidate too
        $this->assertSame('en', $this->parse('en-GB;q=1')->best(['en']));
    }

    public function testMissingHeaderExpressesNoPreference(): void
    {
        $this->assertNull($this->parse('')->best());
        $this->assertNull($this->parse('')->best(['fr']));
    }

    public function testEmptyAndDuplicateEntries(): void
    {
        $languages = $this->parse(' , en;;q=0.9, en;q=0.9');
        $this->assertSame('en', $languages->best());
        // Duplicates keep the first position
        $this->assertSame('en', $languages->best(['en']));
    }

    public function testClientOrderBreaksTies(): void
    {
        $languages = $this->parse('fr;q=0.5, en;q=0.5');
        // The order the client wrote the entries in decides at equal weight
        $this->assertSame('fr', $languages->best(['en', 'fr']));
        $this->assertSame('fr', $languages->best(['fr', 'en']));
    }
}
