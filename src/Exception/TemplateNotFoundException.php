<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Exception;

class TemplateNotFoundException extends TemplateException
{
    public function __construct(string $path, string $basePath = '')
    {
        $context = '' !== $basePath ? " (base: '$basePath')" : '';
        parent::__construct("Template not found: '$path'$context");
    }
}
