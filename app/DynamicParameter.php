<?php

namespace App;

use Illuminate\Support\Str;

class DynamicParameter
{
    /**
     * @param  string  $definition
     */
    public function __construct(private $definition)
    {
        //
    }

    /**
     * @return string
     */
    public function getName()
    {
        return Str::of($this->definition)
            ->after('$')
            ->before(' ')
            ->toString();
    }

    /**
     * @return bool
     */
    public function isOptional()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isVariadic()
    {
        return Str::contains($this->definition, " ...\${$this->getName()}");
    }

    /**
     * @return bool
     */
    public function isDefaultValueAvailable()
    {
        return true;
    }

    /**
     * @return null
     */
    public function getDefaultValue()
    {
        return null;
    }
}
