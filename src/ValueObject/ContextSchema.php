<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\ValueObject;

/**
 * Lightweight schema describing the DTMPL surface area of a document
 * template. `Validation\DtmplSyntaxValidator` populates it by lexing and
 * parsing the template text and walking the AST; a host's UI (a template
 * library's detail panel, a "fill template" form) renders the schema as
 * hints.
 *
 * The schema is deliberately *lightweight*:
 *   - Variable paths only, no PHP type inference.
 *   - Filters captured verbatim so authors can spot
 *     `{var:price currency:USD}` style usage at a glance.
 *   - Loop / conditional control flow recorded so UI can render
 *     "this template iterates over `items`" instead of dumping
 *     `items.title` into a flat list and confusing the user.
 *
 * Strict typing (required vs optional, scalar vs object) is explicitly
 * deferred to a follow-up phase.
 */
final readonly class ContextSchema
{
    /**
     * @param list<ContextSchemaVariable>    $variables
     * @param list<ContextSchemaConstant>    $constants
     * @param list<ContextSchemaLoop>        $loops
     * @param list<ContextSchemaConditional> $conditionals
     */
    public function __construct(
        public array $variables = [],
        public array $constants = [],
        public array $loops = [],
        public array $conditionals = [],
    ) {
    }

    /**
     * Reconstruct from the persisted `DocumentTemplate.contextSchema`
     * array shape (JSON column round-trip). Tolerant of legacy rows
     * that pre-date the schema extension (the new `entityType` /
     * `collection` / `fields` / `callerFillable` keys default to their older
     * values, and a row without `callerFillable` marks nothing). Returns
     * `null` when the input is null/empty so consumers can
     * short-circuit on templates that ship no schema at all.
     *
     * @param array<string, mixed>|null $data
     */
    public static function fromArray(?array $data): ?self
    {
        if (null === $data || [] === $data) {
            return null;
        }
        $variables = [];
        foreach (($data['variables'] ?? []) as $v) {
            if (!is_array($v) || !isset($v['path']) || !is_string($v['path'])) {
                continue;
            }
            /** @var list<string> $filters */
            $filters = is_array($v['filters'] ?? null) ? array_values(array_filter($v['filters'], 'is_string')) : [];
            $loopAlias = is_string($v['loopAlias'] ?? null) ? $v['loopAlias'] : null;
            $entityType = isset($v['entityType']) && is_string($v['entityType']) && '' !== $v['entityType']
                ? $v['entityType']
                : null;
            $collection = (bool) ($v['collection'] ?? false);
            $fields = null;
            if (isset($v['fields']) && is_array($v['fields'])) {
                /** @var list<string> $fields */
                $fields = array_values(array_filter($v['fields'], 'is_string'));
            }
            $variables[] = new ContextSchemaVariable(
                path: $v['path'],
                filters: $filters,
                loopAlias: $loopAlias,
                entityType: $entityType,
                collection: $collection,
                fields: $fields,
                // Only a JSON `true` marks a variable. This mark lets a caller supply the value, so a
                // `"true"` or a `1` that some writer produced stays unmarked rather than being read as
                // permission.
                callerFillable: true === ($v['callerFillable'] ?? false),
            );
        }

        // Constants / loops / conditionals are not needed by the
        // render-time hydrator -- keep the parser lean. Re-hydration
        // for the validator's purposes goes through its own path.
        return new self(variables: $variables);
    }

    /**
     * The paths an author marked as filled by the caller, each once, in the
     * order they first appear. A host that takes values from a caller accepts
     * these and refuses every other path.
     *
     * @return list<string>
     */
    public function callerFillablePaths(): array
    {
        $paths = [];
        foreach ($this->variables as $variable) {
            if ($variable->callerFillable) {
                $paths[$variable->path] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * This schema, with the author's "filled by the caller" marks carried over
     * from the schema it replaces.
     *
     * A host re-extracts the schema from the template's text after every
     * content change, and the text never says which variables a caller may
     * fill: that is the author's mark, kept beside the text. A host that
     * stored the fresh extraction as it is would unmark every variable at the
     * template's next edit, and no one would be told. So each variable whose
     * path the previous schema marked is marked again, every entry of that
     * path included.
     *
     * Two things are not carried:
     * - A path that no longer exists. Its mark goes with it.
     * - A path that is now an entity reference (it names an `entityType`). A
     *   reference is a read of a record, which the host checks for the
     *   caller's access. It is never a value the caller types in.
     *
     * `$previous` is the persisted shape that {@see toArray()} wrote. Anything
     * else, null included, carries nothing.
     */
    public function withCallerFillableCarriedFrom(mixed $previous): self
    {
        $before = is_array($previous) ? self::fromArray($previous) : null;
        $marked = array_flip($before?->callerFillablePaths() ?? []);
        if ([] === $marked) {
            return $this;
        }

        $variables = [];
        foreach ($this->variables as $variable) {
            // A reference stays unmarked: the variable itself refuses the mark.
            $variables[] = isset($marked[$variable->path]) ? $variable->withCallerFillable(true) : $variable;
        }

        return new self(
            variables: $variables,
            constants: $this->constants,
            loops: $this->loops,
            conditionals: $this->conditionals,
        );
    }

    /**
     * Persist-friendly array shape. Stored on
     * `DocumentTemplate.contextSchema` (JSON column).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'variables' => array_map(static fn (ContextSchemaVariable $v): array => $v->toArray(), $this->variables),
            'constants' => array_map(static fn (ContextSchemaConstant $c): array => $c->toArray(), $this->constants),
            'loops' => array_map(static fn (ContextSchemaLoop $l): array => $l->toArray(), $this->loops),
            'conditionals' => array_map(static fn (ContextSchemaConditional $c): array => $c->toArray(), $this->conditionals),
        ];
    }
}
