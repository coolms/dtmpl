<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Validation;

use CoolMS\Dtmpl\Exception\TemplateValidationException;
use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Parser\Parser;
use CoolMS\Dtmpl\Validation\ArrayAliasResolver;
use CoolMS\Dtmpl\Validation\DtmplSyntaxValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Exercises every node type the validator walks. The Lexer + Parser
 * are real (no mocks) so the test catches drift between the validator
 * and the actual DTMPL grammar.
 */
final class DtmplSyntaxValidatorTest extends TestCase
{
    #[Test]
    public function emptyInputYieldsEmptySchema(): void
    {
        $schema = $this->makeValidator()->validateAndExtract('');

        self::assertSame([], $schema->variables);
        self::assertSame([], $schema->constants);
        self::assertSame([], $schema->loops);
        self::assertSame([], $schema->conditionals);
    }

    #[Test]
    public function plainTextYieldsEmptySchema(): void
    {
        $schema = $this->makeValidator()->validateAndExtract('Hello, world. No DTMPL here.');

        self::assertSame([], $schema->variables);
    }

    #[Test]
    public function extractsSimpleVariables(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            'Dear {var:customerName}, your invoice is {var:invoiceId}.',
        );

        self::assertCount(2, $schema->variables);
        self::assertSame('customerName', $schema->variables[0]->path);
        self::assertSame('invoiceId', $schema->variables[1]->path);
        self::assertNull($schema->variables[0]->loopAlias);
    }

    #[Test]
    public function dedupesRepeatedVariables(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            '{var:name} appears once. {var:name} appears twice.',
        );

        self::assertCount(1, $schema->variables, 'Repeated identical references collapse.');
    }

    #[Test]
    public function capturesFiltersOnVariables(): void
    {
        $schema = $this->makeValidator()->validateAndExtract('{var:name uppercase}');

        self::assertCount(1, $schema->variables);
        self::assertSame(['uppercase'], $schema->variables[0]->filters);
    }

    #[Test]
    public function distinguishesSamePathWithDifferentFilters(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            '{var:name uppercase} and {var:name lowercase}',
        );

        self::assertCount(2, $schema->variables);
    }

    #[Test]
    public function extractsConstants(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            'Today is {const:currentDate} on {const:siteName}.',
        );

        self::assertCount(2, $schema->constants);
        self::assertSame('currentDate', $schema->constants[0]->name);
        self::assertSame('siteName', $schema->constants[1]->name);
    }

    #[Test]
    public function dedupesRepeatedConstants(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            '{const:siteName} {const:siteName} {const:siteName}',
        );

        self::assertCount(1, $schema->constants);
    }

    #[Test]
    public function extractsLoopAndTagsInnerVariablesWithLoopAlias(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            '{loop:items:item}{var:item.title} costs {var:item.price}\n{endloop}',
        );

        self::assertCount(1, $schema->loops);
        self::assertSame('items', $schema->loops[0]->path);
        self::assertSame('item', $schema->loops[0]->alias);

        self::assertCount(2, $schema->variables);
        foreach ($schema->variables as $variable) {
            self::assertSame('item', $variable->loopAlias);
        }
    }

    #[Test]
    public function extractsConditionalsWithPathAndNegate(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            '{if:hasOrder}Order placed.{endif}{unless:isPremium}Upgrade prompt.{endunless}',
        );

        self::assertCount(2, $schema->conditionals);
        self::assertSame('hasOrder', $schema->conditionals[0]->path);
        self::assertFalse($schema->conditionals[0]->negate);
        self::assertSame('isPremium', $schema->conditionals[1]->path);
        self::assertTrue($schema->conditionals[1]->negate);
    }

    #[Test]
    public function syntaxErrorThrowsTemplateValidationException(): void
    {
        $this->expectException(TemplateValidationException::class);
        $this->expectExceptionMessage('Template syntax error');

        // `{var:` without closing brace -- guaranteed parser failure.
        $this->makeValidator()->validateAndExtract('Body {var:unclosed');
    }

    #[Test]
    public function acceptsProseWithCodeSamples(): void
    {
        // Reproduces a strict-lexer regression report -- a design note
        // whose extracted text contains a PHP interface body with a
        // doc-block. The literal `{` before `/** @return ...` previously
        // put the lexer into tag mode and rejected `/`. Lexer now keeps
        // the brace literal because the following letters spell no
        // registered keyword.
        $text = 'EntitySchemaProviderInterface { /** @return EntityClassMetadata[] */'
            . ' public function getAllEntities(): array; }';

        $schema = $this->makeValidator()->validateAndExtract($text);

        // The text contains no real DTMPL tags, so the schema must be
        // empty rather than attempting to interpret the code body.
        self::assertSame([], $schema->variables);
        self::assertSame([], $schema->constants);
        self::assertSame([], $schema->loops);
        self::assertSame([], $schema->conditionals);
    }

    #[Test]
    public function acceptsJsonSnippetInTextContent(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            'Example payload: {"key": "value", "count": 42}.',
        );

        self::assertSame([], $schema->variables);
    }

    #[Test]
    public function suggestsNearestKeywordOnTypo(): void
    {
        $this->expectException(TemplateValidationException::class);
        $this->expectExceptionMessageMatches('/Did you mean `var`/');

        // `{vra:foo}` -- anagram of `var`. Strict lexer surfaces the
        // intended keyword instead of silently treating it as text.
        $this->makeValidator()->validateAndExtract('Hello {vra:name}.');
    }

    #[Test]
    public function nestedLoopsTrackInnermostAlias(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            '{loop:orders:order}{loop:order.items:line}{var:line.sku}{endloop}{endloop}',
        );

        self::assertCount(2, $schema->loops);
        self::assertCount(1, $schema->variables);
        // `line.sku` lives inside both loops, but the innermost alias
        // is `line` so the schema's loopAlias should be `line`.
        self::assertSame('line', $schema->variables[0]->loopAlias);
    }

    #[Test]
    public function variableOutsideLoopHasNoAlias(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            '{loop:items:item}{var:item.title}{endloop} and {var:total}',
        );

        $byPath = [];
        foreach ($schema->variables as $v) {
            $byPath[$v->path] = $v;
        }
        self::assertSame('item', $byPath['item.title']->loopAlias);
        self::assertNull($byPath['total']->loopAlias);
    }

    // -- `@alias` entity-reference syntax ----------------------------------

    #[Test]
    public function atAliasOnVariableSetsEntityType(): void
    {
        $schema = $this->makeValidator(['user' => stdClass::class])
            ->validateAndExtract('Hello {var:@user.email}');

        self::assertCount(1, $schema->variables);
        $v = $schema->variables[0];
        self::assertSame('@user', $v->path);
        self::assertSame(stdClass::class, $v->entityType);
        self::assertFalse($v->collection);
        self::assertNull($v->fields);
    }

    #[Test]
    public function multipleSubPathsCollapseToSingleEntityReference(): void
    {
        $schema = $this->makeValidator(['user' => stdClass::class])
            ->validateAndExtract(
                'Hi {var:@user.username}, your email is '
                . '{var:@user.email}, id={var:@user.id}.',
            );

        self::assertCount(1, $schema->variables);
        self::assertSame('@user', $schema->variables[0]->path);
    }

    #[Test]
    public function atAliasCoexistsWithRegularVariables(): void
    {
        $schema = $this->makeValidator(['user' => stdClass::class])
            ->validateAndExtract(
                'Dear {var:@user.email}, generated {var:metadata.timestamp}.',
            );

        $paths = array_map(static fn ($v) => $v->path, $schema->variables);
        sort($paths);
        self::assertSame(['@user', 'metadata.timestamp'], $paths);

        $byPath = [];
        foreach ($schema->variables as $v) {
            $byPath[$v->path] = $v;
        }
        self::assertSame(stdClass::class, $byPath['@user']->entityType);
        self::assertNull($byPath['metadata.timestamp']->entityType);
    }

    #[Test]
    public function unknownAliasRaisesValidationException(): void
    {
        $this->expectException(TemplateValidationException::class);
        $this->expectExceptionMessageMatches('/Unknown entity alias "@ghost"/');

        $this->makeValidator(['user' => stdClass::class])
            ->validateAndExtract('{var:@ghost.x}');
    }

    #[Test]
    public function unknownAliasErrorListsKnownAliases(): void
    {
        try {
            $this->makeValidator([
                'user' => stdClass::class,
                'media_asset' => stdClass::class,
            ])->validateAndExtract('{var:@ghost.x}');
            self::fail('Expected TemplateValidationException');
        } catch (TemplateValidationException $e) {
            self::assertStringContainsString('@user', $e->getMessage());
            self::assertStringContainsString('@media_asset', $e->getMessage());
        }
    }

    #[Test]
    public function withoutAResolverEveryAliasIsUnknown(): void
    {
        // A host that wires no resolver still gets a refusal, not a
        // silently untyped entry: the walk cannot vouch for a reference
        // it cannot resolve, and the message says the dictionary is empty.
        $validator = new DtmplSyntaxValidator(new Lexer(), new Parser());

        try {
            $validator->validateAndExtract('{var:@user.email}');
            self::fail('Expected TemplateValidationException');
        } catch (TemplateValidationException $e) {
            self::assertStringContainsString('Unknown entity alias "@user"', $e->getMessage());
            self::assertStringContainsString('(none registered)', $e->getMessage());
        }
    }

    #[Test]
    public function toArrayShapeIsApiFriendly(): void
    {
        $schema = $this->makeValidator()->validateAndExtract(
            'Hi {var:name}. {const:siteName}. {if:flag}yes{endif} {loop:items:i}{var:i.title}{endloop}',
        );

        $arr = $schema->toArray();
        self::assertArrayHasKey('variables', $arr);
        self::assertArrayHasKey('constants', $arr);
        self::assertArrayHasKey('loops', $arr);
        self::assertArrayHasKey('conditionals', $arr);
        self::assertSame('name', $arr['variables'][0]['path']);
    }

    /**
     * @param array<string, class-string> $aliasMap
     */
    private function makeValidator(array $aliasMap = []): DtmplSyntaxValidator
    {
        return new DtmplSyntaxValidator(
            lexer: new Lexer(),
            parser: new Parser(),
            aliases: new ArrayAliasResolver($aliasMap),
        );
    }
}
