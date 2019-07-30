<?php

namespace App\Models\Relation;

use App\Models\BaseModel;
use App\Models\Relation\Concerns\InteractsWithPivotTable;
use CodeIgniter\Database\BaseBuilder;

class BelongsToMany extends Relation
{
    use InteractsWithPivotTable;

    /**
     * The intermediate table for the relation.
     *
     * @var string
     */
    protected $table;

    /**
     * The foreign key of the parent model.
     *
     * @var string
     */
    protected $foreignPivotKey;

    /**
     * The associated key of the relation.
     *
     * @var string
     */
    protected $relatedPivotKey;

    /**
     * The key name of the parent model.
     *
     * @var string
     */
    protected $parentKey;

    /**
     * The key name of the related model.
     *
     * @var string
     */
    protected $relatedKey;

    /**
     * The "name" of the relationship.
     *
     * @var string
     */
    protected $relationName;

    /**
     * The pivot table columns to retrieve.
     *
     * @var array
     */
    protected $pivotColumns = [];

    /**
     * Any pivot table restrictions for where clauses.
     *
     * @var array
     */
    protected $pivotWheres = [];

    /**
     * Any pivot table restrictions for whereIn clauses.
     *
     * @var array
     */
    protected $pivotWhereIns = [];

    /**
     * The default values for the pivot columns.
     *
     * @var array
     */
    protected $pivotValues = [];

    /**
     * Indicates if timestamps are available on the pivot table.
     *
     * @var bool
     */
    public $withTimestamps = false;

    /**
     * The custom pivot table column for the created_at timestamp.
     *
     * @var string
     */
    protected $pivotCreatedAt;

    /**
     * The custom pivot table column for the updated_at timestamp.
     *
     * @var string
     */
    protected $pivotUpdatedAt;

    /**
     * The class name of the custom pivot model to use for the relationship.
     *
     * @var string
     */
    protected $using;

    /**
     * The name of the accessor to use for the "pivot" relationship.
     *
     * @var string
     */
    protected $accessor = 'pivot';

    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $selfJoinCount = 0;

    /**
     * Create a new belongs to many relationship instance.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $related
     * @param  BaseModel  $parent
     * @param  string  $table
     * @param  string  $foreignPivotKey
     * @param  string  $relatedPivotKey
     * @param  string  $parentKey
     * @param  string  $relatedKey
     * @param  string  $relationName
     * @return void
     */
    public function __construct(BaseBuilder $query, BaseModel $related, BaseModel $parent, $table, $foreignPivotKey,
                                $relatedPivotKey, $parentKey, $relatedKey, $relationName = null)
    {
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
        $this->relationName = $relationName;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->table = $this->resolveTableName($table);

        parent::__construct($query, $parent, $related);
    }

    /**
     * Attempt to resolve the intermediate table name from the given string.
     *
     * @param  string  $table
     * @return string
     */
    protected function resolveTableName($table)
    {
        if (! strpos($table, '\\') || ! class_exists($table)) {
            return $table;
        }

        $model = new $table;

        if (! $model instanceof BaseModel) {
            return $table;
        }

        return $model->getTable();
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        $this->performJoin();

        if (static::$constraints) {
            $this->addWhereConstraints();
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
            $this->getQualifiedForeignPivotKeyName(),
            $this->getKeys($models, $this->parentKey)
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
            $model->setLoadedRelation($relation, []);
        }

        return $models;
    }

