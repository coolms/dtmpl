<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

/**
 * The printed variables a render found with no value, collected as the render runs (opt-in, see
 * {@see \CoolMS\Dtmpl\DtmplEngine::withMissingValues()}).
 *
 * A variable is recorded when the render prints it -- `{var:path}`, in the branch actually taken, in every loop item,
 * inside an include -- and its value is null, an empty or blank string, or an empty list, with no default of its own
 * (an inline default, or a `default` filter). An assignment is never recorded: it sets a value, it does not print one
 * the context had to hold. A branch not taken prints nothing and records nothing.
 *
 * The render itself is unchanged: a missing value still prints as before. What to do about the record is the host's.
 * One collector belongs to one render; it is not cleared between renders.
 *
 * @phpstan-type LoopItem array{path: string, index: int, key: int|string}
 * @phpstan-type Missing array{path: string, template: string, loops: list<LoopItem>}
 */
final class MissingValues
{
    /** @var list<Missing> */
    private array $missing = [];

    /**
     * @param list<LoopItem> $loops the loop items around it, outermost first
     */
    public function record(string $path, string $template, array $loops): void
    {
        $this->missing[] = ['path' => $path, 'template' => $template, 'loops' => $loops];
    }

    /**
     * Every printed variable found with no value, in the order the render printed them; a variable printed in three
     * loop items with no value is three entries.
     *
     * @return list<Missing>
     */
    public function all(): array
    {
        return $this->missing;
    }

    public function isEmpty(): bool
    {
        return 0 === count($this->missing);
    }
}
