<?php

namespace Knuckles\Scribe\Tests\Unit;

use Knuckles\Camel\Camel;
use Knuckles\Scribe\Tests\BaseUnitTest;

/**
 * @internal
 *
 * @coversNothing
 */
class CamelCustomOrderTest extends BaseUnitTest
{
    public function test_sorts_groups_by_group_order()
    {
        $groups = Camel::prepareGroupedEndpointsForOutput([
            $this->group('Users', [$this->endpoint('api/users', ['group-order' => 2])]),
            $this->group('Auth', [$this->endpoint('api/login', ['group-order' => 1])]),
            $this->group('Offertes', [$this->endpoint('api/offertes', ['group-order' => 3])]),
        ]);

        $this->assertEquals(['Auth', 'Users', 'Offertes'], array_column($groups, 'name'));
        $this->assertEquals([1, 2, 3], array_column($groups, 'order'));
    }

    public function test_reads_group_order_from_the_first_endpoint_only()
    {
        $groups = Camel::prepareGroupedEndpointsForOutput([
            $this->group('Users', [
                $this->endpoint('api/users', ['group-order' => 5]),
                $this->endpoint('api/users/save', ['group-order' => 1]),
            ]),
            $this->group('Auth', [$this->endpoint('api/login', ['group-order' => 2])]),
        ]);

        $this->assertEquals(['Auth', 'Users'], array_column($groups, 'name'));
    }

    public function test_places_groups_without_order_last_in_their_original_order()
    {
        $groups = Camel::prepareGroupedEndpointsForOutput([
            $this->group('Zebra', [$this->endpoint('api/zebra')]),
            $this->group('Apple', [$this->endpoint('api/apple')]),
            $this->group('Users', [$this->endpoint('api/users', ['group-order' => 10])]),
        ]);

        $this->assertEquals(['Users', 'Zebra', 'Apple'], array_column($groups, 'name'));
        $this->assertEquals(999, $groups[1]['order']);
    }

    public function test_sorts_endpoints_by_subgroup_order_then_endpoint_order()
    {
        $groups = Camel::prepareGroupedEndpointsForOutput([
            $this->group('Offertes', [
                $this->endpoint('api/offertes/items/delete', ['subgroup-order' => 2, 'endpoint-order' => 2]),
                $this->endpoint('api/offertes/save', ['subgroup-order' => 1, 'endpoint-order' => 2]),
                $this->endpoint('api/offertes/items/save', ['subgroup-order' => 2, 'endpoint-order' => 1]),
                $this->endpoint('api/offertes', ['subgroup-order' => 1, 'endpoint-order' => 1]),
            ]),
        ]);

        $this->assertEquals([
            'api/offertes',
            'api/offertes/save',
            'api/offertes/items/save',
            'api/offertes/items/delete',
        ], $this->uris($groups[0]));
    }

    public function test_places_endpoints_without_order_after_ordered_ones()
    {
        $groups = Camel::prepareGroupedEndpointsForOutput([
            $this->group('Users', [
                $this->endpoint('api/users/unordered'),
                $this->endpoint('api/users/second', ['subgroup-order' => 1, 'endpoint-order' => 2]),
                $this->endpoint('api/users/first', ['subgroup-order' => 1, 'endpoint-order' => 1]),
                $this->endpoint('api/users/no-subgroup-order', ['endpoint-order' => 1]),
            ]),
        ]);

        // Without a subgroup order the endpoint falls in subgroup 999, so it lands after every ordered subgroup
        $this->assertEquals([
            'api/users/first',
            'api/users/second',
            'api/users/no-subgroup-order',
            'api/users/unordered',
        ], $this->uris($groups[0]));
    }

    public function test_ignores_the_config_file_order()
    {
        $groups = Camel::prepareGroupedEndpointsForOutput([
            $this->group('Users', [$this->endpoint('api/users', ['group-order' => 1])]),
            $this->group('Auth', [$this->endpoint('api/login', ['group-order' => 2])]),
        ], ['Auth', 'Users']);

        $this->assertEquals(['Users', 'Auth'], array_column($groups, 'name'));
    }

    private function group(string $name, array $endpoints): array
    {
        return [
            'name' => $name,
            'description' => '',
            'endpoints' => $endpoints,
        ];
    }

    private function endpoint(string $uri, array $custom = []): array
    {
        return [
            'httpMethods' => ['GET'],
            'uri' => $uri,
            'metadata' => [
                'groupName' => '',
                'title' => $uri,
                'custom' => $custom,
            ],
        ];
    }

    private function uris(array $group): array
    {
        return array_values(array_map(fn ($endpoint) => $endpoint->uri, $group['endpoints']));
    }
}
