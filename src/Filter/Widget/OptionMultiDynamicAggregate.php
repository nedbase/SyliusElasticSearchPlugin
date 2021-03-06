<?php

declare(strict_types=1);

namespace Sylius\ElasticSearchPlugin\Filter\Widget;

use ONGR\ElasticsearchBundle\Result\Aggregation\AggregationValue;
use ONGR\ElasticsearchBundle\Result\DocumentIterator;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\FilterAggregation;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\NestedAggregation;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\TermsAggregation;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\Joining\NestedQuery;
use ONGR\ElasticsearchDSL\Query\MatchAllQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\RangeQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use ONGR\ElasticsearchDSL\Search;
use ONGR\FilterManagerBundle\Filter\FilterState;
use ONGR\FilterManagerBundle\Filter\ViewData;
use ONGR\FilterManagerBundle\Search\SearchRequest;

class OptionMultiDynamicAggregate extends MultiDynamicAggregateOverride
{
    /**
     * Fetches buckets from search results.
     *
     * @param DocumentIterator $result     Search results.
     * @param string           $filterName Filter name.
     * @param array            $values     Values from the state object
     *
     * @return array Buckets.
     */
    protected function fetchAggregation(DocumentIterator $result, $filterName, $values)
    {
        $data = [];
        $values = empty($values) ? [] : $values;
        $aggregation = $result->getAggregation(sprintf('%s-filter', $filterName));

        foreach ($values as $name => $value) {
            $data[$name] = $aggregation->find(sprintf('%s.%s.name', $name, $filterName));
        }

        $data['all-selected'] = $aggregation->find(sprintf('all-selected.%s.%s.name', $filterName, $filterName));

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function modifySearch(Search $search, FilterState $state = null, SearchRequest $request = null)
    {
        if ($state && $state->isActive()) {
            $search->addPostFilter($this->getFilterQuery($state->getValue()));
        }
    }

    /**
     * Forms $unsortedChoices array with all possible choices.
     * 0 is assigned to the document count of the choices.
     *
     * @param DocumentIterator $result
     * @param ViewData         $data
     *
     * @return array
     */
    protected function formInitialUnsortedChoices($result, $data)
    {
        $unsortedChoices = [];
        $urlParameters = array_merge(
            $data->getResetUrlParameters(),
            $data->getState()->getUrlParameters()
        );

        foreach ($result->getAggregation($data->getName())->getAggregation($data->getName())->getAggregation('name') as $nameBucket) {
            $groupName = $nameBucket['key'];

            foreach ($nameBucket->getAggregation('value') as $bucket) {
                $bucketArray = ['key' => $bucket['key'], 'doc_count' => 0];
                $choice = $this->createChoice($data, $bucket['key'], '', $bucketArray, $urlParameters);
                $unsortedChoices[$groupName][$bucket['key']] = $choice;
            }
        }

        return $unsortedChoices;
    }

    /**
     * {@inheritdoc}
     */
    public function getViewData(DocumentIterator $result, ViewData $data)
    {
        $unsortedChoices = [];
        $activeNames = $data->getState()->isActive() ? array_keys($data->getState()->getValue()) : [];
        $filterAggregations = $this->fetchAggregation($result, $data->getName(), $data->getState()->getValue());

        if ($this->getShowZeroChoices()) {
            $unsortedChoices = $this->formInitialUnsortedChoices($result, $data);
        }

        /** @var AggregationValue $bucket */
        foreach ($filterAggregations as $activeName => $filterAggregation) {
            foreach ($filterAggregation as $nameAggregation) {
                $name = $nameAggregation['key'];

                if (($name != $activeName && $activeName != 'all-selected') ||
                    ($activeName == 'all-selected' && in_array($name, $activeNames))) {
                    continue;
                }

                foreach ($nameAggregation['value']['buckets'] as $bucket) {
                    $choice = $this->createChoice($data, $name, $activeName, $bucket);
                    $unsortedChoices[$name][$bucket['key']] = $choice;
                }

                $this->addViewDataItem($data, $name, $unsortedChoices[$name]);
                unset($unsortedChoices[$name]);
            }
        }

        /** @var ViewData\AggregateViewData $data */
        $data->sortItems();

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function preProcessSearch(Search $search, Search $relatedSearch, FilterState $state = null)
    {
        [$parent, $child, $field] = explode('>', $this->getDocumentField());
        $filter = !empty($filter = $relatedSearch->getPostFilters()) ? $filter : new MatchAllQuery();
        $parentAggregation = new NestedAggregation($state->getName(), $parent);
        $childAggregation = new NestedAggregation($state->getName(), $child);
        $nameAggregation = new TermsAggregation('name', $this->getNameField());
        $valueAggregation = new TermsAggregation('value', $field);
        $filterAggregation = new FilterAggregation($state->getName() . '-filter');
        $nameAggregation->addAggregation($valueAggregation);
        $childAggregation->addAggregation($nameAggregation);
        $parentAggregation->addAggregation($childAggregation);
        $filterAggregation->setFilter($filter);

        if ($this->getSortType()) {
            $valueAggregation->addParameter('order', [$this->getSortType() => $this->getSortOrder()]);
        }

        if ($this->getOption('size')) {
            $valueAggregation->addParameter('size', $this->getOption('size'));
        }

        if ($state->isActive()) {
            foreach ($state->getValue() as $key => $term) {
                $terms = $state->getValue();
                unset($terms[$key]);

                $this->addSubFilterAggregation(
                    $filterAggregation,
                    $childAggregation,
                    $terms,
                    $key
                );
            }
        }

        $this->addSubFilterAggregation(
            $filterAggregation,
            $parentAggregation,
            $state->getValue() ? $state->getValue() : [],
            'all-selected'
        );

        $search->addAggregation($filterAggregation);

        if ($this->getShowZeroChoices()) {
            $search->addAggregation($parentAggregation);
        }
    }

    /**
     * @param array $terms
     *
     * @return BoolQuery
     */
    private function getFilterQuery($terms)
    {
        [$parent, $child, $field] = explode('>', $this->getDocumentField());
        $boolQuery = new BoolQuery();
        foreach ($terms as $groupName => $values) {
            $innerBoolQuery = new BoolQuery();

            foreach ($values as $value) {
                $nestedBoolQuery = new BoolQuery();
                $nestedBoolQuery->add(new TermQuery($field, $value));
                $nestedBoolQuery->add(new TermQuery($this->getNameField(), $groupName));

                $inStockBoolQuery = new BoolQuery();

                $inStockBoolQuery->add(new RangeQuery('variants.stock', ['gte' => 1]), BoolQuery::SHOULD);
                $inStockBoolQuery->add(new TermQuery('variants.is_tracked', false), BoolQuery::SHOULD);

                $childBoolQuery = new BoolQuery();
                $childBoolQuery->add($inStockBoolQuery, BoolQuery::MUST);
                $childBoolQuery->add(new NestedQuery($child, $nestedBoolQuery), BoolQuery::MUST);

                $nestedQuery = new NestedQuery($parent, $childBoolQuery);

                $innerBoolQuery->add(
                    $nestedQuery,

                    BoolQuery::SHOULD
                );
            }

            $boolQuery->add($innerBoolQuery);
        }

        return $boolQuery;
    }
}
