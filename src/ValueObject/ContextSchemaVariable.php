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
 *
 * `callerFillable` is the author's mark that whoever asks for the
 * render may supply this variable's value. It is off unless the author
 * switches it on: a template's text says what it reads, never who may
 * fill it, so nothing extracted from the text marks a variable. A host
 * that takes values from a caller refuses every variable not marked.
 * The mark belongs to the path, so a host marks every entry of a path
 * together, and {@see ContextSchema::withCallerFillableCarriedFrom()}
 * keeps it across a re-extraction. Two loops that reuse an alias give
 * their items the same paths, and so share a mark: the caller fills a
 * path, whichever loop reads it.
 *
 * An entity reference (one that names an `entityType`) is never
 * caller-filled, however it is built: from a stored schema, by a host,
 * or by hand. A reference is a read of a record, which the host checks
 * against the caller's access, and never a value the caller types in.
 * So the mark is dropped here, in the one place every variable is
 * built, and no reader has to remember to drop it.
 */
final readonly class ContextSchemaVariable
{
    public bool $callerFillable;

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
        bool $callerFillable = false,
    ) {
        $this->callerFillable = $callerFillable && null === $entityType;
    }

    public function withCallerFillable(bool $callerFillable): self
    {
        return new self(
            path: $this->path,
            filters: $this->filters,
            loopAlias: $this->loopAlias,
            entityType: $this->entityType,
            collection: $this->collection,
            fields: $this->fields,
            callerFillable: $callerFillable,
        );
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
        // Emit the extended fields only when non-default -- keeps the
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
        if ($this->callerFillable) {
            $out['callerFillable'] = true;
        }

        return $out;
    }
}
