<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Ex;
use Kaly\Util\Args;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArgsTest extends TestCase
{
    /**
     * @param list<string> $args
     */
    private static function parse(array $args): Args
    {
        return Args::parse(['bin/import.php', ...$args], flags: ['dry-run', 'force'], values: ['format', 'output']);
    }

    public function testFlagFollowedByPositionalStaysAFlag(): void
    {
        $args = self::parse(['--dry-run', 'export.zip']);

        $this->assertTrue($args->flag('dry-run'));
        $this->assertFalse($args->flag('force'));
        $this->assertSame('export.zip', $args->positional(0));
    }

    public function testValueSpellings(): void
    {
        $this->assertSame('html', self::parse(['--format=html'])->value('format'));
        $this->assertSame('html', self::parse(['--format', 'html'])->value('format'));
        $this->assertSame('a=b', self::parse(['--format=a=b'])->value('format'));
        $this->assertSame('', self::parse(['--format='])->value('format'));
        $this->assertSame('-1', self::parse(['--format=-1'])->value('format'));
        $this->assertSame('-', self::parse(['--output', '-'])->value('output'));
        $this->assertNull(self::parse([])->value('format'));
    }

    public function testOptionsBeforeAndAfterPositionals(): void
    {
        $args = self::parse(['a.zip', '--format', 'csv', 'b.zip', '--dry-run']);

        $this->assertSame(['a.zip', 'b.zip'], $args->positionals());
        $this->assertSame('csv', $args->value('format'));
        $this->assertTrue($args->flag('dry-run'));
    }

    public function testSeparatorEndsOptions(): void
    {
        $args = self::parse(['--dry-run', '--', '--force', '-x', 'file']);

        $this->assertTrue($args->flag('dry-run'));
        $this->assertFalse($args->flag('force'));
        $this->assertSame(['--force', '-x', 'file'], $args->positionals());
    }

    public function testSingleDashIsPositional(): void
    {
        $this->assertSame(['-'], self::parse(['-'])->positionals());
    }

    public function testScriptNameIsNotAnArgument(): void
    {
        $this->assertSame([], Args::parse(['bin/import.php'])->positionals());
        $this->assertSame([], Args::parse([])->positionals());
    }

    /**
     * @return iterable<string,array{list<string>,string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'unknown option' => [['--verbose'], 'Unknown option --verbose'];
        yield 'unknown option with value' => [['--verbose=1'], 'Unknown option --verbose'];
        yield 'short option' => [['-n'], 'Unknown option -n'];
        yield 'flag with value' => [['--dry-run=1'], 'Option --dry-run does not take a value'];
        yield 'flag with empty value' => [['--dry-run='], 'Option --dry-run does not take a value'];
        yield 'missing value at end' => [['--format'], 'Option --format requires a value'];
        yield 'option instead of value' => [['--format', '--dry-run'], 'Option --format requires a value'];
        yield 'dash value needs equals' => [['--format', '-1'], 'Option --format requires a value'];
        yield 'repeated flag' => [['--force', '--force'], 'Option --force is given more than once'];
        yield 'repeated value' => [['--format=a', '--format', 'b'], 'Option --format is given more than once'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsThrow(array $args, string $message): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage($message);
        self::parse($args);
    }

    public function testMissingPositionalThrows(): void
    {
        $args = self::parse(['a.zip']);

        $this->assertSame('a.zip', $args->positional(0));
        $this->expectException(Ex::class);
        $this->expectExceptionMessage('Missing argument 2');
        $args->positional(1);
    }

    public function testUndeclaredFlagIsAProgrammingError(): void
    {
        $this->expectException(\Kaly\Ex::class);
        self::parse([])->flag('format');
    }

    public function testUndeclaredValueIsAProgrammingError(): void
    {
        $this->expectException(\Kaly\Ex::class);
        self::parse([])->value('dry-run');
    }

    public function testOptionDeclaredTwiceIsAProgrammingError(): void
    {
        $this->expectException(\Kaly\Ex::class);
        $this->expectExceptionMessage('Option --format is declared more than once');
        Args::parse([], flags: ['format'], values: ['format']);
    }

    public function testInvalidOptionNameIsAProgrammingError(): void
    {
        $this->expectException(\Kaly\Ex::class);
        Args::parse([], flags: ['--dry-run']);
    }
}
