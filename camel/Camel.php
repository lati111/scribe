<?php

namespace Knuckles\Camel;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\PathConfig;
use Knuckles\Scribe\Tools\Utils;
use Symfony\Component\Yaml\Yaml;

class Camel
{
    public static function cacheDir(PathConfig $paths): string
    {
        return $paths->intermediateOutputPath('endpoints.cache');
    }

    public static function camelDir(PathConfig $paths): string
    {
        return $paths->intermediateOutputPath('endpoints');
    }

    /**
     * Load endpoints from the Camel files into groups (arrays).
     *
     * @return array[] each array is a group with keys including `name` and `endpoints`
     */
    public static function loadEndpointsIntoGroups(string $folder): array
    {
        $groups = [];
        self::loadEndpointsFromCamelFiles($folder, function (array $group) use (&$groups) {
            $groups[$group['name']] = $group;
        });

        return $groups;
    }

    /**
     * Load endpoints from the Camel files into a flat list of endpoint arrays.
     * Useful when we don't care about groups, but simply want to compare endpoints contents
     * to see if anything changed.
     *
     * @return array[] list of endpoint arrays
     */
    public static function loadEndpointsToFlatPrimitivesArray(string $folder): array
    {
        $endpoints = [];
        self::loadEndpointsFromCamelFiles($folder, function (array $group) use (&$endpoints) {
            foreach ($group['endpoints'] as $endpoint) {
                $endpoints[] = $endpoint;
            }
        });

        return $endpoints;
    }

    public static function loadEndpointsFromCamelFiles(string $folder, callable $callback): void
    {
        $contents = Utils::listDirectoryContents($folder);

        foreach ($contents as $object) {
            if (
                $object->isFile()
                && Str::endsWith(basename($object->path()), '.yaml')
                && ! Str::startsWith(basename($object->path()), 'custom.')
            ) {
                $group = Yaml::parseFile($object['path']);
                $callback($group);
            }
        }
    }

    public static function loadUserDefinedEndpoints(string $folder): array
    {
        $contents = Utils::listDirectoryContents($folder);

        $userDefinedEndpoints = [];
        foreach ($contents as $object) {
            if (
                $object->isFile()
                && Str::endsWith(basename($object->path()), '.yaml')
                && Str::startsWith(basename($object->path()), 'custom.')
            ) {
                $endpoints = Yaml::parseFile($object->path());
                foreach (($endpoints ?: []) as $endpoint) {
                    $userDefinedEndpoints[] = $endpoint;
                }
            }
        }

        return $userDefinedEndpoints;
    }

    public static function doesGroupContainEndpoint(array $group, OutputEndpointData $endpoint): bool
    {
        return (bool) (Arr::first($group['endpoints'], function ($e) use ($endpoint) {
            return $e->endpointId() === $endpoint->endpointId();
        }));
    }

    /**
     * @param  array[]  $groupedEndpoints
     * @param  array  $configFileOrder  the order for groups that users specified in their config file
     * @return array[]
     */
    public static function sortByConfigFileOrder(array $groupedEndpoints, array $configFileOrder): array
    {
        $groupsCollection = collect($groupedEndpoints)->sortBy(fn (array $group) => $group['order']);

        $sortedGroups = [];
        foreach ($groupsCollection as $group) {
            $group['endpoints'] = collect($group['endpoints'])
                ->sortBy(function (OutputEndpointData $endpoint) {
                    $subgroupOrder = $endpoint->metadata->custom['subgroup-order'] ?? 999;
                    $endpointOrder = $endpoint->metadata->custom['endpoint-order'] ?? 999;

                    return $subgroupOrder * 1000 + $endpointOrder;
                })
                ->all();

            $sortedGroups[] = $group;
        }

        return $sortedGroups;
    }

    /**
     * Prepare endpoints to be turned into HTML.
     * Map them into OutputEndpointData DTOs, and sort them by the specified order in the config file.
     *
     * @param  array<string,array[]>  $groupedEndpoints
     */
    public static function prepareGroupedEndpointsForOutput(array $groupedEndpoints, array $configFileOrder = []): array
    {
        $groups = array_map(function (array $group) {
            return [
                'name' => $group['name'],
                'description' => $group['description'],
                'order' => $group['endpoints'][0]['metadata']['custom']['group-order'] ?? 999,
                'endpoints' => array_map(
                    fn (array $endpoint) => OutputEndpointData::fromExtractedEndpointArray($endpoint),
                    $group['endpoints']
                ),
            ];
        }, $groupedEndpoints);

        return self::sortByConfigFileOrder($groups, $configFileOrder);
    }

    /**
     * Given an $order list like ['first', 'second', ...], return a compare function that can be used to sort
     * a list of strings based on the order of items in $order.
     * Any strings not in the list are sorted with natural sort.
     */
    protected static function getOrderListComparator(array $order): \Closure
    {
        return function ($a, $b) use ($order) {
            $indexOfA = array_search($a, $order);
            $indexOfB = array_search($b, $order);

            // If both are in the $order list, compare them normally based on their position in the list
            if ($indexOfA !== false && $indexOfB !== false) {
                return $indexOfA <=> $indexOfB;
            }

            // If only A is in the $order list, then it must come before B.
            if ($indexOfA !== false) {
                return -1;
            }

            // If only B is in the $order list, then it must come before A.
            if ($indexOfB !== false) {
                return 1;
            }

            // If neither is present, fall back to natural sort
            return strnatcmp($a, $b);
        };
    }
}