    /**
     * Set the join clause for the relation query.
     *
     * @param  BaseBuilder|null  $query
     * @return $this
     */
    protected function performJoin($query = null)
    {
        $query = $query ?: $this->query;

        // We need to join to the intermediate table on the related model's primary
        // key column with the intermediate table's foreign key for the related
        // model instance. Then we can set the "where" for the parent models.
        $baseTable = $this->related->getTable();

        $key = $baseTable.'.'.$this->relatedKey;

        $query->join($this->table, "{$key} = {$this->getQualifiedRelatedPivotKeyName()}");

        return $this;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array   $models
     * @param  array  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, array $results, $relation)
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have an array dictionary of child objects we can easily match the
        // children back to their parent using the dictionary and the keys on the
        // the parent models. Then we will return the hydrated models back out.
        foreach ($models as $model) {
            if (isset($dictionary[$key = $model->{$this->parentKey}])) {
                $model->setLoadedRelation(
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
        // First we will build a dictionary of child models keyed by the foreign key
        // of the relation so that we will easily and quickly match them to their
        // parents without having a possibly slow inner loops for every models.
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->{$this->accessor}->{$this->foreignPivotKey}][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the class being used for pivot models.
     *
     * @return string
     */
    public function getPivotClass()
    {
        return $this->using ?? Pivot::class;
    }

    /**
     * Specify the custom pivot model to use for the relationship.
     *
     * @param  string  $class
     * @return $this
     */
    public function using($class)
    {
        $this->using = $class;

        return $this;
    }

    /**
     * Specify the custom pivot accessor to use for the relationship.
     *
     * @param  string  $accessor
     * @return $this
     */
    public function as($accessor)
    {
        $this->accessor = $accessor;

        return $this;
    }

    /**
     * Set a where clause for a pivot table column.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed   $value
     * @return $this
     */
    public function wherePivot($column, $operator = '=', $value = null)
    {
        $this->pivotWheres[] = func_get_args();

        return $this->where($this->table.'.'.$column.' '.$operator, $value);
    }

    /**
     * Set a "where in" clause for a pivot table column.
     *
     * @param  string  $column
     * @param  array   $values
     * @return $this
     */
    public function wherePivotIn($column, array $values)
    {
        $this->pivotWhereIns[] = func_get_args();

        return $this->whereIn($this->table.'.'.$column, $values);
    }

    /**
     * Set an "or where" clause for a pivot table column.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed   $value
     * @return $this
     */
    public function orWherePivot($column, $operator = '=', $value = null)
    {
        return $this->orWhere($column.' '.$operator, $value);
    }

    /**
     * Set an "or where in" clause for a pivot table column.
     *
     * @param  string  $column
     * @param  array   $values
     * @return $this
     */
    public function orWherePivotIn($column, array $values)
    {
        return $this->orWhereIn($column, $values);
    }

    /**
     * Find a related model by its primary key or return new instance of the related model.
     *
     * @param  mixed  $id
     * @return \App\Models\BaseModel
     */
    public function findOrNew($id)
    {
        if (is_null($instance = $this->find($id))) {
            $instance = new $this->related();
        }

        return $instance;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     *
     * @param  array  $attributes
     * @return \App\Models\BaseModel
     */
    public function firstOrNew(array $attributes)
    {
        if (is_null($instance = $this->where($attributes)->first())) {
            $instance = new $this->related();
            $instance->fill($attributes);
        }

        return $instance;
    }

    /**
     * Get the first related record matching the attributes or create it.
     *
     * @param array $attributes
     * @param array $joining
     * @param bool $touch
     * @return mixed
     * @throws \ReflectionException
     */
    public function firstOrCreate(array $attributes, array $joining = [], $touch = true)
    {
        if (is_null($instance = $this->where($attributes)->first())) {
            $instance = $this->create($attributes, $joining, $touch);
        }

        return $instance;
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     *
     * @param array $attributes
     * @param array $values
     * @param array $joining
     * @param bool $touch
     * @return mixed
     * @throws \ReflectionException
     */
    public function updateOrCreate(array $attributes, array $values = [], array $joining = [], $touch = true)
    {
        if (is_null($instance = $this->where($attributes)->first())) {
            return $this->create($values, $joining, $touch);
        }

        $instance->fill($values);
        $instance->save();

        return $instance;
    }

    /**
     * Find a related model by its primary key.
     *
     * @param  mixed  $id
     * @param  array  $columns
     * @return \App\Models\BaseModel|[\App\Models\BaseModel]|null
     */
    public function find($id, $columns = ['*'])
    {
        return is_array($id) ? $this->findMany($id, $columns) : $this->where(
            $this->getRelated()->getQualifiedKeyName(), $id
        )->first($columns);
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * @param  mixed  $ids
     * @param  array  $columns
     * @return [\App\Models\BaseModel]
     */
    public function findMany($ids, $columns = ['*'])
    {
        return empty($ids) ? [] : $this->whereIn(
            $this->getRelated()->getQualifiedKeyName(), $ids
        )->findAll($columns);
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
     * Create a new instance of the related model.
     *
     * @param array $attributes
     * @param array $joining
     * @param bool $touch
     * @return mixed
     * @throws \ReflectionException
     */
    public function create(array $attributes = [], array $joining = [], $touch = true)
    {
        $instance = new $this->related();

        $instance->fill($attributes);

        // Once we save the related model, we need to attach it to the base model via
        // through intermediate table so we'll use the existing "attach" method to
        // accomplish this which will insert the record and any more attributes.
        $instance->save($instance->getAttributes());

        $this->attach($instance->getInsertID(), $joining, $touch);

        return $instance;
    }

    /**
     * Save a new model and attach it to the parent model.
     *
     * @param BaseModel $model
     * @param array $pivotAttributes
     * @param bool $touch
     * @return BaseModel
     * @throws \ReflectionException
     */
    public function save(BaseModel $model, array $pivotAttributes = [], $touch = true)
    {
        $model->save();

        $this->attach($model, $pivotAttributes, $touch);

        return $model;
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return ! is_null($this->parent->{$this->parentKey})
            ? $this->findAll()
            : [];
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param array $columns
     * @return array|null
     */
    public function findAll($columns = ['*'])
    {
        // First we'll add the proper select columns onto the query so it is run with
        // the proper columns. Then, we will get the results and hydrate out pivot
        // models with the result of those columns as a separate model relation.
        $builder = $this->related->applyScopes();

        $columns = count($builder->builder()->QBSelect) > 0 ? [] : $columns;

        $models = $builder->select(
            $this->shouldSelect($columns)
        )->findAll();

        $this->hydratePivotRelation($models);

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded. This will solve the
        // n + 1 query problem for the developer and also increase performance.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $models;
    }

    /**
     * Get the select columns for the relation query.
     *
     * @param  array  $columns
     * @return array
     */
    protected function shouldSelect(array $columns = ['*'])
    {
        if ($columns == ['*']) {
            $columns = [$this->related->getTable().'.*'];
        }

        return array_merge($columns, $this->aliasedPivotColumns());
    }

    /**
     * Hydrate the pivot table relationship on the models.
     *
     * @param  array  $models
     * @return void
     */
    protected function hydratePivotRelation(array $models)
    {
        // To hydrate the pivot relationship, we will just gather the pivot attributes
        // and create a new Pivot model, which is basically a dynamic model that we
        // will set the attributes, table, and connections on it so it will work.
        foreach ($models as $model) {
            $model->setLoadedRelation($this->accessor, $this->newExistingPivot(
                $this->migratePivotAttributes($model)
            ));
        }
    }

    /**
     * Get the pivot attributes from a model.
     *
     * @param  \App\Models\BaseModel  $model
     * @return array
     */
    protected function migratePivotAttributes(BaseModel $model)
    {
        $values = [];

        foreach ($model->getAttributes() as $key => $value) {
            // To get the pivots attributes we will just take any of the attributes which
            // begin with "pivot_" and add those to this arrays, as well as unsetting
            // them from the parent's models since they exist in a different table.
            if (strpos($key, 'pivot_') === 0) {
                $values[substr($key, 6)] = $value;

                $model->unsetAttribute($key);
            }
        }

        return $values;
    }

    /**
     * If we're touching the parent model, touch.
     *
     * @throws \ReflectionException
     */
    public function touchIfTouching()
    {
        if ($this->touchingParent()) {
            $this->getParent()->touch();
        }

        if ($this->getParent()->touches($this->relationName)) {
            $this->touch();
        }
    }

    /**
     * Determine if we should touch the parent on sync.
     *
     * @return bool
     */
    protected function touchingParent()
    {
        return $this->getRelated()->touches($this->guessInverseRelation());
    }

    /**
     * Attempt to guess the name of the inverse of the relation.
     *
     * @return string
     */
    protected function guessInverseRelation()
    {
        return str_camel(str_plural_studly(class_basename($this->getParent())));
    }

    /**
     * Touch all of the related models for the relationship.
     *
     * E.g.: Touch all roles associated with this user.
     *
     * @return void
     */
    public function touch()
    {
        $key = $this->getRelated()->getKeyName();

        $columns = [
            $this->related->getUpdatedAtColumn() => $this->related->freshTimestampString(),
        ];

        // If we actually have IDs for the relation, we will run the query to update all
        // the related model's timestamps, to make sure these all reflect the changes
        // to the parent models. This will help us keep any caching synced up here.
        if (count($ids = $this->allRelatedIds()) > 0) {
            $this->getRelated()->newQueryWithoutRelationships()->whereIn($key, $ids)->update($columns);
        }
    }

    /**
     * Get all of the IDs for the related models.
     *
     * @return array
     */
    public function allRelatedIds()
    {
        return $this->newPivotQuery()->findColumn($this->relatedPivotKey);
    }

    /**
     * Get the pivot columns for the relation.
     *
     * "pivot_" is prefixed ot each column for easy removal later.
     *
     * @return array
     */
    protected function aliasedPivotColumns()
    {
        $defaults = [$this->foreignPivotKey, $this->relatedPivotKey];
        $columns = array_merge($defaults, $this->pivotColumns);
        $return = [];

        foreach ($columns as $column)
        {
            $fieldName = $this->table.'.'.$column.' as pivot_'.$column;

            if (! in_array($fieldName, $return))
            {
                $return[] = $fieldName;
            }
        }

        return $return;
    }

    /**
     * Set the where clause for the relation query.
     *
     * @return $this
     */
    protected function addWhereConstraints()
    {
        $this->query->where(
            $this->getQualifiedForeignPivotKeyName(), $this->parent->{$this->parentKey}
        );

        return $this;
    }

    /**
     * Get the fully qualified foreign key for the relation.
     *
     * @return string
     */
    public function getQualifiedForeignPivotKeyName()
    {
        return $this->table.'.'.$this->foreignPivotKey;
    }

    /**
     * Get the fully qualified "related key" for the relation.
     *
     * @return string
     */
    public function getQualifiedRelatedPivotKeyName()
    {
        return $this->table.'.'.$this->relatedPivotKey;
    }

    /**
     * Specify that the pivot table has creation and update timestamps.
     *
     * @param  mixed  $createdAt
     * @param  mixed  $updatedAt
     * @return $this
     */
    public function withTimestamps($createdAt = null, $updatedAt = null)
    {
        $this->withTimestamps = true;

        $this->pivotCreatedAt = $createdAt;
        $this->pivotUpdatedAt = $updatedAt;

        return $this->withPivot($this->createdAt(), $this->updatedAt());
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function createdAt()
    {
        return $this->pivotCreatedAt ?: $this->parent->getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function updatedAt()
    {
        return $this->pivotUpdatedAt ?: $this->parent->getUpdatedAtColumn();
    }
}