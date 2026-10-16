<?php

declare(strict_types=1);

namespace Kaly\Util;

use Kaly\Ex;
use LogicException;

/**
 * Command line arguments of a `bin/*.php` script, parsed against declared options.
 *
 * Declaring which long options are flags and which take a value removes the
 * ambiguity of `--dry-run export.zip`. Supported: `--flag`, `--name=value`,
 * `--name value`, the `--` separator, options before or after positionals.
 * Unknown, repeated or malformed options throw `Kaly\Ex`, never exit: the
 * script decides the exit code. Short options, negation, repeated values and
 * help generation are out of scope; use symfony/console for them.
 */
final class Args
{
    /**
     * @param array<string,bool> $flags
     * @param array<string,?string> $values
     * @param list<string> $positionals
     */
    private function __construct(
        private readonly array $flags,
        private readonly array $values,
        private readonly array $positionals,
    ) {}

    /**
     * @param list<string> $argv Arguments as PHP provides them, starting with the script
     * @param list<string> $flags Options without a value
     * @param list<string> $values Options requiring a value
     * @throws Ex When the arguments do not match the declared options
     */
    public static function parse(array $argv, array $flags = [], array $values = []): self
    {
        $declaredFlags = self::declare($flags, [], false);
        $declaredValues = self::declare($values, $declaredFlags, null);
        $positionals = [];
        $seen = [];

        $args = array_slice($argv, 1);
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if ($arg === '--') {
                array_push($positionals, ...array_slice($args, $i + 1));
                break;
            }
            if ($arg === '-' || !str_starts_with($arg, '-')) {
                $positionals[] = $arg;
                continue;
            }
            if (!str_starts_with($arg, '--')) {
                throw new Ex("Unknown option {$arg}");
            }

            $name = substr($arg, 2);
            $inline = null;
            $equals = strpos($name, '=');
            if ($equals !== false) {
                $inline = substr($name, $equals + 1);
                $name = substr($name, 0, $equals);
            }
            if (isset($seen[$name])) {
                throw new Ex("Option --{$name} is given more than once");
            }
            $seen[$name] = true;

            if (array_key_exists($name, $declaredFlags)) {
                if ($inline !== null) {
                    throw new Ex("Option --{$name} does not take a value");
                }
                $declaredFlags[$name] = true;
            } elseif (array_key_exists($name, $declaredValues)) {
                if ($inline === null) {
                    $next = $args[$i + 1] ?? null;
                    if ($next === null || $next !== '-' && str_starts_with($next, '-')) {
                        throw new Ex("Option --{$name} requires a value");
                    }
                    $inline = $next;
                    $i++;
                }
                $declaredValues[$name] = $inline;
            } else {
                throw new Ex("Unknown option --{$name}");
            }
        }

        return new self($declaredFlags, $declaredValues, $positionals);
    }

    /**
     * Whether a declared flag is present
     */
    public function flag(string $name): bool
    {
        if (!array_key_exists($name, $this->flags)) {
            throw new LogicException("Flag --{$name} is not declared");
        }
        return $this->flags[$name];
    }

    /**
     * The value of a declared option, or null when it is absent
     */
    public function value(string $name): ?string
    {
        if (!array_key_exists($name, $this->values)) {
            throw new LogicException("Option --{$name} is not declared");
        }
        return $this->values[$name];
    }

    /**
     * A required positional argument, counted from 0
     *
     * @throws Ex When the argument is missing
     */
    public function positional(int $index): string
    {
        return $this->positionals[$index] ?? throw new Ex('Missing argument ' . ($index + 1));
    }

    /**
     * @return list<string>
     */
    public function positionals(): array
    {
        return $this->positionals;
    }

    /**
     * @template T
     * @param list<string> $names
     * @param array<string,mixed> $taken
     * @param T $initial
     * @return array<string,T>
     */
    private static function declare(array $names, array $taken, mixed $initial): array
    {
        $declared = [];
        foreach ($names as $name) {
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $name) !== 1) {
                throw new LogicException(
                    "Option name \"{$name}\" must start with a letter or digit and contain only letters, digits, dashes or underscores",
                );
            }
            if (isset($declared[$name]) || array_key_exists($name, $taken)) {
                throw new LogicException("Option --{$name} is declared more than once");
            }
            $declared[$name] = $initial;
        }
        return $declared;
    }
}
