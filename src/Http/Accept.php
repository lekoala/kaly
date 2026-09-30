<?php

declare(strict_types=1);

namespace Kaly\Http;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Content negotiation over the Accept header.
 *
 * One implementation of the q weights, so every caller agrees on what the
 * client asked for: RequestUtils::getPreferredContentType() picks from a server
 * priority list, ExceptionHandler::wantsJson() asks whether JSON beats HTML.
 *
 * Entries keep their specificity: an explicit `text/html` outranks `text/*` at
 * equal weight, and a wildcard only applies to the types that no explicit entry
 * covers.
 */
final class Accept
{
    /**
     * @param list<AcceptEntry> $entries
     */
    private function __construct(
        private array $entries,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return self::parse($request->getHeaderLine('Accept'));
    }

    public static function parse(string $header): self
    {
        if (trim($header) === '') {
            // No preference expressed: everything is acceptable
            return new self([new AcceptEntry(self::any(), 1.0, 0)]);
        }

        $entries = [];
        $position = 0;
        foreach (MediaType::splitOn($header, ',') as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pieces = MediaType::splitOn($part, ';');
            $name = strtolower(trim((string) array_shift($pieces)));
            // An entry without a type/subtype separator is not a media type
            if ($name === '' || !str_contains($name, '/')) {
                continue;
            }
            $media = MediaType::parse($name);
            if ($media === null || $media->isEmpty()) {
                continue;
            }
            $entries[] = new AcceptEntry($media, self::quality($pieces), $position++);
        }

        if ($entries === []) {
            return new self([new AcceptEntry(self::any(), 1.0, 0)]);
        }

        // Highest weight first; at equal weight the most specific entry wins,
        // and after that the order the client wrote them in
        usort($entries, static function (AcceptEntry $a, AcceptEntry $b): int {
            return [$b->quality, $b->media->specificity(), -$b->position] <=> [$a->quality, $a->media->specificity(), -$a->position];
        });

        return new self($entries);
    }

    /**
     * The best type for this request, from a server priority list.
     *
     * The client leads: an entry weighs as much as its q value says. The
     * priority list is the server preference and breaks ties, and says nothing
     * when the client refused the entry (q=0). Null when the list is empty or
     * nothing in it is acceptable.
     *
     * @param string[] $priorityList
     */
    public function negotiate(array $priorityList): ?string
    {
        $best = null;
        $bestQuality = 0.0;
        foreach ($priorityList as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate === '') {
                continue;
            }
            $separator = strpos($candidate, '/');
            $type = $separator === false ? $candidate : substr($candidate, 0, $separator);
            $subtype = $separator === false ? '*' : substr($candidate, $separator + 1);

            $quality = $this->qualityFor($type, $subtype);
            // Strictly greater: an equal weight keeps the earlier entry, so
            // the list order decides the ties
            if ($quality > $bestQuality) {
                $best = $candidate;
                $bestQuality = $quality;
            }
        }
        return $best;
    }

    /**
     * The best weight the client gave to a type, 0.0 when it refused it.
     *
     * A wildcard entry only counts when nothing more specific applies, so an
     * explicit "text/html;q=0.5" is not overridden by a later bare wildcard
     * weighing 0.9.
     */
    public function qualityFor(string $type, string $subtype = '*'): float
    {
        $best = null;
        $bestSpecificity = -1;
        foreach ($this->entries as $entry) {
            if (!$entry->media->accepts($type, $subtype)) {
                continue;
            }
            $specificity = $entry->media->specificity();
            // A more specific entry is the client's real opinion; a wildcard
            // only fills the gaps
            if ($best === null || $specificity > $bestSpecificity) {
                $best = $entry->quality;
                $bestSpecificity = $specificity;
            }
        }
        return $best ?? 0.0;
    }

    /**
     * The best weight given to a type by an entry that names it, ignoring the
     * anything wildcard (`type` = `*`).
     *
     * Used to tell "the client does not want HTML" from "the client does not
     * care": a bare wildcard expresses no opinion, so it does not count here.
     * A family entry like `text/*` does name the type and does count.
     */
    public function explicitQualityFor(string $type, string $subtype = '*'): float
    {
        $best = null;
        foreach ($this->entries as $entry) {
            if ($entry->media->isWildcard() || !$entry->media->accepts($type, $subtype)) {
                continue;
            }
            $best = max($best ?? 0.0, $entry->quality);
        }
        return $best ?? 0.0;
    }

    /**
     * Does the client accept this type, at any weight?
     */
    public function accepts(string $type, string $subtype = '*'): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->media->accepts($type, $subtype)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The accepted media types, best first.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        $types = [];
        foreach ($this->entries as $entry) {
            $types[] = (string) $entry->media;
        }
        return $types;
    }

    /**
     * @param string[] $pieces The parameters of a single Accept entry
     */
    private static function quality(array $pieces): float
    {
        foreach ($pieces as $piece) {
            $piece = trim($piece);
            $eq = strpos($piece, '=');
            if ($eq === false || strtolower(trim(substr($piece, 0, $eq))) !== 'q') {
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

    /**
     * The "anything" entry used when the client expressed no preference.
     */
    private static function any(): MediaType
    {
        $any = MediaType::parse('*/*');
        if ($any === null) {
            throw new LogicException('The wildcard media type failed to parse');
        }
        return $any;
    }
}
