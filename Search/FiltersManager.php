<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\FilterManagerBundle\Search;

use ONGR\ElasticsearchBundle\ORM\Repository;
use ONGR\ElasticsearchBundle\Result\DocumentIterator;
use ONGR\FilterManagerBundle\Filters\FilterInterface;
use ONGR\FilterManagerBundle\Filters\FilterState;
use ONGR\FilterManagerBundle\Filters\Helper\ViewDataFactoryInterface;
use ONGR\FilterManagerBundle\Filters\ViewData;
use ONGR\FilterManagerBundle\Relations\ExcludeRelation;
use ONGR\FilterManagerBundle\Relations\FilterIterator;
use ONGR\FilterManagerBundle\Relations\LogicalJoin\AndRelation;
use Symfony\Component\HttpFoundation\Request;

/**
 * This class is entry point for search request execution.
 */
class FiltersManager
{
    /**
     * @var FiltersContainer
     */
    private $container;

    /**
     * @var Repository
     */
    private $repository;

    /**
     * @param FiltersContainer $container
     * @param Repository       $repository
     */
    public function __construct(FiltersContainer $container, Repository $repository)
    {
        $this->container = $container;
        $this->repository = $repository;
    }

    /**
     * Generates and executes search request.
     *
     * @param Request $request
     *
     * @return SearchResponse
     */
    public function execute(Request $request)
    {
        return $this->search($this->container->buildSearchRequest($request));
    }

    /**
     * Executes search.
     *
     * @param SearchRequest $request
     *
     * @return SearchResponse
     */
    public function search(SearchRequest $request)
    {
        $search = $this->container->buildSearch($request);

        /** @var FilterInterface[] $filters */
        foreach ($this->container->all() as $name => $filter) {
            // We simply exclude not related filters and current filter itself.
            $relatedFilters = $this->container->getFiltersByRelation(
                new AndRelation([$filter->getSearchRelation(), new ExcludeRelation([$name])])
            );
            $filter->preProcessSearch(
                $search,
                $this->container->buildSearch($request, $relatedFilters),
                $request->get($name)
            );
        }

        $result = $this->repository->execute($search);

        return new SearchResponse(
            $this->getFiltersViewData($result, $request),
            $result,
            $this->composeUrlParameters($request)
        );
    }

    /**
     * @param Request $request
     *
     * @return mixed
     */
    public function simpleSearch(Request $request)
    {
        $searchRequest = $this->container->buildSearchRequest($request);
        $search        = $this->container->buildSearch($searchRequest);

        return $this->repository->execute($search);
    }

    /**
     * Composes url parameters related to given filter.
     *
     * @param SearchRequest   $request Search request.
     * @param FilterInterface $filter  Filter.
     * @param array           $exclude Additional names of filters to exclude.
     *
     * @return array
     */
    protected function composeUrlParameters(SearchRequest $request, FilterInterface $filter = null, $exclude = [])
    {
        $out = [];

        $and = [];

        if ($filter) {
            $and[] = $filter->getResetRelation();
        }

        if (!empty($exclude)) {
            $and[] = new ExcludeRelation($exclude);
        }

        /** @var FilterState[] $states */
        $states = new FilterIterator(new \IteratorIterator($request), new AndRelation($and));

        foreach ($states as $state) {
            $out = array_merge($out, $state->getUrlParameters());
        }

        return $out;
    }

    /**
     * Creates view data for each filter.
     *
     * @param DocumentIterator $result
     * @param SearchRequest    $request
     *
     * @return ViewData[]
     */
    protected function getFiltersViewData(DocumentIterator $result, SearchRequest $request)
    {
        $out = [];

        /** @var FilterInterface[] $filters */
        $filters = $this->container->all();

        foreach ($filters as $name => $filter) {
            if ($filter instanceof ViewDataFactoryInterface) {
                $viewData = $filter->createViewData();
            } else {
                $viewData = new ViewData();
            }
            $viewData->setName($name);
            $viewData->setUrlParameters($this->composeUrlParameters($request, $filter));
            $viewData->setState($request->get($name));
            $viewData->setTags($filter->getTags());
            $viewData->setResetUrlParameters($this->composeUrlParameters($request, $filter, [$name]));
            $out[$name] = $filter->getViewData($result, $viewData);
        }

        return $out;
    }

    /**
     * @return FiltersContainer
     */
    public function getContainer()
    {
        return $this->container;
    }
}
