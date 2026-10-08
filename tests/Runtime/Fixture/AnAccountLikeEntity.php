<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime\Fixture;

/**
 * An entity shaped like an application's account, for the tests of what a template may read from an object.
 *
 * A named class rather than an anonymous one: a test asserts that a method did NOT run, which means reading a
 * property of the fixture, and an anonymous class behind a function returning `object` hides its own shape from a
 * static analyser. The shapes here are the ones that matter to the policy, and each says why it is shaped so.
 *
 * None of these values is a secret. They are strings chosen to be recognisable in a failure message.
 */
final class AnAccountLikeEntity
{
    /** Set by {@see reactivate()}, so a test can assert the method did not run. */
    public bool $reactivated = false;

    /** An ordinary public field: the control, which must stay readable. */
    public string $email = 'alice@example.test';

    /** A field whose name begins with "is" without being a getter for `suer`. */
    public string $issuer = 'a bank';

    /** A secret in a PUBLIC field, as an application's may be before it is made private. */
    public string $cancelCodeHash = 'not-a-real-digest';

    private string $password = 'not-a-real-hash';
    private string $theSecret = 'not-a-real-secret';

    public function getPassword(): string
    {
        return $this->password;
    }

    /** A secret read through a method that is not a getter: the name carries it, not the shape. */
    public function sealedSecret(): string
    {
        return $this->theSecret;
    }

    public function getTokenHash(): string
    {
        return 'not-a-real-token-hash';
    }

    public function getSipCredentials(): string
    {
        return 'not-real-credentials';
    }

    /** @return list<string> */
    public function getRecoveryCodes(): array
    {
        return ['not-a-real-code'];
    }

    /** A one-time code's digest behind a bare method, the other shape the name rule has to catch. */
    public function hashedCode(): string
    {
        return 'a-code-digest';
    }

    public function getFullName(): string
    {
        return 'Alice';
    }

    public function isActive(): bool
    {
        return true;
    }

    /** A method that changes state, which a template must not be able to call. */
    public function reactivate(): void
    {
        $this->reactivated = true;
    }
}
