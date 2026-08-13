<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DtmplEngineTest extends TestCase
{
    private DtmplEngine $engine;

    public function testSimpleText(): void
    {
        $result = $this->engine->render('Hello, World!');
        $this->assertSame('Hello, World!', $result);
    }

    public function testSimpleVariable(): void
    {
        $result = $this->engine->render('{var:name}', ['name' => 'John']);
        $this->assertSame('John', $result);
    }

    public function testNestedVariable(): void
    {
        $result = $this->engine->render('{var:user.name}', [
            'user' => ['name' => 'Alice'],
        ]);
        $this->assertSame('Alice', $result);
    }

    public function testDeepNestedVariable(): void
    {
        $result = $this->engine->render('{var:user.profile.firstName}', [
            'user' => [
                'profile' => [
                    'firstName' => 'Bob',
                ],
            ],
        ]);
        $this->assertSame('Bob', $result);
    }

    public function testVariableWithDefault(): void
    {
        $result = $this->engine->render('{var:missing:default=`Guest`}');
        $this->assertSame('Guest', $result);
    }

    public function testVariableWithFilter(): void
    {
        $result = $this->engine->render('{var:name uppercase}', ['name' => 'john']);
        $this->assertSame('JOHN', $result);
    }

    public function testVariableWithMultipleFilters(): void
    {
        $result = $this->engine->render('{var:text php.trim uppercase}', ['text' => '  hello  ']);
        $this->assertSame('HELLO', $result);
    }

    public function testPHPFilter(): void
    {
        $result = $this->engine->render('{var:name php.strtoupper}', ['name' => 'alice']);
        $this->assertSame('ALICE', $result);
    }

    public function testTruncateFilter(): void
    {
        $result = $this->engine->render('{var:text truncate:10}', [
            'text' => 'This is a very long text that should be truncated',
        ]);
        $this->assertSame('This is a ...', $result);
    }

    public function testSimpleLoop(): void
    {
        $result = $this->engine->render('{loop:items}{var:item.name}{endloop}', [
            'items' => [
                ['name' => 'A'],
                ['name' => 'B'],
                ['name' => 'C'],
            ],
        ]);
        $this->assertSame('ABC', $result);
    }

    public function testLoopWithSeparator(): void
    {
        $result = $this->engine->render('{loop:items split=`, `}{var:item.name}{endloop}', [
            'items' => [
                ['name' => 'Apple'],
                ['name' => 'Banana'],
                ['name' => 'Cherry'],
            ],
        ]);
        $this->assertSame('Apple, Banana, Cherry', $result);
    }

    public function testLoopWithIndex(): void
    {
        $result = $this->engine->render('{loop:items}{var:loop.index1}. {var:item.name} {endloop}', [
            'items' => [
                ['name' => 'First'],
                ['name' => 'Second'],
            ],
        ]);
        $this->assertSame('1. First 2. Second ', $result);
    }

    public function testLoopOddFilter(): void
    {
        $result = $this->engine->render('{loop:items odd}{var:item.name}{endloop}', [
            'items' => [
                ['name' => 'A'], // index 0 - even
                ['name' => 'B'], // index 1 - odd
                ['name' => 'C'], // index 2 - even
                ['name' => 'D'], // index 3 - odd
            ],
        ]);
        $this->assertSame('BD', $result);
    }

    public function testLoopEvenFilter(): void
    {
        $result = $this->engine->render('{loop:items even}{var:item.name}{endloop}', [
            'items' => [
                ['name' => 'A'], // index 0 - even
                ['name' => 'B'], // index 1 - odd
                ['name' => 'C'], // index 2 - even
            ],
        ]);
        $this->assertSame('AC', $result);
    }

    public function testLoopAssociativeArray(): void
    {
        // Associative arrays expose item.key and item.value per iteration.
        $result = $this->engine->render(
            '{loop:attrs} {var:item.key}="{var:item.value}"{endloop}',
            ['attrs' => ['class' => 'form-control', 'id' => 'email']],
        );
        $this->assertSame(' class="form-control" id="email"', $result);
    }

    public function testLoopAssociativeArrayWithOddFilter(): void
    {
        // odd/even filters count iteration index (0-based), not the key.
        $result = $this->engine->render(
            '{loop:attrs odd}{var:item.key}:{var:item.value} {endloop}',
            ['attrs' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']],
        );
        // Indices 1 and 3 (odd) → keys 'b' and 'd'
        $this->assertSame('b:2 d:4 ', $result);
    }

    public function testLoopAssociativeArrayWithSplit(): void
    {
        $result = $this->engine->render(
            '{loop:attrs split=`; `}{var:item.key}={var:item.value}{endloop}',
            ['attrs' => ['class' => 'form-control', 'id' => 'email']],
        );
        $this->assertSame('class=form-control; id=email', $result);
    }

    public function testNestedLoops(): void
    {
        $template = '{loop:categories:cat}{var:cat.name}: {loop:cat.products:prod}{var:prod.name}, {endloop}|{endloop}';
        $result = $this->engine->render($template, [
            'categories' => [
                [
                    'name' => 'Fruits',
                    'products' => [
                        ['name' => 'Apple'],
                        ['name' => 'Banana'],
                    ],
                ],
                [
                    'name' => 'Veggies',
                    'products' => [
                        ['name' => 'Carrot'],
                    ],
                ],
            ],
        ]);
        $this->assertSame('Fruits: Apple, Banana, |Veggies: Carrot, |', $result);
    }

    public function testDefineSimpleVariableNumber(): void
    {
        $result = $this->engine->render('{def:test=18}{var:test}');
        $this->assertSame('18', $result);
    }

    public function testDefineSimpleVariableString(): void
    {
        $result = $this->engine->render('{def:test=`Hello, World!`}{var:test}');
        $this->assertSame('Hello, World!', $result);
    }

    public function testDefineNestedVariableString(): void
    {
        // test = ['index' => 'Hello, world!'] -- associative: item is ['key'=>..., 'value'=>...]
        $template = <<<DTMPL
            {def:test.index=`Hello, world!`}
            {loop:test}
            {var:loop.index1}). {var:loop.key} - {var:item.value}
            {endloop}
            DTMPL;
        $result = $this->engine->render($template);
        $this->assertStringContainsString('1). index - Hello, world!', $result);
    }

    public function testDefineSimpleArray(): void
    {
        $template = '{def:items=[`apple`, `banana`, `cherry`]}{loop:items}{var:item} {endloop}';
        $result = $this->engine->render($template);
        $this->assertSame('apple banana cherry ', $result);
    }

    public function testDefineArrayWithNumbers(): void
    {
        $template = '{def:numbers=[1, 2, 3, 4, 5]}{loop:numbers}{var:item}{endloop}';
        $result = $this->engine->render($template);
        $this->assertSame('12345', $result);
    }

    public function testDefineAssociativeArray(): void
    {
        $template = <<<DTMPL
            {def:user=[name: `Alice`, age: 30, city: `NYC`]}
            Name: {var:user.name}
            Age: {var:user.age}
            City: {var:user.city}
            DTMPL;
        $result = $this->engine->render($template);
        $this->assertStringContainsString('Name: Alice', $result);
        $this->assertStringContainsString('Age: 30', $result);
        $this->assertStringContainsString('City: NYC', $result);
    }

    public function testDefineNestedArray(): void
    {
        $template = <<<DTMPL
            {def:data=[
                name: `Product`,
                tags: [`new`, `featured`, `sale`],
                price: 99.99
            ]}
            {var:data.name}: {var:data.price}
            Tags: {loop:data.tags}{var:item} {endloop}
            DTMPL;
        $result = $this->engine->render($template);
        $this->assertStringContainsString('Product: 99.99', $result);
        $this->assertStringContainsString('Tags: new featured sale', $result);
    }

    public function testDefineDeeplyNestedArray(): void
    {
        $template = <<<DTMPL
            {def:company=[
                name: `TechCorp`,
                departments: [
                    engineering: [
                        name: `Engineering`,
                        employees: [`Alice`, `Bob`, `Charlie`]
                    ],
                    sales: [
                        name: `Sales`,
                        employees: [`Dave`, `Eve`]
                    ]
                ]
            ]}
            Company: {var:company.name}
            Eng Dept: {var:company.departments.engineering.name}
            Engineers: {loop:company.departments.engineering.employees}{var:item} {endloop}
            DTMPL;
        $result = $this->engine->render($template);
        $this->assertStringContainsString('Company: TechCorp', $result);
        $this->assertStringContainsString('Eng Dept: Engineering', $result);
        $this->assertStringContainsString('Engineers: Alice Bob Charlie', $result);
    }

    public function testDefineMixedArray(): void
    {
        $template = <<<DTMPL
            {def:mixed=[
                `first item`,
                key1: `value1`,
                `second item`,
                nested: [`a`, `b`, `c`],
                `third item`
            ]}
            {loop:mixed}{var:item} {endloop}
            DTMPL;
        $result = $this->engine->render($template);
        $this->assertStringContainsString('first item', $result);
        $this->assertStringContainsString('value1', $result);
        $this->assertStringContainsString('second item', $result);
    }

    public function testDefineArrayWithBooleans(): void
    {
        $template = '{def:flags=[true, false, true]}{loop:flags}{var:item} {endloop}';
        $result = $this->engine->render($template);
        $this->assertSame('true false true ', $result);
    }

    public function testDefineEmptyArray(): void
    {
        $template = '{def:empty=[]}{var:empty count}';
        $result = $this->engine->render($template);
        $this->assertSame('0', $result);
    }

    public function testDefineArrayWithTrailingComma(): void
    {
        $template = '{def:items=[`a`, `b`, `c`,]}{loop:items}{var:item}{endloop}';
        $result = $this->engine->render($template);
        $this->assertSame('abc', $result);
    }

    public function testDefineMultipleArrays(): void
    {
        $template = <<<DTMPL
            {def:colors=[`red`, `green`, `blue`]}
            {def:sizes=[`S`, `M`, `L`, `XL`]}
            Colors: {loop:colors}{var:item} {endloop}
            Sizes: {loop:sizes}{var:item} {endloop}
            DTMPL;
        $result = $this->engine->render($template);
        $this->assertStringContainsString('Colors: red green blue', $result);
        $this->assertStringContainsString('Sizes: S M L XL', $result);
    }

    public function testDefineArrayInContext(): void
    {
        $template = <<<DTMPL
            {def:products=[
                [name: `Laptop`, price: 999],
                [name: `Mouse`, price: 25],
                [name: `Keyboard`, price: 75]
            ]}
            {loop:products:product}
            {var:product.name}: \${var:product.price}
            {endloop}
            DTMPL;
        $result = $this->engine->render($template);
        $this->assertStringContainsString('Laptop: $999', $result);
        $this->assertStringContainsString('Mouse: $25', $result);
        $this->assertStringContainsString('Keyboard: $75', $result);
    }

    public function testSimpleConditional(): void
    {
        $result = $this->engine->render('{if:isPremium}Premium{endif}', ['isPremium' => true]);
        $this->assertSame('Premium', $result);
    }

    public function testConditionalFalse(): void
    {
        $result = $this->engine->render('{if:isPremium}Premium{endif}', ['isPremium' => false]);
        $this->assertSame('', $result);
    }

    public function testUnlessConditional(): void
    {
        $result = $this->engine->render('{unless:isVerified}Please verify{endunless}', [
            'isVerified' => false,
        ]);
        $this->assertSame('Please verify', $result);
    }

    public function testConditionalWithNestedVariable(): void
    {
        $result = $this->engine->render('{if:user.isPremium}Welcome, Premium!{endif}', [
            'user' => ['isPremium' => true],
        ]);
        $this->assertSame('Welcome, Premium!', $result);
    }

    public function testConditionalEqualityLiteral(): void
    {
        $result = $this->engine->render('{if:field.type=`fieldset`}yes{endif}', [
            'field' => ['type' => 'fieldset'],
        ]);
        $this->assertSame('yes', $result);
    }

    public function testConditionalEqualityLiteralFalse(): void
    {
        $result = $this->engine->render('{if:field.type=`fieldset`}yes{endif}', [
            'field' => ['type' => 'text'],
        ]);
        $this->assertSame('', $result);
    }

    public function testConditionalEqualityVariable(): void
    {
        $result = $this->engine->render('{if:a=b}match{endif}', [
            'a' => 'hello',
            'b' => 'hello',
        ]);
        $this->assertSame('match', $result);
    }

    public function testUnlessEqualityLiteral(): void
    {
        $result = $this->engine->render('{unless:field.type=`fieldset`}not fieldset{endunless}', [
            'field' => ['type' => 'text'],
        ]);
        $this->assertSame('not fieldset', $result);
    }

    public function testComplexTemplate(): void
    {
        $template = <<<DTMPL
            Hello {var:user.name:default=`Guest` capitalize}!

            {if:user.isPremium}
            You have premium access.
            {endif}

            Your orders:
            {loop:orders:order}
            Order #{var:order.id}: {var:order.total}
            {endloop}
            DTMPL;

        $result = $this->engine->render($template, [
            'user' => ['name' => 'alice', 'isPremium' => true],
            'orders' => [
                ['id' => 1, 'total' => 100],
                ['id' => 2, 'total' => 200],
            ],
        ]);

        $this->assertStringContainsString('Hello Alice!', $result);
        $this->assertStringContainsString('You have premium access.', $result);
        $this->assertStringContainsString('Order #1: 100', $result);
        $this->assertStringContainsString('Order #2: 200', $result);
    }

    public function testEscapeFilter(): void
    {
        $result = $this->engine->render('{var:html escape}', [
            'html' => '<script>alert("xss")</script>',
        ]);
        $this->assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $result);
    }

    public function testCountFilter(): void
    {
        $result = $this->engine->render('{var:items count}', [
            'items' => ['a', 'b', 'c'],
        ]);
        $this->assertSame('3', $result);
    }

    public function testJoinFilter(): void
    {
        $result = $this->engine->render('{var:tags join:`, `}', [
            'tags' => ['php', 'symfony', 'doctrine'],
        ]);
        $this->assertSame('php, symfony, doctrine', $result);
    }

    public function testDateFilter(): void
    {
        $date = new DateTimeImmutable('2024-01-15 10:30:00');
        $result = $this->engine->render('{var:date date:`Y-m-d`}', ['date' => $date]);
        $this->assertSame('2024-01-15', $result);
    }

    public function testEmptyLoop(): void
    {
        $result = $this->engine->render('{loop:items}{var:item.items.name}{endloop}', ['items' => []]);
        $this->assertSame('', $result);
    }

    public function testMissingVariable(): void
    {
        $result = $this->engine->render('{var:missing}');
        $this->assertSame('', $result);
    }

    public function testBooleanTrue(): void
    {
        $result = $this->engine->render('{var:flag}', ['flag' => true]);
        $this->assertSame('true', $result);
    }

    public function testBooleanFalse(): void
    {
        $result = $this->engine->render('{var:flag}', ['flag' => false]);
        $this->assertSame('false', $result);
    }

    public function testNumberValue(): void
    {
        $result = $this->engine->render('{var:count}', ['count' => 42]);
        $this->assertSame('42', $result);
    }

    public function testFloatValue(): void
    {
        $result = $this->engine->render('{var:price}', ['price' => 19.99]);
        $this->assertSame('19.99', $result);
    }

    public function testEscapedBraces(): void
    {
        $result = $this->engine->render('Use {{braces}} for variables');
        $this->assertSame('Use {braces} for variables', $result);
    }

    public function testCustomFilter(): void
    {
        $this->engine->registerFilter('double', fn ($v) => $v * 2);
        $result = $this->engine->render('{var:number double}', ['number' => 5]);
        $this->assertSame('10', $result);
    }

    public function testValidateValid(): void
    {
        $isValid = $this->engine->validate('{var:name}');
        $this->assertTrue($isValid);
    }

    public function testValidateInvalid(): void
    {
        $isValid = $this->engine->validate('{var:name');
        $this->assertFalse($isValid);
    }

    public function testCacheCompilation(): void
    {
        $template = '{var:name}';

        // First compilation
        $result1 = $this->engine->render($template, ['name' => 'Test']);

        // Second compilation (should use cache)
        $result2 = $this->engine->render($template, ['name' => 'Test']);

        $this->assertSame($result1, $result2);

        $stats = $this->engine->getCacheStats();
        $this->assertGreaterThan(0, $stats['in_memory_count']);
    }

    public function testClearCache(): void
    {
        $this->engine->render('{var:name}', ['name' => 'Test']);
        $this->assertGreaterThan(0, $this->engine->getCacheStats()['in_memory_count']);

        $this->engine->clearCache();
        $this->assertSame(0, $this->engine->getCacheStats()['in_memory_count']);
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }
}
