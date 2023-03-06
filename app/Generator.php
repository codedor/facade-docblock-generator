<?php

namespace App;

use ArrayAccess;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\Finder\Finder;
use Throwable;

class Generator
{
    public function __construct(
        private string $namespace,
        private string $directory,
        private bool $isLinting,
    ) {
    }

    public function execute()
    {
        $finder = (new Finder)
            ->in(dirname(__DIR__) . DIRECTORY_SEPARATOR . $this->directory)
            ->notName('Facade.php');

        $this->resolveFacades($finder)->each(function ($facade) {
            $proxies = $this->resolveDocSees($facade);

            // Build a list of methods that are available on the Facade...

            $resolvedMethods = $proxies->map(fn ($fqcn) => new ReflectionClass($fqcn))
                ->flatMap(fn ($class) => [$class, ...$this->resolveDocMixins($class)])
                ->flatMap($this->resolveMethods(...))
                ->reject($this->isMagic(...))
                ->reject($this->isInternal(...))
                ->reject($this->isDeprecated(...))
                ->reject($this->fulfillsBuiltinInterface(...))
                ->reject(fn ($method) => $this->conflictsWithFacade($facade, $method))
                ->unique($this->resolveName(...))
                ->map($this->normaliseDetails(...));

            // Prepare the @method docblocks...

            $methods = $resolvedMethods->map(function ($method) {
                if (is_string($method)) {
                    return " * @method static {$method}";
                }

                $parameters = $method['parameters']->map(function ($parameter) {
                    $rest = $parameter['variadic'] ? '...' : '';

                    $default = $parameter['optional'] ? ' = '.$this->resolveDefaultValue($parameter) : '';

                    return "{$parameter['type']} {$rest}{$parameter['name']}{$default}";
                });

                return " * @method static {$method['returns']} {$method['name']}({$parameters->join(', ')})";
            });

            // Fix: ensure we keep the references to the Carbon library on the Date Facade...

            if (Str::endsWith($facade->getName(), 'Date')) {
                $methods->prepend(' *')
                        ->prepend(' * @see https://github.com/briannesbitt/Carbon/blob/master/src/Carbon/Factory.php')
                        ->prepend(' * @see https://carbon.nesbot.com/docs/');
            }

            // To support generics, we want to preserve any mixins on the class...

            $directMixins = $this->resolveDocTags($facade->getDocComment() ?: '', '@mixin');

            // Generate the docblock...
            $docblock = <<< PHP
            /**
            {$methods->join(PHP_EOL)}
             *
            {$proxies->map(fn ($class) => " * @see {$class}")->merge($directMixins->map(fn ($class) => " * @mixin {$class}"))->join(PHP_EOL)}
             */
            PHP;

            $docblock = Str::replace("\n\n", "\n", $docblock);

            if (($facade->getDocComment() ?: '') === $docblock) {
                return;
            }

            // Update the facade docblock...

            // echo "Updating docblock for [{$facade->getName()}].".PHP_EOL;
            $contents = file_get_contents($facade->getFileName());
            $contents = Str::replace($facade->getDocComment(), $docblock, $contents);

            file_put_contents($facade->getFileName(), $contents);
        });
    }

    /**
     * Resolve the facades from the given directory.
     *
     * @param  \Symfony\Component\Finder\Finder  $finder
     * @return \Illuminate\Support\Collection<\ReflectionClass>
     */
    public function resolveFacades($finder)
    {
        return collect($finder)
            ->map(fn ($file) => $file->getBaseName('.php'))
            ->map(fn ($name) => Str::replace("\\\\", "\\", "\\{$this->namespace}\\{$name}"))
            ->map(fn ($class) => new ReflectionClass($class));
    }

    /**
     * Resolve the classes referenced in the @see docblocks.
     *
     * @param  \ReflectionClass  $class
     * @return \Illuminate\Support\Collection<class-string>
     */
    public function resolveDocSees($class)
    {
        return $this->resolveDocTags($class->getDocComment() ?: '', '@see')
            ->reject(fn ($tag) => Str::startsWith($tag, 'https://'));
    }

    /**
     * Resolve the classes referenced methods in the @methods docblocks.
     *
     * @param  \ReflectionClass  $class
     * @return \Illuminate\Support\Collection<string>
     */
    public function resolveDocMethods($class)
    {
        return $this->resolveDocTags($class->getDocComment() ?: '', '@method')
            ->map(fn ($tag) => Str::squish($tag))
            ->map(fn ($tag) => Str::before($tag, ')').')');
    }

