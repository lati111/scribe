<?php

namespace Knuckles\Scribe\Tests\Fixtures;

use Illuminate\Routing\Controller;

/**
 * Shared description of the group.
 *
 * @group Inherited group
 */
abstract class TestInheritDocBaseController extends Controller
{
    /**
     * Fetch a resource.
     *
     * @authenticated
     */
    public function show()
    {
        return '';
    }

    /**
     * Base description.
     */
    public function index()
    {
        return '';
    }
}

/**
 * @inheritdoc
 */
abstract class TestInheritDocMiddleController extends TestInheritDocBaseController
{
    /**
     * {@inheritDoc}
     *
     * @queryParam page int The page to fetch.
     */
    public function index()
    {
        return '';
    }
}

/**
 * @inheritdoc
 *
 * @subgroup Own subgroup
 */
class TestInheritDocController extends TestInheritDocMiddleController
{
    /**
     * @inheritdoc
     */
    public function show()
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function index()
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function withoutParentMethod()
    {
        return '';
    }
}
