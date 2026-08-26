<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Exception;

/**
 * A template named a filter that is not registered.
 *
 * Extends the package's own {@see RuntimeException} so a host can catch
 * every engine failure as one category ({@see TemplateException}). It
 * used to be a bare SPL `InvalidArgumentException`, which a caller could
 * only catch by also catching unrelated argument errors from its own code.
 *
 * Still an argument error in spirit -- hence the name of the thing that
 * was wrong, the filter, in the message.
 */
class UnknownFilterException extends RuntimeException
{
}
