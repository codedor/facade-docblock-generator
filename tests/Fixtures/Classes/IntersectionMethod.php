<?php

namespace Tests\Fixtures\Classes;

use Countable;
use Stringable;
use Traversable;

class IntersectionMethod
{
    public function foo((Countable&Stringable) | Traversable $val)
    {
        return $val;
    }
}
