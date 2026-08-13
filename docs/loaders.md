# Loaders

A loader turns a template name into template source. What
``{include:`partials/header`}`` resolves to depends entirely on which loaders
are installed and in what order.

## The chain

`CompositeTemplateLoader` holds the chain and asks each loader in turn. The
first one that `supports()` the path wins.

```php
interface PrioritizedLoaderInterface extends TemplateLoaderInterface
{
    public function getPriority(): int;
    public function supports(string $path, string $basePath = ''): bool;
}
```

Higher priority is consulted first. `FilesystemTemplateLoader` sits at priority
`0`, so anything that should override files on disk takes a positive number, and
anything that is a last resort takes a negative one.

`FallbackTemplateLoader` is pinned at the very end regardless of its priority.
It exists so a missing template produces a useful error rather than a blank
page, and it must stay last or it would answer for names another loader could
have served.

## Adding one

```php
final class DatabaseTemplateLoader implements PrioritizedLoaderInterface
{
    public function getPriority(): int
    {
        return 10;   // ahead of the filesystem
    }

    public function supports(string $path, string $basePath = ''): bool
    {
        return str_starts_with($path, 'db:');
    }

    public function load(string $path, string $basePath = ''): string
    {
        return $this->repository->sourceFor(substr($path, 3));
    }
}
```

Under Symfony the bundle tags every `TemplateLoaderInterface` implementation
`dtmpl.template_loader` automatically, and a compiler pass sorts the chain by
priority. To pin a specific priority for your own class, call
`registerForAutoconfiguration` on the concrete class in your own bundle's
`build()`.

Without Symfony, construct the composite yourself:

```php
$loader = new CompositeTemplateLoader([
    new DatabaseTemplateLoader($repository),
    new FilesystemTemplateLoader('/path/to/templates'),
]);

$engine = new DtmplEngine(loader: $loader);
```

## `supports()` is the whole contract

A loader that returns `true` too eagerly silently shadows every loader behind
it, and the failure looks like "my template changes aren't showing up" rather
than like an error. Make `supports()` as narrow as the loader actually is -- a
prefix, an extension, a known directory -- and never `return true`.

## Relative includes

An include resolves against the directory of the template doing the including,
which is why `load()` takes `$basePath`. A loader that ignores it will work for
top-level templates and break for partials that include their neighbours.
