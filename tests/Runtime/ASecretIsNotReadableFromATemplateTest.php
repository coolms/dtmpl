<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Runtime\Context;
use CoolMS\Dtmpl\Runtime\EntityWrapper;
use CoolMS\Dtmpl\Runtime\FilterRegistry;
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
        yield 'a one-time code digest' => ['hashedCode'];
        yield 'the same digest, the other way round, in a public field' => ['cancelCodeHash'];
        yield 'that public field written snake_case' => ['cancel_code_hash'];
        yield 'written snake_case' => ['hashed_code'];
        yield 'the getter, snake_case' => ['get_password'];
        yield 'the getter, kebab-case' => ['get-password'];
        yield 'capitalised' => ['Password'];
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

    /**
     * The same method, spelt the ways PropertyAccessor would still find it: it camelizes a segment before it
     * looks for a method, and PHP matches a method name without regard to case. Measured 2026-10-08: at the
     * first revision of this policy `re_activate` RAN reactivate().
     */
    #[Test]
    #[DataProvider('theWaysAMethodIsAskedFor')]
    public function aMethodIsNotCalledBySpellingItAnotherWay(string $segment): void
    {
        $account = self::anAccount();

        self::assertNull(new Context(['u' => $account])->get(['u', $segment]), $segment . ' must read as absent');
        self::assertFalse($account->reactivated, $segment . ' must not have run the method');
    }

    /** @return iterable<string, array{string}> */
    public static function theWaysAMethodIsAskedFor(): iterable
    {
        yield 'as written' => ['reactivate'];
        yield 'snake_case' => ['re_activate'];
        yield 'kebab-case' => ['re-activate'];
        yield 'capitalised' => ['Reactivate'];
    }

    /**
     * A filter that reads a field off an object itself asks the same policy. Without it,
     * `filter_by:`password`,`a-guess`` is an equality test against a secret -- one guess per render -- around
     * every refusal above.
     */
    #[Test]
    public function aFilterCannotCompareAgainstASecret(): void
    {
        $account = self::anAccount();
        $filters = new FilterRegistry();

        $bySecret = $filters->apply('filter_by', [$account], ['password', 'not-a-real-hash']);
        self::assertSame([], $bySecret, 'the account must not be matched on its secret, even by the right value');
        $byGetter = $filters->apply('filter_by', [$account], ['getPassword', 'not-a-real-hash']);
        self::assertSame([], $byGetter, 'nor on the getter spelt out');
        $bySnake = $filters->apply('filter_by', [$account], ['hashed_code', 'a-code-digest']);
        self::assertSame([], $bySnake, 'nor by another spelling');

        // The control: the same filter, the same object, an ordinary field -- it still selects.
        self::assertSame([$account], $filters->apply('filter_by', [$account], ['email', 'alice@example.test']));
        self::assertSame([], $filters->apply('filter_by', [$account], ['email', 'someone@example.test']));
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
        // Asked directly, in any spelling: a caller of its own passes the name as it is written, so the
        // separators come off here too rather than only in mayRead()'s camelized name.
        self::assertTrue(ObjectReadPolicy::isSecret('cancel_code_hash'), 'a part spanning the separators is found');
        self::assertTrue(ObjectReadPolicy::isSecret('hashed-code'));
        self::assertTrue(ObjectReadPolicy::isSecret('get_password'));
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

            public function hashedCode(): string
            {
                return 'a-code-digest';
            }

            /** A secret in a PUBLIC field, as one of the application's is before it is made private. */
            public string $cancelCodeHash = 'not-a-real-digest';

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
