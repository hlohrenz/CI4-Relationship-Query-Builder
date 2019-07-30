<?php

namespace App\Models\Relation;

use App\Models\BaseModel;
use CodeIgniter\Database\BaseBuilder;

class HasManyThrough extends Relation
{
    /**
     * The "through" parent model instance.
     *
     * @var \App\Models\BaseModel
     */
    protected $throughParent;

    /**
     * The far parent model instance.
     *
     * @var \App\Models\BaseModel
     */
    protected $farParent;

    /**
     * The near key on the relationship.
     *
     * @var string
     */
    protected $firstKey;

    /**
     * The far key on the relationship.
     *
     * @var string
     */
    protected $secondKey;

    /**
     * The local key on the relationship.
     *
     * @var string
     */
    protected $localKey;

    /**
     * The local key on the intermediary model.
     *
     * @var string
     */
    protected $secondLocalKey;

    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $selfJoinCount = 0;

    /**
     * Create a new has many through relationship instance.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $farParent
     * @param  BaseModel  $throughParent
     * @param  string  $firstKey
     * @param  string  $secondKey
     * @param  string  $localKey
     * @param  string  $secondLocalKey
     * @return void
     */
    public function __construct(BaseBuilder $query, BaseModel $farParent, BaseModel $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey)
    {
        $this->localKey = $localKey;
        $this->firstKey = $firstKey;
        $this->secondKey = $secondKey;
        $this->farParent = $farParent;
        $this->throughParent = $throughParent;
        $this->secondLocalKey = $secondLocalKey;

        parent::__construct($query, $throughParent, $farParent);
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        $localValue = $this->farParent[$this->localKey];

        $this->performJoin();

        if (static::$constraints) {
            $this->query->where($this->getQualifiedFirstKeyName(), $localValue);
        }
    }

    /**
     * Set the join clause on the query.
     *
     * @param  BaseBuilder|null  $query
     * @return void
     */
    protected function performJoin(BaseBuilder $query = null)
    {
        $query = $query ?: $this->query;

        $farKey = $this->getQualifiedFarKeyName();

        $query->join($this->throughParent->getTable(), $this->getQualifiedParentKeyName().' = '.$farKey);

        if ($this->throughParentSoftDeletes()) {
            $query->where($this->throughParent->getQualifiedDeletedAtColumn(), 0);
        }
    }

    /**
     * Get the fully qualified parent key name.
     *
     * @return string
     */
    public function getQualifiedParentKeyName()
    {
        return $this->parent->qualifyColumn($this->secondLocalKey);
    }

    /**
     * Determine whether "through" parent of the relation uses Soft Deletes.
     *
     * @return bool
     */
    public function throughParentSoftDeletes()
    {
        return $this->throughParent->isSoftDeleteEnabled();
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $this->query->whereIn(
            $this->getQualifiedFirstKeyName(), $this->getKeys($models, $this->localKey)
        );
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array   $models
     * @param  string  $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, []);
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array   $models
     * @param  array   $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, array $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            if (isset($dictionary[$key = $model->getAttribute($this->localKey)])) {
                $model->setRelation(
                    $relation, $dictionary[$key]
                );
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  array  $results
     * @return array
     */
    protected function buildDictionary(array $results)
    {
        $dictionary = [];

        // First we will create a dictionary of models keyed by the foreign key of the
        // relationship as this will allow us to quickly access all of the related
        // models without having to do nested looping which will be quite slow.
        foreach ($results as $result) {
            $dictionary[$result->laravel_through_key][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     *
     * @param array $attributes
     * @return BaseModel
     * @throws \Exception
     */
    public function firstOrNew(array $attributes)
    {
        if (is_null($instance = $this->where($attributes)->first())) {
            $instance = $this->related->newModelQuery();
            $instance->fill($attributes);
        }

        return $instance;
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     *
     * @param array $attributes
     * @param array $values
     * @return BaseModel
     * @throws \ReflectionException
     */
    public function updateOrCreate(array $attributes, array $values = [])
    {
        $instance = $this->firstOrNew($attributes);

        $instance->fill($values)->save();

        return $instance;
    }

    /**
     * Execute the query and get the first related model.
     *
     * @param  array   $columns
     * @return mixed
     */
    public function first($columns = ['*'])
    {
        $results = $this->limit(1)->findAll($columns);

        return count($results) > 0 ? $results[0] : null;
    }

    /**
     * Find a related model by its primary key.
     *
     * @param  mixed  $id
     * @param  array  $columns
     * @return BaseModel|array|null
     */
    public function find($id, $columns = ['*'])
    {
        if (is_array($id)) {
            return $this->findMany($id, $columns);
        }

        return $this->where(
            $this->getRelated()->getQualifiedKeyName(), $id
        )->first($columns);
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * @param  mixed  $ids
     * @param  array  $columns
     * @return array
     */
    public function findMany($ids, $columns = ['*'])
    {
        if (empty($ids)) {
            return [];
        }

        return $this->whereIn(
            $this->getRelated()->getQualifiedKeyName(), $ids
        )->findAll($columns);
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return ! is_null($this->farParent->{$this->localKey})
            ? $this->findAll()
            : [];
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return array
     */
    public function findAll($columns = ['*'])
    {
        $builder = $this->prepareQueryBuilder($columns);

        $models = $builder->findAll();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded. This will solve the
        // n + 1 query problem for the developer and also increase performance.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $models;
    }

    /**
     * Set the select clause for the relation query.
     *
     * @param  array  $columns
     * @return array
     */
    protected function shouldSelect(array $columns = ['*'])
    {
        if ($columns == ['*']) {
            $columns = [$this->related->getTable().'.*'];
        }

        return array_merge($columns, [$this->getQualifiedFirstKeyName().' as through_key']);
    }

    /**
     * Prepare the query builder for query execution.
     *
     * @param  array  $columns
     * @return BaseModel
     */
    protected function prepareQueryBuilder($columns = ['*'])
    {
        $builder = $this->related->applyScopes();

        $columns = count($builder->builder()->QBSelect) > 0 ? [] : $columns;

        return $builder->select(
            $this->shouldSelect($columns)
        );
    }

    /**
     * Get the qualified foreign key on the related model.
     *
     * @return string
     */
    public function getQualifiedFarKeyName()
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Get the foreign key on the "through" model.
     *
     * @return string
     */
    public function getFirstKeyName()
    {
        return $this->firstKey;
    }

    /**
     * Get the qualified foreign key on the "through" model.
     *
     * @return string
     */
    public function getQualifiedFirstKeyName()
    {
        return $this->throughParent->qualifyColumn($this->firstKey);
    }

    /**
     * Get the foreign key on the related model.
     *
     * @return string
     */
    public function getForeignKeyName()
    {
        return $this->secondKey;
    }

    /**
     * Get the qualified foreign key on the related model.
     *
     * @return string
     */
    public function getQualifiedForeignKeyName()
    {
        return $this->related->qualifyColumn($this->secondKey);
    }

    /**
     * Get the local key on the far parent model.
     *
     * @return string
     */
    public function getLocalKeyName()
    {
        return $this->localKey;
    }

    /**
     * Get the qualified local key on the far parent model.
     *
     * @return string
     */
    public function getQualifiedLocalKeyName()
    {
        return $this->farParent->qualifyColumn($this->localKey);
    }

    /**
     * Get the local key on the intermediary model.
     *
     * @return string
     */
    public function getSecondLocalKeyName()
    {
        return $this->secondLocalKey;
    }
}