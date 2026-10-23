<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Content negotiation over the Accept-Language header.
 *
 * One implementation of the language weights, analogous to Accept for media
 * types. Entries keep the order the client wrote them in: at equal weight,
 * the earlier entry is the client's preference.
 *
 * The wildcard `*` is kept: it means "any language you support", and only
 * fills the gaps the explicit entries left open.
 */
final class AcceptLanguage
{
    /**
     * @param list<AcceptLanguageEntry> $entries
     */
    private function __construct(
        private array $entries,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return self::parse($request->getHeaderLine('Accept-Language'));
    }

    public static function parse(string $header): self
    {
        $entries = [];
        $position = 0;
        foreach (MediaType::splitOn($header, ',') as $part) {
            $piece = trim($part);
            if ($piece === '') {
                continue;
            }
            $elements = MediaType::splitOn($piece, ';');
            $language = trim((string) array_shift($elements));
            if ($language === '') {
                continue;
            }
            $entries[] = new AcceptLanguageEntry($language, self::quality($elements), $position++);
        }

        // Highest weight first; at equal weight the order the client wrote
        // them in decides
        usort(
            $entries,
            static fn(AcceptLanguageEntry $a, AcceptLanguageEntry $b): int => [$b->quality, -$b->position] <=> [$a->quality, -$a->position],
        );

        return new self($entries);
    }

    /**
     * The best language for this request.
     *
     * When $allowed is provided, the best supported language is negotiated:
     * the client leads, so "en-US" matches the supported "en", a language
     * refused with q=0 is never a match, and the wildcard fills the gaps.
     * Null when nothing matches so the caller can fall back to its own
     * default.
     *
     * @param string[]|null $allowed
     */
    public function best(?array $allowed = null): ?string
    {
        if ($allowed === null) {
            // Without a list, the best accepted language is the client's own
            // preference; the wildcard is not a language
            foreach ($this->entries as $entry) {
                if ($entry->language !== '*' && $entry->quality > 0.0) {
                    return $entry->language;
                }
            }
            return null;
        }

        foreach ($this->entries as $entry) {
            if ($entry->quality <= 0.0) {
                continue;
            }
            foreach ($allowed as $candidate) {
                if (
                    trim($candidate) !== ''
                    && $this->matches($entry, strtolower(str_replace('_', '-', trim($candidate))))
                    && $this->qualityFor($candidate) === $entry->quality
                ) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * The best weight the client gave to a language, 0.0 when it refused it.
     *
     * An explicit entry outranks the wildcard, which only fills the gaps: an
     * "en;q=0.5" is not overridden by a later bare wildcard weighing 0.9.
     */
    public function qualityFor(string $language): float
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        $best = 0.0;
        $bestSpecificity = -1;
        foreach ($this->entries as $entry) {
            if (!$this->matches($entry, $language)) {
                continue;
            }
            $specificity = $entry->language === '*' ? 0 : count(explode('-', str_replace('_', '-', $entry->language)));
            if ($specificity > $bestSpecificity) {
                $best = $entry->quality;
                $bestSpecificity = $specificity;
            }
        }
        return $best;
    }

    /**
     * Does the language tag match the entry, directly or by prefix?
     *
     * Positive preferences can fall back to their language, so "en-US" can
     * select supported "en". Refusals only apply to the named range and its
     * descendants: refusing "en-GB" does not refuse generic "en".
     */
    private function matches(AcceptLanguageEntry $entry, string $language): bool
    {
        $tag = strtolower(str_replace('_', '-', $entry->language));
        if ($tag === '*' || $tag === $language) {
            return true;
        }
        return str_starts_with($language, $tag . '-') || $entry->quality > 0.0 && str_starts_with($tag, $language . '-');
    }

    /**
     * @param string[] $pieces The parameters of a single entry
     */
    private static function quality(array $pieces): float
    {
        foreach ($pieces as $piece) {
            $piece = trim($piece);
            $eq = strpos($piece, '=');
            if ($eq === false || MediaType::normalizeName(substr($piece, 0, $eq)) !== 'q') {
                continue;
            }
            // `q = 0.5` and `q="0.5"` are both seen in the wild
            $value = trim(trim(substr($piece, $eq + 1)), "\"'");
            $quality = (float) $value;
            // A malformed or out of range weight means "not acceptable"
            // rather than a silent default
            return $quality >= 0.0 && $quality <= 1.0 ? $quality : 0.0;
        }
        return 1.0;
    }
}
