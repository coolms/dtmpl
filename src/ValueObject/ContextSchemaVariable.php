<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\ValueObject;

/**
 * One `{var:foo.bar filter1:`arg`}` reference found in a template.
 * `path` matches the dotted accessor handed to DTMPL's Context
 * resolver; `filters` lists the filter names in order so the UI can
 * surface them without re-parsing the source. `loopAlias` is non-null
 * when the variable was found inside a `{loop:items:item}` body and
 * the path's first segment is the alias -- this lets UI render the
 * variable under its loop without faking type inference.
 *
 * Later versions added three optional fields:
 *  - `entityType` -- FQCN of the referenced domain entity. When set
 *    the Generate dialog renders a `<cms-entity-picker>` instead of
 *    a scalar text input, and the render-time
 *    `EntityHydratingContributor` swaps the persisted id for a
 *    flattened entity projection.
 *  - `collection` -- `true` for list-of-entities references usable
 *    with `{loop:varName}`. The persisted value is a list of ids.
 *  - `fields` -- explicit allow-list of fields to expose; `null`
 *    hands the call to the resolver's default exposure rules.
 *
 * All three are backward-compatible: schemas constructed without
 * these arguments render exactly as before.
 */
final readonly class ContextSchemaVariable
{
    /**
     * @param list<string>      $filters
     * @param list<string>|null $fields
     */
    public function __construct(
        public string $path,
        public array $filters = [],
        public ?string $loopAlias = null,
        public ?string $entityType = null,
        public bool $collection = false,
        public ?array $fields = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'path' => $this->path,
            'filters' => $this->filters,
            'loopAlias' => $this->loopAlias,
        ];
        // Emit Phase 2 fields only when non-default -- keeps the
        // persisted JSON narrow for legacy schemas, and lets the
        // hydration parser cleanly distinguish "feature unused"
        // from "feature opted in".
        if (null !== $this->entityType) {
            $out['entityType'] = $this->entityType;
        }
        if ($this->collection) {
            $out['collection'] = true;
        }
        if (null !== $this->fields) {
            $out['fields'] = $this->fields;
        }

        return $out;
    }
}
