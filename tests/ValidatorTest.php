<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Validation\Validator;
use Kaly\Validation\Violation;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    public function testAnEmptyValidatorProducesAValidResult(): void
    {
        $result = (new Validator())->result();

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->violations());
    }

    public function testNotBlankRefusesEmptyAndUnicodeWhitespace(): void
    {
        $validator = new Validator();
        $validator->notBlank('a', '')->notBlank('b', " \u{00A0}\t")->notBlank('c', '0')->notBlank('d', 'ada');

        $result = $validator->result();
        $this->assertFalse($result->isValid());
        $this->assertSame(['a', 'b'], array_map(static fn($violation) => $violation->field, $result->violations()));
        $this->assertSame('not_blank', $result->violations()[0]->code);
        $this->assertSame('not_blank', $result->violations()[0]->messageId);
        $this->assertSame('validation', $result->violations()[0]->domain);
    }

    public function testEmailOnlyChecksTheSyntax(): void
    {
        $validator = new Validator();
        $validator->email('ok', 'ada@example.test')->email('ko', 'not-an-email');

        $violations = $validator->result()->for('ko');
        $this->assertCount(1, $violations);
        $this->assertSame('email', $violations[0]->code);
        $this->assertSame([], $validator->result()->for('ok'));
        $this->assertSame([], $validator->result()->for('missing'));
    }

    public function testLengthUsesCodePointsWithInclusiveBounds(): void
    {
        $validator = new Validator();
        $validator->length('short', 'ab', min: 3);
        $validator->length('long', 'abcd', max: 3);
        $validator->length('between', 'ab', min: 3, max: 5);
        $validator->length('ok', 'abc', min: 1, max: 5);
        $validator->length('unicode', 'héllo', max: 4);

        $result = $validator->result();
        $this->assertSame(
            ['length_min', 'length_max', 'length_between', 'length_max'],
            array_map(static fn($violation) => $violation->code, $result->violations()),
        );
        $this->assertSame(['%min%' => 3], $result->for('short')[0]->parameters);
    }

    public function testMinAndMaxLengthAreShortcuts(): void
    {
        $validator = new Validator();
        $validator->minLength('a', 'x', 2)->maxLength('b', 'xyz', 2);

        $this->assertSame('length_min', $validator->result()->for('a')[0]->code);
        $this->assertSame('length_max', $validator->result()->for('b')[0]->code);
    }

    public function testBetweenIsInclusive(): void
    {
        $validator = new Validator();
        $validator->between('min', 18, 18, 120)->between('max', 120, 18, 120);
        $validator->between('low', 12, 18, 120)->between('high', 200, 18, 120);
        $validator->between('at_least', 1, min: 2)->between('at_most', 3, max: 2);

        $result = $validator->result();
        $this->assertSame([], $result->for('min'));
        $this->assertSame([], $result->for('max'));
        $this->assertCount(1, $result->for('low'));
        $this->assertCount(1, $result->for('high'));
        $this->assertSame('between', $result->for('low')[0]->code);
        $this->assertSame('between', $result->for('low')[0]->messageId);
        // One-sided bounds get their own message id so each catalog entry only
        // uses the placeholders it receives; the machine code stays between
        $this->assertSame('between_min', $result->for('at_least')[0]->code);
        $this->assertSame('between_min', $result->for('at_least')[0]->messageId);
        $this->assertSame(['%min%' => 2], $result->for('at_least')[0]->parameters);
        $this->assertSame('between_max', $result->for('at_most')[0]->code);
        $this->assertSame('between_max', $result->for('at_most')[0]->messageId);
        $this->assertSame(['%max%' => 2], $result->for('at_most')[0]->parameters);
    }

    public function testOneOfComparesStrictly(): void
    {
        $validator = new Validator();
        $validator->oneOf('ok', '1', ['1', '2'])->oneOf('ko', 1, ['1', '2']);

        $this->assertSame([], $validator->result()->for('ok'));
        $this->assertSame('one_of', $validator->result()->for('ko')[0]->code);
    }

    public function testMatchesUsesTheGivenPattern(): void
    {
        $validator = new Validator();
        $validator->matches('ok', 'ABC-123', '/^[A-Z]+-\d+$/')->matches('ko', 'abc', '/^[A-Z]+-\d+$/');

        $this->assertSame([], $validator->result()->for('ok'));
        $this->assertSame('pattern', $validator->result()->for('ko')[0]->code);
    }

    public function testCountChecksTheNumberOfItems(): void
    {
        $validator = new Validator();
        $validator->count('few', ['a'], min: 2)->count('many', ['a', 'b', 'c'], max: 2);
        $validator->count('ok', ['a', 'b'], min: 1, max: 3);

        $this->assertSame('count_min', $validator->result()->for('few')[0]->code);
        $this->assertSame('count_max', $validator->result()->for('many')[0]->code);
        $this->assertSame([], $validator->result()->for('ok'));
    }

    public function testLengthCountsUtf8CodePointsWhateverTheInternalEncoding(): void
    {
        $previous = mb_internal_encoding();
        mb_internal_encoding('ISO-8859-1');
        try {
            $validator = new Validator();
            $validator->length('x', 'é', max: 1);

            $this->assertTrue($validator->result()->isValid());
        } finally {
            mb_internal_encoding($previous);
        }
    }

    public function testNullIsIgnoredAndBelongsToTheType(): void
    {
        $validator = new Validator();
        $validator->notBlank('a', null)->email('b', null)->length('c', null, min: 1);
        $validator->between('d', null, min: 1)->oneOf('e', null, ['x']);
        $validator->matches('f', null, '/x/')->count('g', null, min: 1);

        $this->assertTrue($validator->result()->isValid());
    }

    public function testAddAcceptsApplicationViolations(): void
    {
        $validator = new Validator();
        $validator->add(new Violation(
            field: 'end',
            code: 'end_before_start',
            messageId: 'end_before_start',
            fallback: 'End must not be before start',
            domain: 'booking',
        ));

        $violations = $validator->result()->violations();
        $this->assertCount(1, $violations);
        $this->assertSame('booking', $violations[0]->domain);
        $this->assertSame([], $validator->result()->for('start'));
        $this->assertCount(1, $validator->result()->for('end'));
    }

    public function testAGlobalViolationHasANullField(): void
    {
        $validator = new Validator();
        $validator->add(new Violation(null, 'invalid_period', 'invalid_period', 'The selected period is not valid'));

        $this->assertCount(1, $validator->result()->for(null));
    }

    public function testAnInvalidPatternIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Validator())->matches('x', 'value', 'not-a-pattern');
    }

    public function testMissingBoundsAreAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Validator())->length('x', 'value');
    }

    public function testAnInvertedRangeIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Validator())->between('x', 1, 10, 5);
    }

    public function testAMisconfiguredRuleThrowsEvenWithoutAValue(): void
    {
        // Length with no bounds
        try {
            (new Validator())->length('x', null);
            $this->fail('length should have failed');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('At least one bound must be set', $exception->getMessage());
        }

        // Length with an inverted range
        try {
            (new Validator())->length('x', null, 10, 5);
            $this->fail('length should have failed');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The minimum must not be greater than the maximum', $exception->getMessage());
        }

        // Between with no bounds
        try {
            (new Validator())->between('x', null);
            $this->fail('between should have failed');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('At least one bound must be set', $exception->getMessage());
        }

        // Between with an inverted range
        try {
            (new Validator())->between('x', null, 10, 5);
            $this->fail('between should have failed');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The minimum must not be greater than the maximum', $exception->getMessage());
        }

        // Count with no bounds
        try {
            (new Validator())->count('x', null);
            $this->fail('count should have failed');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('At least one bound must be set', $exception->getMessage());
        }

        // Count with an inverted range
        try {
            (new Validator())->count('x', null, 5, 2);
            $this->fail('count should have failed');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The minimum must not be greater than the maximum', $exception->getMessage());
        }

        // Invalid pattern
        try {
            (new Validator())->matches('x', null, 'not-a-pattern');
            $this->fail('matches should have failed');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid regular expression', $exception->getMessage());
        }
    }

    public function testAnInvertedCountRangeIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Validator())->count('x', ['a'], min: 5, max: 2);
    }
}
