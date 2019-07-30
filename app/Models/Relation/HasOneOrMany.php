<?php

namespace App\Models\Relation;

use App\Models\BaseModel;
use CodeIgniter\Database\BaseBuilder;

abstract class HasOneOrMany extends Relation
{
    /**
     * The foreign key of the parent model.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * The local key of the parent model.
     *
     * @var string
     */
    protected $localKey;

    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $selfJoinCount = 0;

    /**
     * Create a new has one or many relationship instance.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $parent
     * @param  BaseModel  $child
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return void
     */
    public function __construct(BaseBuilder $query, BaseModel $parent, BaseModel $child, $foreignKey, $localKey)
    {
        $this->localKey = $localKey;
        $this->foreignKey = $foreignKey;

        parent::__construct($query, $parent, $child);
    }

    /**
     * Create and return an un-saved instance of the related model.
     *
     * @param array $attributes
     * @return BaseModel
     * @throws \Exception
     */
    public function make(array $attributes = [])
    {
        $instance = $this->related->newModelQuery();

        $instance->fill($attributes);
        $this->setForeignAttributesForCreate($instance);

        return $instance;
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @throws \Exception
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->query->where($this->foreignKey, $this->getParentKey());

            // Consider adding whereNull and whereNotNull methods to query builder!
            $this->query->where("{$this->foreignKey} IS NOT NULL");
        }
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
            $this->foreignKey, $this->getKeys($models, $this->localKey)
        );
    }

    /**
     * Match the eagerly loaded results to their single parents.
     *
     * @param  array   $models
     * @param  array   $results
     * @param  string  $relation
     * @return array
     */
    public function matchOne(array $models, array $results, $relation)
    {
        return $this->matchOneOrMany($models, $results, $relation, 'one');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     *
     * @param  array   $models
     * @param  array  $results
     * @param  string  $relation
     * @return array
     */
    public function matchMany(array $models, array $results, $relation)
    {
        return $this->matchOneOrMany($models, $results, $relation, 'many');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     *
     * @param  array   $models
     * @param  array  $results
     * @param  string  $relation
     * @param  string  $type
     * @return array
     */
    protected function matchOneOrMany(array $models, array $results, $relation, $type)
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            if (isset($dictionary[$key = $model->getAttribute($this->localKey)])) {
                $model->setLoadedRelation(
                    $relation, $this->getRelationValue($dictionary, $key, $type)
                );
            }
        }

        return $models;
    }

    /**
     * Get the value of a relationship by one or many type.
     *
     * @param  array   $dictionary
     * @param  string  $key
     * @param  string  $type
     * @return mixed
     */
    protected function getRelationValue(array $dictionary, $key, $type)
    {
        $value = $dictionary[$key];

        return $type === 'one' ? reset($value) : $value;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  array  $results
     * @return array
     */
    protected function buildDictionary(array $results)
    {
        $foreign = $this->getForeignKeyName();

        $dictionary = [];

        foreach ($results as $key => $item)
        {
            $pair = [$item->{$foreign} => $item];

            $key = key($pair);

            $value = reset($pair);

            if (! isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }

            $dictionary[$key][] = $value;
        }

        return $dictionary;
    }

    /**
     * Find a model by its primary key or return new instance of the related model.
     *
     * @param $id
     * @param array $columns
     * @return BaseModel
     * @throws \Exception
     */
    public function findOrNew($id, $columns = ['*'])
    {
        if (is_null($instance = $this->where($this->related->getKeyName(), $id)->first($columns))) {
            $instance = $this->related->newModelQuery();

            $this->setForeignAttributesForCreate($instance);
        }

        return $instance;
    }

    /**
     * Execute the query and get the first result.
     *
     * @param  array   $columns
     * @return mixed
     */
    public function first($columns = ['*'])
    {
        // We need to call get to do all of our pivoting stuff
        $results = $this->limit(1)->findAll($columns);

        return count($results) > 0 ? $results[0] : null;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     *
     * @param array $attributes
     * @param array $values
     * @return BaseModel
     * @throws \Exception
     */
    public function firstOrNew(array $attributes, array $values = [])
    {
        if (is_null($instance = $this->where($attributes)->first())) {
            $instance = $this->related->newModelQuery();
            $instance->fill($attributes + $values);

            $this->setForeignAttributesForCreate($instance);
        }

        return $instance;
    }

    /**
     * Get the first related record matching the attributes or create it.
     *
     * @param array $attributes
     * @param array $values
     * @return BaseModel
     * @throws \ReflectionException
     */
    public function firstOrCreate(array $attributes, array $values = [])
    {
        if (is_null($instance = $this->where($attributes)->first())) {
            $instance = $this->create($attributes + $values);
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

        $instance->fill($values);
        $instance->save();

        return $instance;
    }

    /**
     * Attach a model instance to the parent model.
     *
     * @param BaseModel $model
     * @return BaseModel|bool
     * @throws \ReflectionException
     */
    public function save(BaseModel $model)
    {
        $this->setForeignAttributesForCreate($model);

        return $model->save() ? $model : false;
    }

    /**
     * Attach a collection of models to the parent instance.
     *
     * @param $models
     * @return mixed
     * @throws \ReflectionException
     */
    public function saveMany($models)
    {
        foreach ($models as $model) {
            $this->save($model);
        }

        return $models;
    }

    /**
     * Create a new instance of the related model.
     *
     * @param array $attributes
     * @return BaseModel
     * @throws \ReflectionException
     */
    public function create(array $attributes = [])
    {
        $instance = $this->related->newModelQuery();

        $instance->fill($attributes);

        $this->setForeignAttributesForCreate($instance);

        $instance->save();

        return $instance;
    }

    /**
     * Create a Collection of new instances of the related model.
     *
     * @param array $records
     * @return array
     * @throws \ReflectionException
     */
    public function createMany(array $records)
    {
        $instances = [];

        foreach ($records as $record) {
            $instances[] = $this->create($record);
        }

        return $instances;
    }

    /**
     * Set the foreign ID for creating a related model.
     *
     * @param BaseModel $model
     * @throws \Exception
     */
    protected function setForeignAttributesForCreate(BaseModel $model)
    {
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     *
     * @return string
     */
    public function getExistenceCompareKey()
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Get the key value of the parent's local key.
     *
     * @return mixed|void
     * @throws \Exception
     */
    public function getParentKey()
    {
        return $this->parent->getAttribute($this->localKey);
    }

    /**
     * Get the fully qualified parent key name.
     *
     * @return string
     */
    public function getQualifiedParentKeyName()
    {
        return $this->parent->qualifyColumn($this->localKey);
    }

    /**
     * Get the plain foreign key.
     *
     * @return string
     */
    public function getForeignKeyName()
    {
        $segments = explode('.', $this->getQualifiedForeignKeyName());

        return end($segments);
    }

    /**
     * Get the foreign key for the relationship.
     *
     * @return string
     */
    public function getQualifiedForeignKeyName()
    {
        return $this->foreignKey;
    }

    /**
     * Get the local key for the relationship.
     *
     * @return string
     */
    public function getLocalKeyName()
    {
        return $this->localKey;
    }
}