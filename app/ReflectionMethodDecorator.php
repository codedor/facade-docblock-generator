<?php

namespace App;

use ReflectionClass;

/**
 * @mixin \ReflectionMethod
 */
class ReflectionMethodDecorator
{
    /**
     * @param  \ReflectionMethod  $method
     * @param  class-string  $sourceClass
     */
    public function __construct(private $method, private $sourceClass)
    {
        //
    }

    /**
     * @param  string  $name
     * @param  array  $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->method->{$name}(...$arguments);
    }

    /**
     * @return \ReflectionMethod
     */
    public function toBase()
    {
        return $this->method;
    }

    /**
     * @return \ReflectionClass
     */
    public function sourceClass()
    {
        return new ReflectionClass($this->sourceClass);
    }
}
