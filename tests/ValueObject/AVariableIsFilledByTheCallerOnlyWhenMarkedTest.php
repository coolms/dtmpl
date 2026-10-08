<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\ValueObject;

use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Parser\Parser;
use CoolMS\Dtmpl\Validation\DtmplSyntaxValidator;
use CoolMS\Dtmpl\ValueObject\ContextSchema;
use CoolMS\Dtmpl\ValueObject\ContextSchemaVariable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "filled by the caller" mark: off unless an author switches it on, read
 * back only from a JSON `true`, and carried across a re-extraction of the
 * template's text for every path that still exists and is still a plain value.
 */
final class AVariableIsFilledByTheCallerOnlyWhenMarkedTest extends TestCase
{
    private const string TEMPLATE = 'Dear {var:name}, {var:name|upper} {var:city}.'
        . ' {loop:items:item}{var:item.title}{endloop}';

    #[Test]
    public function nothingExtractedFromATemplateIsMarked(): void
    {
        $schema = $this->extract(self::TEMPLATE);

        self::assertSame([], $schema->callerFillablePaths());
        foreach ($schema->toArray()['variables'] as $entry) {
            self::assertIsArray($entry);
            self::assertArrayNotHasKey('callerFillable', $entry);
        }
    }

    #[Test]
    public function aMarkSurvivesThePersistedShape(): void
    {
        $marked = new ContextSchema(variables: [
            new ContextSchemaVariable('name', callerFillable: true),
            new ContextSchemaVariable('city'),
            new ContextSchemaVariable('name', filters: ['upper'], callerFillable: true),
        ]);

        $persisted = $marked->toArray();
        $read = ContextSchema::fromArray($persisted);

        self::assertNotNull($read);
        self::assertSame(['name'], $read->callerFillablePaths());
        self::assertSame(true, $persisted['variables'][0]['callerFillable'] ?? null);
        self::assertArrayNotHasKey('callerFillable', $persisted['variables'][1]);
    }

    #[Test]
    public function onlyAJsonTrueMarksAVariable(): void
    {
        $read = ContextSchema::fromArray(['variables' => [
            ['path' => 'a', 'callerFillable' => 'true'],
            ['path' => 'b', 'callerFillable' => 1],
            ['path' => 'c', 'callerFillable' => 'yes'],
            ['path' => 'd', 'callerFillable' => true],
        ]]);

        self::assertNotNull($read);
        self::assertSame(['d'], $read->callerFillablePaths());
    }

    #[Test]
    public function aReExtractionKeepsTheMarksOfThePathsThatStillExist(): void
    {
        $before = new ContextSchema(variables: [
            new ContextSchemaVariable('name', callerFillable: true),
            new ContextSchemaVariable('item.title', loopAlias: 'item', callerFillable: true),
            new ContextSchemaVariable('removed', callerFillable: true),
            new ContextSchemaVariable('city'),
        ]);

        $after = $this->extract(self::TEMPLATE)->withCallerFillableCarriedFrom($before->toArray());

        self::assertSame(['name', 'item.title'], $after->callerFillablePaths());
        $byEntry = array_map(
            static fn (ContextSchemaVariable $v): string => sprintf(
                '%s=%s',
                $v->path,
                $v->callerFillable ? 'marked' : 'not',
            ),
            $after->variables,
        );
        // Both entries of `name` (one bare, one with a filter) carry the mark; `city` was never marked.
        self::assertSame(['name=marked', 'name=marked', 'city=not', 'item.title=marked'], $byEntry);
    }

    #[Test]
    public function aPathThatBecameAnEntityReferenceLosesItsMark(): void
    {
        $before = ['variables' => [['path' => 'customer', 'callerFillable' => true]]];
        $fresh = new ContextSchema(variables: [
            new ContextSchemaVariable('customer', entityType: 'Acme\\Shop\\Customer'),
        ]);

        self::assertSame([], $fresh->withCallerFillableCarriedFrom($before)->callerFillablePaths());
    }

    /**
     * However a reference is built -- read from a stored schema that marks it, constructed marked, or marked after --
     * it is never caller-filled, and the persisted shape never says it is.
     */
    #[Test]
    public function aReferenceIsNeverCallerFilledHoweverItIsBuilt(): void
    {
        $stored = ContextSchema::fromArray(['variables' => [
            ['path' => 'recipient', 'entityType' => 'Acme\\Shop\\Customer', 'callerFillable' => true],
            ['path' => 'letter.greeting', 'callerFillable' => true],
        ]]);
        self::assertNotNull($stored);
        self::assertSame(['letter.greeting'], $stored->callerFillablePaths());

        $built = new ContextSchemaVariable('recipient', entityType: 'Acme\\Shop\\Customer', callerFillable: true);
        self::assertFalse($built->callerFillable);
        self::assertFalse($built->withCallerFillable(true)->callerFillable);
        self::assertArrayNotHasKey('callerFillable', $built->withCallerFillable(true)->toArray());
    }

    #[Test]
    public function aPreviousSchemaWithoutMarksOrWithoutAShapeCarriesNothing(): void
    {
        $fresh = $this->extract(self::TEMPLATE);

        foreach ([null, 'not a schema', [], ['variables' => [['path' => 'name']]]] as $previous) {
            self::assertSame($fresh, $fresh->withCallerFillableCarriedFrom($previous));
        }
    }

    #[Test]
    public function theCarryOverKeepsTheRestOfTheSchema(): void
    {
        $fresh = $this->extract(
            '{const:siteName} {if:flag}{var:name}{endif} {loop:items:item}{var:item.title}{endloop}',
        );
        $before = ['variables' => [['path' => 'name', 'callerFillable' => true]]];

        $after = $fresh->withCallerFillableCarriedFrom($before);

        self::assertSame(['name'], $after->callerFillablePaths());
        self::assertEquals($fresh->constants, $after->constants);
        self::assertEquals($fresh->loops, $after->loops);
        self::assertEquals($fresh->conditionals, $after->conditionals);
        self::assertNotSame([], $after->constants);
        self::assertNotSame([], $after->loops);
        self::assertNotSame([], $after->conditionals);
    }

    private function extract(string $template): ContextSchema
    {
        return new DtmplSyntaxValidator(new Lexer(), new Parser())->validateAndExtract($template);
    }
}