    /**
     * Resolve the parameters type from the @param docblocks.
     *
     * @param  \ReflectionMethodDecorator  $method
     * @param  \ReflectionParameter  $parameter
     * @return string|null
     */
    public function resolveDocParamType($method, $parameter)
    {
        $paramTypeNode = collect($this->parseDocblock($method->getDocComment())->getParamTagValues())
            ->firstWhere('parameterName', '$'.$parameter->getName());

        // As we didn't find a param type, we will now recursivly check if the prototype has a value specified...

        if ($paramTypeNode === null) {
            try {
                $prototype = new ReflectionMethodDecorator($method->getPrototype(), $method->sourceClass()->getName());

                return $this->resolveDocParamType($prototype, $parameter);
            } catch (Throwable) {
                return null;
            }
        }

        $type = $this->resolveDocblockTypes($method, $paramTypeNode->type);

        return is_string($type) ? trim($type, '()') : null;
    }

    /**
     * Resolve the return type from the @return docblock.
     *
     * @param  \ReflectionMethodDecorator  $method
     * @return string|null
     */
    public function resolveReturnDocType($method)
    {
        $returnTypeNode = array_values($this->parseDocblock($method->getDocComment())->getReturnTagValues())[0] ?? null;

        if ($returnTypeNode === null) {
            return null;
        }

        $type = $this->resolveDocblockTypes($method, $returnTypeNode->type);

        return is_string($type) ? trim($type, '()') : null;
    }

    /**
     * Parse the given docblock.
     *
     * @param  string  $docblock
     * @return \PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode
     */
    public function parseDocblock($docblock)
    {
        return (new PhpDocParser(new TypeParser(new ConstExprParser), new ConstExprParser))->parse(
            new TokenIterator((new Lexer)->tokenize($docblock ?: '/** */'))
        );
    }

    /**
     * Resolve the types from the docblock.
     *
     * @param  \ReflectionMethodDecorator  $method
     * @param  \PHPStan\PhpDocParser\Ast\Type\TypeNode  $typeNode
     * @return string
     */
    public function resolveDocblockTypes($method, $typeNode)
    {
        if ($typeNode instanceof UnionTypeNode) {
            return '('.collect($typeNode->types)
                ->map(fn ($node) => $this->resolveDocblockTypes($method, $node))
                ->unique()
                ->implode('|').')';
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            return '('.collect($typeNode->types)
                ->map(fn ($node) => $this->resolveDocblockTypes($method, $node))
                ->unique()
                ->implode('&').')';
        }

        if ($typeNode instanceof GenericTypeNode) {
            return $this->resolveDocblockTypes($method, $typeNode->type);
        }

        if ($typeNode instanceof ThisTypeNode) {
            return '\\'.$method->sourceClass()->getName();
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return $this->resolveDocblockTypes($method, $typeNode->type).'[]';
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            if ($typeNode->name === 'static') {
                return '\\'.$method->sourceClass()->getName();
            }

            if ($typeNode->name === 'self') {
                return '\\'.$method->getDeclaringClass()->getName();
            }

            if ($this->isBuiltIn($typeNode->name)) {
                return (string) $typeNode;
            }

            if ($typeNode->name === 'class-string') {
                return 'string';
            }

            $guessedFqcn = $this->resolveClassImports($method->getDeclaringClass())->get($typeNode->name) ?? '\\'.$method->getDeclaringClass()->getNamespaceName().'\\'.$typeNode->name;

            foreach ([$typeNode->name, $guessedFqcn] as $name) {
                if (class_exists($name)) {
                    return (string) $name;
                }

                if (interface_exists($name)) {
                    return (string) $name;
                }

                if (enum_exists($name)) {
                    return (string) $name;
                }

                if ($this->isKnownOptionalDependency($name)) {
                    return (string) $name;
                }
            }

            return $this->handleUnknownIdentifierType($method, $typeNode);
        }

        if ($typeNode instanceof ConditionalTypeNode) {
            return $this->handleConditionalType($method, $typeNode);
        }

        if ($typeNode instanceof NullableTypeNode) {
            return '?'.$this->resolveDocblockTypes($method, $typeNode->type);
        }

        if ($typeNode instanceof CallableTypeNode) {
            return $this->resolveDocblockTypes($method, $typeNode->identifier);
        }

        // echo 'Unhandled type: '.$typeNode::class;
        // echo PHP_EOL;
        // echo 'You may need to update the `resolveDocblockTypes` to handle this type.';
        // echo PHP_EOL;
    }

    /**
     * Handle conditional types.
     *
     * @param  \ReflectionMethodDecorator  $method
     * @param  \PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode  $typeNode
     * @return string
     */
    public function handleConditionalType($method, $typeNode)
    {
        if (
            in_array($method->getname(), ['pull', 'get']) &&
            $method->getDeclaringClass()->getName() === Repository::class
        ) {
            return 'mixed';
        }

        // echo 'Found unknown conditional type. You will need to update the `handleConditionalType` to handle this new conditional type.';
        // echo PHP_EOL;
    }

