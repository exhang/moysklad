<?php

namespace MoySklad\Components\ListQuery;

use MoySklad\Components\Expand;
use MoySklad\Components\Fields\MetaField;
use MoySklad\Components\FilterQuery;
use MoySklad\Components\Specs\QuerySpecs;
use MoySklad\Lists\EntityList;
use MoySklad\MoySklad;
use MoySklad\Repositories\RequestUrlRepository;
use MoySklad\Traits\AccessesSkladInstance;

class ListQuery{
    use AccessesSkladInstance;
    
    protected
        $entityClass,
        $entityName;
    /**
     * @var Expand $expand
     */
    private $expand;
    private $customQueryUrl = null;
    protected static $entityListClass = EntityList::class;

    public function __construct(MoySklad &$skladInstance, $entityClass)
    {
        $this->skladHashCode = $skladInstance->hashCode();
        $this->entityClass = $entityClass;
        $this->entityName = $entityClass::$entityName;
    }

    /**
     * Add expand to query
     * @param Expand $expand
     * @return $this
     */
    public function withExpand(Expand $expand){
        $this->expand = $expand;
        return $this;
    }

    /**
     * Url that will be used instead of default list url
     * @param $customQueryUrl
     */
    public function setCustomQueryUrl($customQueryUrl){
        $this->customQueryUrl = $customQueryUrl;
    }

    /**
     * Attach added expand to specs
     * @param QuerySpecs $querySpecs
     * @return QuerySpecs
     */
    protected function attachExpand(QuerySpecs &$querySpecs){
        $querySpecs->expand = $this->expand;
        return $querySpecs;
    }

    /**
     * Get list of entities
     * @param array $queryParams
     * @return array|EntityList
     */
    public function get(QuerySpecs $querySpecs = null){
        return $this->filter(null, $querySpecs);
    }

    /**
     * Search within list of entities
     * @param string $searchString
     * @param QuerySpecs|null $querySpecs
     * @return EntityList
     */
    public function search($searchString = '', QuerySpecs $querySpecs = null){
        if ( !$querySpecs ) $querySpecs = QuerySpecs::create([]);
        $this->attachExpand($querySpecs);
        return static::recursiveRequest(function(QuerySpecs $querySpecs, $searchString){
            $query = array_merge($querySpecs->toArray(), [
                "search" => $searchString
            ]);
            return $this->getSkladInstance()->getClient()->get($this->getQueryUrl(), $query);
        }, $querySpecs, [
            $searchString
        ]);
    }

    /**
     * Filter within list of entities
     * @param FilterQuery|null $filterQuery
     * @param QuerySpecs|null $querySpecs
     * @return EntityList
     */
    public function filter( FilterQuery $filterQuery = null, QuerySpecs $querySpecs = null ){
        if ( !$querySpecs ) $querySpecs = QuerySpecs::create([]);
        $this->attachExpand($querySpecs);
        return static::recursiveRequest(function(QuerySpecs $querySpecs, FilterQuery $filterQuery = null){
            if ( $filterQuery ){
                $query = array_merge($querySpecs->toArray(), [
                    "filter" => $filterQuery->getRaw()
                ]);
            } else {
                $query = $querySpecs->toArray();
            }
            return $this->getSkladInstance()->getClient()->get($this->getQueryUrl(), $query);
        }, $querySpecs, [
            $filterQuery
        ]);
    }

    /**
     * Used for sending multiple list requests
     * @param callable $method
     * @param QuerySpecs $queryParams
     * @param array $methodArgs
     * @param int $requestCounter
     * @return mixed
     */
    protected function recursiveRequest(
        callable $method, QuerySpecs $queryParams, $methodArgs = [], $requestCounter = 1
    ){
        $res = call_user_func_array($method, array_merge([$queryParams], $methodArgs));
        $resultingMeta = new MetaField($res->meta);
        $resultingObjects = (new static::$entityListClass($this->getSkladInstance(), $res->rows, $resultingMeta))
            ->map(function($e) {
                return new $this->entityClass($this->getSkladInstance(), $e);
            });
        if ( $resultingMeta->size > $queryParams->limit + $queryParams->offset ){
            $newQueryParams = $this->recreateQuerySpecs($queryParams);
            if ( $queryParams->maxResults === 0 || $queryParams->maxResults > $requestCounter * $queryParams->limit ){
                $resultingObjects = $resultingObjects->merge(
                    static::recursiveRequest($method, $newQueryParams, $methodArgs, ++$requestCounter)
                );
            }
        }
        return $resultingObjects;
    }

    /**
     * Get previous QuerySpecs and increase offset
     * @param QuerySpecs $queryParams
     * @return static
     */
    protected function recreateQuerySpecs(QuerySpecs &$queryParams){
          return QuerySpecs::create([
              "offset" => $queryParams->offset + QuerySpecs::MAX_LIST_LIMIT,
              "limit" => $queryParams->limit,
              "maxResults" => $queryParams->maxResults,
              "expand" => $this->expand
          ]);
    }

    /**
     * Get default list query url, or use custom one
     * @return null|string
     */
    protected function getQueryUrl(){
        return (!empty($this->customQueryUrl)?$this->customQueryUrl: RequestUrlRepository::instance()->getListUrl($this->entityName));
    }
}