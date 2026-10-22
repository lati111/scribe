<?php

namespace Knuckles\Scribe\Tests\Unit;

use Illuminate\Routing\Route;
use Knuckles\Scribe\Extracting\RouteDocBlocker;
use Knuckles\Scribe\Tests\BaseLaravelTest;
use Knuckles\Scribe\Tests\Fixtures\TestController;
use Knuckles\Scribe\Tests\Fixtures\TestInheritDocController;

/**
 * @internal
 *
 * @coversNothing
 */
class RouteDocBlockerTest extends BaseLaravelTest
{
    public function test_replaces_inheritdoc_with_the_parent_method_docblock()
    {
        $docBlocks = $this->docBlocks('show');

        $this->assertEquals('Fetch a resource.', $docBlocks['method']->getShortDescription());
        $this->assertTrue($docBlocks['method']->hasTag('authenticated'));
        $this->assertFalse($docBlocks['method']->hasTag('inheritdoc'));
    }

    public function test_walks_up_the_parents_while_the_inherited_docblock_inherits()
    {
        $docBlocks = $this->docBlocks('index');

        // The middle class contributes its query param, the base class its description
        $this->assertEquals('Base description.', $docBlocks['method']->getShortDescription());
        $this->assertTrue($docBlocks['method']->hasTag('queryParam'));
        $this->assertFalse($docBlocks['method']->hasTag('inheritdoc'));
    }

    public function test_resolves_class_docblocks_through_every_parent()
    {
        $docBlocks = $this->docBlocks('show');

        $this->assertEquals('Inherited group', $docBlocks['class']->getTagsByName('group')[0]->getContent());
        $this->assertEquals('Own subgroup', $docBlocks['class']->getTagsByName('subgroup')[0]->getContent());
        $this->assertFalse($docBlocks['class']->hasTag('inheritdoc'));
    }

    public function test_keeps_inheritdoc_when_the_parent_has_no_such_method()
    {
        $docBlocks = $this->docBlocks('withoutParentMethod');

        $this->assertTrue($docBlocks['method']->hasTag('inheritdoc'));
    }

    public function test_leaves_docblocks_without_inheritdoc_untouched()
    {
        $route = new Route(['GET'], 'api/description', ['uses' => [TestController::class, 'withEndpointDescription']]);
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($route);

        $this->assertEquals('Example title.', $docBlocks['method']->getShortDescription());
        $this->assertEquals('Group A', $docBlocks['class']->getTagsByName('group')[0]->getContent());
    }

    private function docBlocks(string $method): array
    {
        $route = new Route(['GET'], "api/inherit/{$method}", ['uses' => [TestInheritDocController::class, $method]]);

        return RouteDocBlocker::getDocBlocksFromRoute($route);
    }
}
