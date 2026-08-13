<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\ValueObject;

/**
 * Lightweight schema describing the DTMPL surface area of a document
 * template. F.13a populates this on upload by lexing + parsing the
 * extracted DOCX text and walking the AST; downstream UI (Document
 * Library detail panel, future "fill template" form) renders the
 * schema as hints.
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
     * that pre-date Phase 2 (the new `entityType` / `collection` /
     * `fields` keys default to their pre-Phase-2 values). Returns
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
            );
        }

        // Constants / loops / conditionals are not needed by the
        // render-time hydrator -- keep the parser lean. Re-hydration
        // for the validator's purposes goes through its own path.
        return new self(variables: $variables);
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
