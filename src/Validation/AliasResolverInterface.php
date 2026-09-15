<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Validation;

/**
 * The dictionary behind the `@alias` prefix of a variable path.
 *
 * `{var:@user.email}` names an entity by an author-facing alias rather
 * than by class. The validator asks this port which class the alias
 * stands for and stamps the answer onto the schema entry as
 * `entityType`. What the aliases are, and where they come from, is the
 * host's business: the engine declares the question only, and a host
 * answers it (coolms/entity-bundle does, from its entity alias
 * registry). Without a provider every alias is unknown.
 *
 * Implementations are consulted once per validated template and keep
 * no state between calls.
 */
interface AliasResolverInterface
{
    /**
     * The class an alias stands for, or null when the alias is unknown.
     *
     * @return class-string|null
     */
    public function resolve(string $alias): ?string;

    /**
     * Every alias this resolver knows, without the `@`. Read only to
     * word the refusal for an unknown alias, so the author can correct
     * a typo without a code search.
     *
     * @return list<string>
     */
    public function knownAliases(): array;
}
