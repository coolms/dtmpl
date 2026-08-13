# Widgets

A widget is how an application offers real functionality to template authors
without letting them write PHP.

```
{widget:comments}
{widget:document:my-template-slug}
```

The engine resolves the name to a registered renderer, calls it with the current
context, and inserts what comes back.

## Writing one

```php
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use Stringable;

final class CommentsWidgetRenderer implements WidgetRendererInterface
{
    public string $key { get => 'comments'; }

    public function __invoke(array $context, array $params = []): ?Stringable
    {
        return new WidgetResult([
            'comments' => $this->repository->findFor($params['id'] ?? null),
        ]);
    }
}
```

`$key` is the name authors type. `__invoke` receives the render context and the
tag's parameters, and returns anything `Stringable` -- or `null` to render
nothing at all, which is the honest answer when a widget has nothing to show.

Under Symfony the bundle tags every implementation automatically; there is
nothing to register.

## Returning data rather than markup

Returning a string from `__invoke` means the widget owns its own HTML, which
puts markup inside PHP and out of the designer's reach.

`WidgetResult` is the alternative. It is `Stringable`, `ArrayAccess` and
`IteratorAggregate` at once, so the same return value can be used three ways:

```
{widget:comments}                     <- renders the widget's partial
{var:comments.total}                  <- read a field off the result
{loop:comments}...{endloop}           <- iterate it
```

The partial a widget renders through is configured by the host, not hard-coded
in the renderer -- so a theme can restyle a widget without touching PHP.

## Parameters

```
{widget:document:my-slug}
{widget:comments id=`42`}
```

Everything after the widget name reaches `__invoke` as `$params`. Values are
escaped like any other output; a widget that needs raw HTML has to opt in
deliberately.

## Two rules worth keeping

**Return `null`, don't return an empty string.** Null lets the caller decide
whether the surrounding markup should appear at all. An empty string leaves an
empty wrapper behind.

**Don't query per element.** A widget runs once per occurrence, but a widget
inside a `{loop:}` runs once per iteration. Load in bulk before the loop, or the
page turns into N queries -- the same trap as a filter that touches the database.
