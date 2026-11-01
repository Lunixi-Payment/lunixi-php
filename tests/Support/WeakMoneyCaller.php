<?php

// Deliberately no strict_types: SDK admission must reject PHP's coercible scalars.
namespace Lunixi\Sdk\Tests\Support;

final class WeakMoneyCaller
{
    /** @param class-string $class @param array<mixed> $arguments @return object */
    public static function construct(string $class, array $arguments)
    {
        return new $class(...$arguments);
    }

    /** @param object $target @param array<mixed> $arguments @return mixed */
    public static function invoke($target, string $method, array $arguments)
    {
        return $target->{$method}(...$arguments);
    }

    /** @param class-string $class @param array<mixed> $arguments @return mixed */
    public static function invokeStatic(string $class, string $method, array $arguments)
    {
        return $class::{$method}(...$arguments);
    }
}
