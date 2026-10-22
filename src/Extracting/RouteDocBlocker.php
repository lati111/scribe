<?php

namespace Knuckles\Scribe\Extracting;

use Illuminate\Routing\Route;
use Knuckles\Scribe\Tools\ConsoleOutputUtils as c;
use Knuckles\Scribe\Tools\Utils as u;
use Mpociot\Reflection\DocBlock;

/**
 * Class RouteDocBlocker
 * Utility class to help with retrieving doc blocks from route classes and methods.
 * Also caches them so repeated access is faster.
 */
class RouteDocBlocker
{
    protected static array $docBlocks = [];

    /**
     * @return array{method: DocBlock, class: ?DocBlock} Method and class docblocks
     */
    public static function getDocBlocksFromRoute(Route $route): array
    {
        [$className, $methodName] = u::getRouteClassAndMethodNames($route);

        return static::getDocBlocks($route, $className, $methodName);
    }

    /**
     * @param  mixed  $className
     * @param  null|mixed  $methodName
     * @return array{method: DocBlock, class: DocBlock} Method and class docblocks
     */
    public static function getDocBlocks(Route $route, $className, $methodName = null): array
    {
        if (is_array($className)) {
            [$className, $methodName] = $className;
        }

        $normalizedClassName = static::normalizeClassName($className);
        $docBlocks = self::getCachedDocBlock($route, $normalizedClassName, $methodName);

        if ($docBlocks) {
            return $docBlocks;
        }

        $class = new \ReflectionClass($className);

        if (! $class->hasMethod($methodName)) {
            throw new \Exception('Error while fetching docblock for route '.c::getRouteRepresentation($route).": Class {$className} does not contain method {$methodName}");
        }

        $method = u::getReflectedRouteMethod([$className, $methodName]);

        $docBlocks = [
            'method' => new DocBlock(self::resolveInheritedDoc(
                $method->getDocComment() ?: '',
                // Closure routes are a ReflectionFunction, which has nothing to inherit from
                $method instanceof \ReflectionMethod ? $method->getDeclaringClass()->getParentClass() : false,
                fn (\ReflectionClass $parent) => $parent->hasMethod($methodName) ? $parent->getMethod($methodName) : null
            )),
            'class' => new DocBlock(self::resolveInheritedDoc(
                $class->getDocComment() ?: '',
                $class->getParentClass(),
                fn (\ReflectionClass $parent) => $parent
            )),
        ];

        self::cacheDocBlocks($route, $normalizedClassName, $methodName, $docBlocks);

        return $docBlocks;
    }

    /**
     * Replace the inheritdoc tag (with or without braces) with the docblock of the parent, walking up
     * the parents for as long as the inherited docblock itself contains the tag.
     * The tag is left as is when there is no parent (or the parent has no such method).
     *
     * @param  \Closure(\ReflectionClass): (\ReflectionClass|\ReflectionMethod|null)  $getReflector
     */
    protected static function resolveInheritedDoc(string $docComment, \ReflectionClass|false $parent, \Closure $getReflector): string
    {
        $pattern = '/\{?@inherit[Dd]oc\}?/';

        while ($parent && preg_match($pattern, $docComment)) {
            $reflector = $getReflector($parent);
            if ($reflector === null) {
                break;
            }

            $inheritedDoc = str_replace(['/**', '*/'], '', $reflector->getDocComment() ?: '');
            $docComment = preg_replace($pattern, $inheritedDoc, $docComment, 1);
            $parent = $parent->getParentClass();
        }

        return $docComment;
    }

    /**
     * @param  object|string  $classNameOrInstance
     */
    protected static function normalizeClassName($classNameOrInstance): string
    {
        if (is_object($classNameOrInstance)) {
            // Route handlers are not destroyed until the script ends so this should be perfectly safe.
            $classNameOrInstance = get_class($classNameOrInstance).'::'.spl_object_id($classNameOrInstance);
        }

        return $classNameOrInstance;
    }

    protected static function getCachedDocBlock(Route $route, string $className, string $methodName)
    {
        $routeId = self::getRouteCacheId($route, $className, $methodName);

        return self::$docBlocks[$routeId] ?? null;
    }

    protected static function cacheDocBlocks(Route $route, string $className, string $methodName, array $docBlocks)
    {
        $routeId = self::getRouteCacheId($route, $className, $methodName);
        self::$docBlocks[$routeId] = $docBlocks;
    }

    private static function getRouteCacheId(Route $route, string $className, string $methodName): string
    {
        return $route->uri()
            .':'
            .implode(array_diff($route->methods(), ['HEAD']))
            .$className
            .$methodName;
    }
}
