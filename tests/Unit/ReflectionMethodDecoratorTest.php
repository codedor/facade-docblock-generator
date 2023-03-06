<?php

use App\ReflectionMethodDecorator;

beforeEach(function () {
    $this->sourceClass = new class {
        public function foo($msg)
        {
            echo $msg;
        }
    };
    $this->reflectionMethod = new ReflectionMethod(
        $this->sourceClass,
        'foo'
    );

    $this->decorator = new ReflectionMethodDecorator(
        $this->reflectionMethod,
        $this->sourceClass
    );
});

it('returns a base', function () {
    expect($this->decorator)
        ->toBase()->toBe($this->reflectionMethod);
});

it('returns a source class', function () {
    expect($this->decorator->sourceClass())
        ->toBeInstanceOf(ReflectionClass::class)
        ->getName()->toEqual(get_class($this->sourceClass));
});
