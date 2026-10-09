<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;
use ValueError;

class PasswordHasherTest extends TestCase
{
    public function testHashesAreNativeCompatibleAndSalted(): void
    {
        $hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $first = $hasher->hash('secret');
        $second = $hasher->hash('secret');

        $this->assertTrue(password_verify('secret', $first));
        $this->assertTrue($hasher->verify('secret', $second));
        $this->assertFalse($hasher->verify('wrong', $first));
        $this->assertFalse($hasher->verify('secret', 'invalid hash'));
        $this->assertNotSame($first, $second);
        $this->assertSame(4, password_get_info($first)['options']['cost']);
        $this->assertFalse($hasher->needsRehash($first));
    }

    public function testRehashTracksTheConfiguredPolicyWithoutChangingVerification(): void
    {
        $fast = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
        $stronger = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 5]);
        $hash = $fast->hash('secret');

        $this->assertTrue($stronger->verify('secret', $hash));
        $this->assertTrue($stronger->needsRehash($hash));
        $this->assertFalse($stronger->needsRehash($stronger->hash('secret')));
        $this->assertTrue($fast->needsRehash('invalid hash'));
    }

    public function testDefaultPolicyUsesPhpDefaults(): void
    {
        $hash = (new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]))->hash('secret');
        $hasher = new PasswordHasher();

        $this->assertTrue($hasher->verify('secret', $hash));
        $this->assertSame(password_needs_rehash($hash, PASSWORD_DEFAULT), $hasher->needsRehash($hash));
    }

    public function testInvalidPolicyIsRejectedWhenHashing(): void
    {
        $hasher = new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 3]);
        $this->expectException(ValueError::class);
        $hasher->hash('secret');
    }
}