    /**
     * Handle unknown identifier types.
     *
     * @param  \ReflectionMethodDecorator  $method
     * @param  \PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode  $typeNode
     * @return string
     */
    public function handleUnknownIdentifierType($method, $typeNode)
    {
        if (
            $typeNode->name === 'TCacheValue' &&
            $method->getDeclaringClass()->getName() === Repository::class
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TWhenParameter' &&
            in_array(Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TWhenReturnType' &&
            in_array(Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TUnlessParameter' &&
            in_array(Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TUnlessReturnType' &&
            in_array(Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TEnum' &&
            $method->getDeclaringClass()->getName() === Request::class
        ) {
            return 'object';
        }

        // echo 'Found unknown type: '.$typeNode->name;
        // echo PHP_EOL;
        // echo 'You may need to update the `handleUnknownIdentifierType` to handle this new type / generic.';
        // echo PHP_EOL;
    }

    /**
     * Determine if the type is a built-in.
     *
     * @param  string  $type
     * @return bool
     */
    public function isBuiltIn($type)
    {
        return in_array($type, [
            'null', 'bool', 'int', 'float', 'string', 'array', 'object',
            'resource', 'never', 'void', 'mixed', 'iterable', 'self', 'static',
            'parent', 'true', 'false', 'callable',
        ]);
    }

    /**
     * Determine if the type is known optional dependency.
     *
     * @param  string  $type
     * @return bool
     */
    public function isKnownOptionalDependency($type)
    {
        return in_array($type, [
            '\Pusher\Pusher',
            '\GuzzleHttp\Psr7\RequestInterface',
        ]);
    }

    /**
     * Resolve the declared type.
     *
     * @param  \ReflectionType|null  $type
     * @return string|null
     */
    public function resolveType($type)
    {
        if ($type instanceof ReflectionIntersectionType) {
            return collect($type->getTypes())
                ->map($this->resolveType(...))
                ->filter()
                ->join('&');
        }

        if ($type instanceof ReflectionUnionType) {
            return collect($type->getTypes())
                ->map($this->resolveType(...))
                ->filter()
                ->join('|');
        }

        if ($type instanceof ReflectionNamedType && $type->getName() === 'null') {
            return ($type->isBuiltin() ? '' : '\\').$type->getName();
        }

        if ($type instanceof ReflectionNamedType && $type->getName() !== 'null') {
            return ($type->isBuiltin() ? '' : '\\').$type->getName().($type->allowsNull() ? '|null' : '');
        }

        return null;
    }

    /**
     * Resolve the docblock tags.
     *
     * @param  string  $docblock
     * @param  string  $tag
     * @return \Illuminate\Support\Collection<string>
     */
    public function resolveDocTags($docblock, $tag)
    {
        return Str::of($docblock)
            ->explode("\n")
            ->skip(1)
            ->reverse()
            ->skip(1)
            ->reverse()
            ->map(fn ($line) => ltrim($line, ' \*'))
            ->filter(fn ($line) => Str::startsWith($line, $tag))
            ->map(fn ($line) => Str::of($line)->after($tag)->trim()->toString())
            ->values();
    }

    /**
     * Recursivly resolve docblock mixins.
     *
     * @param  \ReflectionClass  $class
     * @return \Illuminate\Support\Collection<\ReflectionClass>
     */
    public function resolveDocMixins($class)
    {
        return $this->resolveDocTags($class->getDocComment() ?: '', '@mixin')
            ->map(fn ($mixin) => new ReflectionClass($mixin))
            ->flatMap(fn ($mixin) => [$mixin, ...$this->resolveDocMixins($mixin)]);
    }

    /**
     * Resolve the classes referenced methods in the @methods docblocks.
     *
     * @param  \ReflectionMethodDecorator  $method
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function resolveDocParameters($method)
    {
        return $this->resolveDocTags($method->getDocComment() ?: '', '@param')
            ->map(fn ($tag) => Str::squish($tag));
    }

    /**
     * Determine if the method is magic.
     *
     * @param  \ReflectionMethod|string  $method
     * @return bool
     */
    public function isMagic($method)
    {
        return Str::startsWith(is_string($method) ? $method : $method->getName(), '__');
    }

    /**
     * Determine if the method is marked as @internal.
     *
     * @param  \ReflectionMethod|string  $method
     * @return bool
     */
    public function isInternal($method)
    {
        if (is_string($method)) {
            return false;
        }

        return $this->resolveDocTags($method->getDocComment(), '@internal')->isNotEmpty();
    }

    /**
     * Determine if the method is deprecated.
     *
     * @param  \ReflectionMethod|string  $method
     * @return bool
     */
    public function isDeprecated($method)
    {
        if (is_string($method)) {
            return false;
        }

        return $method->isDeprecated() || $this->resolveDocTags($method->getDocComment(), '@deprecated')->isNotEmpty();
    }

    /**
     * Determine if the method is for a builtin contract.
     *
     * @param  \ReflectionMethodDecorator|string  $method
     * @return bool
     */
    public function fulfillsBuiltinInterface($method)
    {
        if (is_string($method)) {
            return false;
        }

        if ($method->sourceClass()->implementsInterface(ArrayAccess::class)) {
            return in_array($method->getName(), ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset']);
        }

        return false;
    }

    /**
     * Resolve the methods name.
     *
     * @param  \ReflectionMethod|string  $method
     * @return string
     */
    public function resolveName($method)
    {
        return is_string($method)
            ? Str::of($method)->after(' ')->before('(')->toString()
            : $method->getName();
    }

    /**
     * Resolve the classes methods.
     *
     * @param  \ReflectionClass  $class
     * @return \Illuminate\Support\Collection<\ReflectionMethodDecorator|string>
     */
    public function resolveMethods($class)
    {
        return collect($class->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn ($method) => new ReflectionMethodDecorator($method, $class->getName()))
            ->merge($this->resolveDocMethods($class));
    }

    /**
     * Determine if the given method conflicts with a Facade method.
     *
     * @param  \ReflectionClass  $facade
     * @param  \ReflectionMethod|string  $method
     * @return bool
     */
    public function conflictsWithFacade($facade, $method)
    {
        return collect($facade->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC))
            ->map(fn ($method) => $method->getName())
            ->contains(is_string($method) ? $method : $method->getName());
    }

    /**
     * Normalise the method details into a easier format to work with.
     *
     * @param  \ReflectionMethodDecorator|string  $method
     * @return array|string
     */
    public function normaliseDetails($method)
    {
        return is_string($method) ? $method : [
            'name' => $method->getName(),
            'parameters' => $this->resolveParameters($method)
                ->map(fn ($parameter) => [
                    'name' => '$'.$parameter->getName(),
                    'optional' => $parameter->isOptional() && ! $parameter->isVariadic(),
                    'default' => $parameter->isDefaultValueAvailable()
                        ? $parameter->getDefaultValue()
                        : "❌ Unknown default for [{$parameter->getName()}] in [{$parameter->getDeclaringClass()?->getName()}::{$parameter->getDeclaringFunction()->getName()}] ❌",
                    'variadic' => $parameter->isVariadic(),
                    'type' => $this->resolveDocParamType($method, $parameter) ?? $this->resolveType($parameter->getType()) ?? 'void',
                ]),
            'returns' => $this->resolveReturnDocType($method) ?? $this->resolveType($method->getReturnType()) ?? 'void',
        ];
    }

    /**
     * Resolve the parameters for the method.
     *
     * @param  \ReflectionMethodDecorator  $method
     * @return \Illuminate\Support\Collection<int, \ReflectionParameter|\DynamicParameter>
     */
    public function resolveParameters($method)
    {
        $dynamicParameters = $this->resolveDocParameters($method)
            ->skip($method->getNumberOfParameters())
            ->mapInto(DynamicParameter::class);

        return collect($method->getParameters())->merge($dynamicParameters);
    }

    /**
     * Resolve the classes imports.
     *
     * @param  \ReflectionClass  $class
     * @return \Illuminate\Support\Collection<string, class-string>
     */
    public function resolveClassImports($class)
    {
        return Str::of(file_get_contents($class->getFileName()))
            ->explode(PHP_EOL)
            ->take($class->getStartLine() - 1)
            ->filter(fn ($line) => preg_match('/^use [A-Za-z0-9\\\\]+( as [A-Za-z0-9]+)?;$/', $line) === 1)
            ->map(fn ($line) => Str::of($line)->after('use ')->before(';'))
            ->mapWithKeys(fn ($class) => [
                ($class->contains(' as ') ? $class->after(' as ') : $class->classBasename())->toString() => $class->start('\\')->before(' as ')->toString(),
            ]);
    }

    /**
     * Resolve the default value for the parameter.
     *
     * @param  array  $parameter
     * @return string
     */
    public function resolveDefaultValue($parameter)
    {
        // Reflection limitation fix for:
        // - Illuminate\Filesystem\Filesystem::ensureDirectoryExists()
        // - Illuminate\Filesystem\Filesystem::makeDirectory()
        if ($parameter['name'] === '$mode' && $parameter['default'] === 493) {
            return '0755';
        }

        $default = json_encode($parameter['default']);

        return Str::of($default === false ? 'unknown' : $default)
            ->replace('"', "'")
            ->replace('\\/', '/')
            ->toString();
    }
}
