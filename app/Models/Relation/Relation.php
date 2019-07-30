<?php

namespace App\Models\Relation;

use App\Models\BaseModel;
use App\Support\Traits\ForwardsCalls;
use CodeIgniter\Database\BaseBuilder;

abstract class Relation
{
    use ForwardsCalls;

    /**
     * The Eloquent query builder instance.
     *
     * @var BaseBuilder
     */
    protected $query;

    /**
     * The parent model instance.
     *
     * @var BaseModel
     */
    protected $parent;

    /**
     * The related model instance.
     *
     * @var BaseModel
     */
    protected $related;

    /**
     * Indicates if the relation is adding constraints.
     *
     * @var bool
     */
    protected static $constraints = true;

    /**
     * Create a new relation instance.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $parent
     * @param  BaseModel  $related
     * @return void
     */
    public function __construct(BaseBuilder $query, BaseModel $parent, BaseModel $related)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $related;

        $this->addConstraints();
    }

    /**
     * Run a callback with constraints disabled on the relation.
     *
     * @param  \Closure  $callback
     * @return mixed
     */
    public static function noConstraints(\Closure $callback)
    {
        $previous = static::$constraints;
        static::$constraints = false;
        // When resetting the relation where clause, we want to shift the first element
        // off of the bindings, leaving only the constraints that the developers put
        // as "extra" on the relationships, and not original relation constraints.
        try {
            return call_user_func($callback);
        } finally {
            static::$constraints = $previous;
        }
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    abstract public function addConstraints();

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    abstract public function addEagerConstraints(array $models);

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array   $models
     * @param  string  $relation
     * @return array
     */
    abstract public function initRelation(array $models, $relation);

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array   $models
     * @param  array   $results
     * @param  string  $relation
     * @return array
     */
    abstract public function match(array $models, array $results, $relation);

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    abstract public function getResults();

    /**
     * Get the relationship for eager loading.
     *
     * @return array|null
     */
    public function getEager()
    {
        return $this->findAll();
    }

    /**
     * Get the relationship for eager loading.
     *
     * @return array|null
     */
    public function findAll()
    {
        return $this->related->findAll();
    }

    /**
     * Touch all of the related models for the relationship.
     *
     * @throws \ReflectionException
     */
    public function touch()
    {
        $model = $this->getRelated();

        // if (! $model::isIgnoringTouch()) {
            $this->rawUpdate([
                $model->getUpdatedAtColumn() => $model->freshTimestampString(),
            ]);
        // }
    }

    /**
     * Run a raw update against the base query.
     *
     * @param array $attributes
     * @return bool
     * @throws \ReflectionException
     */
    public function rawUpdate(array $attributes = [])
    {
        return $this->related->withoutGlobalScopes()->update($attributes);
    }

    /**
     * Get all of the primary keys for an array of models.
     *
     * @param  array   $models
     * @param  string  $key
     * @return array
     */
    protected function getKeys(array $models, $key = null)
    {
        $keys = [];
        foreach ($models as $model) {
            $keys[] = $key ? $model->$key : $model->primaryKey;
        }

        return $keys;
    }

    /**
     * Get the underlying query for the relation.
     *
     * @return BaseBuilder
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Get the parent model of the relation.
     *
     * @return \App\Models\BaseModel
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Get the related model of the relation.
     *
     * @return \App\Models\BaseModel
     */
    public function getRelated()
    {
        return $this->related;
    }

    /**
     * Handle dynamic method calls to the relationship.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        $result = null;

        if (method_exists($this->query, $method))
        {
            $result = $this->forwardCallTo($this->query, $method, $parameters);
        }
        elseif (method_exists($this->related, $method))
        {
            $result = $this->forwardCallTo($this->related, $method, $parameters);
        }

        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     *
     * @return void
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }
}