<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\Context;
use CoolMS\Dtmpl\Runtime\EntityWrapper;
use CoolMS\Dtmpl\Runtime\ObjectReadPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * A template reads an object's fields; it does not read its secrets and it does not call its methods.
 *
 * Both doors are asserted, because an object reaches a template two ways: wrapped, as the entity widgets hand
 * it over, and raw, as any renderer may put it in a context. Each test states which door it went through.
 */
final class ASecretIsNotReadableFromATemplateTest extends TestCase
{
    private PropertyAccessorInterface $accessor;

    protected function setUp(): void
    {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    /** Every way the same secret can be asked for, through a raw object in a context. */
    #[Test]
    #[DataProvider('theWaysASecretIsAskedFor')]
    public function aSecretIsNotReadableThroughAContext(string $segment): void
    {
        $context = new Context(['u' => self::anAccount()]);

        self::assertNull($context->get(['u', $segment]), $segment . ' must read as an absent field');
    }

    /** The same, wrapped, as a widget hands the entity over. */
    #[Test]
    #[DataProvider('theWaysASecretIsAskedFor')]
    public function aSecretIsNotReadableThroughTheWrapper(string $segment): void
    {
        $wrapper = new EntityWrapper(self::anAccount(), $this->accessor);

        self::assertNull($wrapper->__get($segment), $segment . ' must read as an absent field');
        self::assertFalse(isset($wrapper->$segment), $segment . ' must not even be set');
    }

    /** @return iterable<string, array{string}> */
    public static function theWaysASecretIsAskedFor(): iterable
    {
        yield 'the property itself' => ['password'];
        yield 'its getter, written out' => ['getPassword'];
        yield 'a secret whose name is not "password"' => ['sealedSecret'];
        yield 'a token hash' => ['tokenHash'];
        yield 'credentials' => ['sipCredentials'];
        yield 'a recovery code' => ['recoveryCodes'];
    }

    /**
     * The control, which must FAIL DIFFERENTLY: an ordinary field still reads, through either door. Without
     * this, a refusal of everything would pass the tests above.
     */
    #[Test]
    public function anOrdinaryFieldStillReads(): void
    {
        $context = new Context(['u' => self::anAccount()]);
        $wrapper = new EntityWrapper(self::anAccount(), $this->accessor);

        self::assertSame('alice@example.test', $context->get(['u', 'email']));
        self::assertSame('alice@example.test', $wrapper->__get('email'));
        self::assertTrue($context->get(['u', 'active']), 'an is-getter reads');
        self::assertSame('Alice', $context->get(['u', 'fullName']), 'a get-getter reads');
        self::assertSame('a bank', $context->get(['u', 'issuer']), 'a field whose name begins with "is" is not a getter');
    }

    /** A template may not CALL a method: the value is absent, and the object is untouched. */
    #[Test]
    public function aBareMethodIsNotCalled(): void
    {
        $account = self::anAccount();
        $context = new Context(['u' => $account]);
        $wrapper = new EntityWrapper($account, $this->accessor);

        self::assertNull($context->get(['u', 'reactivate']), 'through a context');
        self::assertNull($wrapper->__get('reactivate'), 'through the wrapper');
        self::assertFalse($account->reactivated, 'and the method did not run');
    }

    /** The wrapper's own accessor is not a way to the object behind it. */
    #[Test]
    public function theWrapperDoesNotHandOverTheEntity(): void
    {
        $wrapper = new EntityWrapper(self::anAccount(), $this->accessor);
        $context = new Context(['u' => $wrapper]);

        self::assertNull($context->get(['u', 'entity']), 'entity() is a method over a private property');
        self::assertNull($context->get(['u', 'entity', 'password']), 'so it is no way to the secret either');
    }

    /** What the policy answers on its own, including the names it must NOT take for getters. */
    #[Test]
    public function thePolicyNamesTheFieldASegmentAsksFor(): void
    {
        self::assertSame('password', ObjectReadPolicy::fieldName('getPassword'));
        self::assertSame('active', ObjectReadPolicy::fieldName('isActive'));
        self::assertSame('issuer', ObjectReadPolicy::fieldName('issuer'), 'is + lower case is a name, not a getter');
        self::assertSame('candidate', ObjectReadPolicy::fieldName('candidate'), 'can + lower case likewise');
        self::assertTrue(ObjectReadPolicy::isSecret('getPassword'));
        self::assertFalse(ObjectReadPolicy::isSecret('contentHash'), 'a hash of content is not a secret');
    }

    private static function anAccount(): object
    {
        return new class {
            public bool $reactivated = false;

            public string $email = 'alice@example.test';

            /** A field whose name begins with "is" but is not a getter. */
            public string $issuer = 'a bank';

            private string $password = 'not-a-real-hash';

            private string $theSecret = 'not-a-real-secret';

            public function getPassword(): string
            {
                return $this->password;
            }

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

            public function getRecoveryCodes(): array
            {
                return ['not-a-real-code'];
            }

            public function getFullName(): string
            {
                return 'Alice';
            }

            public function isActive(): bool
            {
                return true;
            }

            public function reactivate(): void
            {
                $this->reactivated = true;
            }
        };
    }
}
