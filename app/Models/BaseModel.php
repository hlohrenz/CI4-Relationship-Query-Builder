<?php namespace App\Models;

use App\Models\Concerns\GuardsAttributes;
use App\Models\Concerns\HasAttributes;
use App\Models\Concerns\HasRelations;
use App\Models\Concerns\HidesAttributes;
use App\Models\Relation\Pivot;
use App\Models\Relation\Relation;
use Carbon\Traits\Date;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use CodeIgniter\Validation\ValidationInterface;

class BaseModel extends Model implements \JsonSerializable
{
    use HasRelations, HasAttributes, HidesAttributes, GuardsAttributes;

    /**
     * Fields to cast.
     *
     * @var array
     */
    protected $casts = [];

    /**
     * Attributes to append to a model.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * All relations to eager load.
     *
     * @var array
     */
    protected $eagerLoad = [];

    /**
     * Indicates if the model exists.
     *
     * @var bool
     */
    public $exists = false;

    /**
     * BaseModel constructor.
     * @param ConnectionInterface|null $db
     * @param ValidationInterface|null $validation
     */
    public function __construct(ConnectionInterface $db = null, ValidationInterface $validation = null)
    {
        parent::__construct($db, $validation);

        $this->syncOriginal();
    }

    /**
     * Populate model with array of data.
     *
     * @param array $data
     * @return Model
     */
    public function populateFromArray(array $data)
    {
        $classSet = \Closure::bind(function ($key, $value) {
            $this->$key = $value;
        }, $this, get_class($this));
        foreach (array_keys($data) as $key)
        {
            $classSet($key, $data[$key]);
        }
        return $this;
    }

    /**
     * Create a new pivot model instance.
     *
     * @param  \App\Models\BaseModel  $parent
     * @param  array  $attributes
     * @param  string  $table
     * @param  bool  $exists
     * @param  string|null  $using
     * @return \App\Models\Relation\Pivot
     */
    public function newPivot(self $parent, array $attributes, $table, $exists, $using = null)
    {
        return $using ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
            : Pivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    /**
     * How object returns when being serialized.
     *
     * @return array|mixed
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param  array  $attributes
     * @return $this
     *
     * @throws \Exception
     */
    public function fill(array $attributes)
    {
        $totallyGuarded = $this->totallyGuarded();

        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            $key = $this->removeTableFromKey($key);

            // The developers may choose to place some attributes in the "fillable" array
            // which means only those attributes may be set through mass assignment to
            // the model, and all others will just get ignored for security reasons.
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded) {
                throw new \Exception(sprintf(
                    'Add [%s] to fillable property to allow mass assignment on [%s].',
                    $key, get_class($this)
                ));
            }
        }

        return $this;
    }

    /**
     * Fill the model with an array of attributes. Force mass assignment.
     *
     * @param  array  $attributes
     * @return $this
     */
    public function forceFill(array $attributes)
    {
        return static::unguarded(function () use ($attributes) {
            return $this->fill($attributes);
        });
    }

    /**
     * Remove the table name from a given key.
     *
     * @param  string  $key
     * @return string
     */
    protected function removeTableFromKey($key)
    {
        return str_contains($key, '.') ? end(explode('.', $key)) : $key;
    }

    /**
     * Works with the current Query Builder instance to return
     * all results, while optionally limiting them.
     *
     * @param integer $limit
     * @param integer $offset
     *
     * @return array|null
     */
    public function findAll(int $limit = 0, int $offset = 0)
    {
        $this->applyScopes();

        $models = parent::findAll($limit, $offset);

        // If models returned, eager load relations
        if (count($models) > 0) {
            $models = $this->eagerLoadRelations($models);
        }

        // Return new collection of models
        return $models;
    }

    public function eagerLoadRelations(array $models)
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            // For nested eager loads we'll skip loading them here and they will be set as an
            // eager load on the query to retrieve the relation so that they will be eager
            // loaded on that query, because that is where they get hydrated as models.
            if (strpos($name, '.') === false) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }

        return $models;
    }

    protected function eagerLoadRelation(array $models, $name, \Closure $constraints)
    {
        // First we will "back up" the existing where conditions on the query so we can
        // add our eager constraints. Then we will merge the wheres that were on the
        // query back to it in order that any where conditions might be specified.
        $relation = $this->getRelation($name);

        $relation->addEagerConstraints($models);

        $constraints($relation);

        // Once we have the results, we just match those back up to their parent models
        // using the relationship instance. Then we just return the finished arrays
        // of models which have been eagerly hydrated and are readied for return.
        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEager(), $name
        );
    }

    /**
     * Get the relation instance for the given relation name.
     *
     * @param  string  $name
     * @return \App\Models\Relation\Relation
     */
    public function getRelation($name)
    {
        // We want to run a relationship query without any constrains so that we will
        // not have to remove these where clauses manually which gets really hacky
        // and error prone. We don't want constraints because we add eager ones.
        $relation = Relation::noConstraints(function () use ($name) {
            if (! method_exists($this, $name))
            {
                throw RelationNotFoundException::make($this, $name);
            }

            return (new $this())->$name();
        });

        $nested = $this->relationsNestedUnder($name);

        // If there are nested relationships set on the query, we will put those onto
        // the query instances so that they can be handled after this relationship
        // is loaded. In this way they will all trickle down as they are loaded.
        if (count($nested) > 0) {
            $relation->builder()->with($nested);
        }

        return $relation;
    }

    /**
     * Get the deeply nested relations for a given top-level relation.
     *
     * @param  string  $relation
     * @return array
     */
    protected function relationsNestedUnder($relation)
    {
        $nested = [];

        // We are basically looking for any relationships that are nested deeper than
        // the given top-level relationship. We will just check for any relations
        // that start with the given top relations and adds them to our arrays.
        foreach ($this->eagerLoad as $name => $constraints) {
            if ($this->isNestedUnder($relation, $name)) {
                $nested[substr($name, strlen($relation.'.'))] = $constraints;
            }
        }

        return $nested;
    }

    /**
     * Determine if the relationship is nested.
     *
     * @param  string  $relation
     * @param  string  $name
     * @return bool
     */
    protected function isNestedUnder($relation, $name)
    {
        return strpos($name, '.') !== false && str_starts_with($name, $relation.'.');
    }

    /**
     * Begin querying a model with eager loading.
     *
     * @param  array|string  $relations
     * @return BaseBuilder|static
     */
    public function with($relations)
    {
        $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations);

        $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);

        return $this;
    }

    /**
     * Eager load relations on the model.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function load($relations)
    {
        $query = $this->newQueryWithoutRelationships()->with(
            is_string($relations) ? func_get_args() : $relations
        );

        $query->eagerLoadRelations([$this]);

        return $this;
    }

    /**
     * Allow array of where clauses.
     *
     * @param mixed $key
     * @param null $value
     * @param bool|null $escape
     * @return $this|BaseBuilder
     */
    public function where($key, $value = null, bool $escape = null)
    {
        if (is_array($key))
        {
            foreach ($key as $field => $value)
            {
                parent::where($field, $value);
            }

            return $this;
        }

        return parent::where($key, $value, $escape);
    }

    /**
     * Allow generic save to model. If no data is passed, used attributes set on it.
     *
     * @param null $data
     * @return bool
     * @throws \ReflectionException
     */
    public function save($data = null): bool
    {
        if (is_null($data))
        {
            $data = $this->getAttributes();
        }

        $response = parent::save($data);

        $this->syncOriginal();

        return $response;
    }

    /**
     * Insert and return model instance.
     *
     * @param null $data
     * @param bool $returnID
     * @return $this|bool|int|string
     * @throws \ReflectionException
     */
    public function insert($data = null, bool $returnID = true)
    {
        $id = parent::insert($data, $returnID);

        if ($returnID)
        {
            return $id;
        }

        // Let's return the actual result
        $this->setAttribute($this->getKeyName(), $this->getInsertID());

        return $this;
    }

    /**
     * Parse a list of relations into individuals.
     *
     * @param  array  $relations
     * @return array
     */
    protected function parseWithRelations(array $relations)
    {
        $results = [];

        foreach ($relations as $name => $constraints) {
            // If the "name" value is a numeric key, we can assume that no
            // constraints have been specified. We'll just put an empty
            // Closure there, so that we can treat them all the same.
            if (is_numeric($name)) {
                $name = $constraints;
                [$name, $constraints] = strpos($name, ':') !== false
                    ? $this->createSelectWithConstraint($name)
                    : [$name, function () {
                        //
                    }];
            }

            // We need to separate out any nested includes, which allows the developers
            // to load deep relationships using "dots" without stating each level of
            // the relationship with its own key in the array of eager-load names.
            $results = $this->addNestedWiths($name, $results);
            $results[$name] = $constraints;
        }

        return $results;
    }

    /**
     * Create a constraint to select the given columns for the relation.
     *
     * @param  string  $name
     * @return array
     */
    protected function createSelectWithConstraint($name)
    {
        return [explode(':', $name)[0], function ($query) use ($name) {
            $query->select(explode(',', explode(':', $name)[1]));
        }];
    }

    /**
     * Parse the nested relationships in a relation.
     *
     * @param  string  $name
     * @param  array  $results
     * @return array
     */
    protected function addNestedWiths($name, $results)
    {
        $progress = [];

        // If the relation has already been set on the result array, we will not set it
        // again, since that would override any constraints that were already placed
        // on the relationships. We will only set the ones that are not specified.
        foreach (explode('.', $name) as $segment) {
            $progress[] = $segment;
            if (! isset($results[$last = implode('.', $progress)])) {
                $results[$last] = function () {
                    //
                };
            }
        }

        return $results;
    }

    /**
     * Apply global scopes.
     *
     * @return $this
     */
    public function applyScopes()
    {
        if (! $this->scopes) {
            return $this;
        }

        $builder = clone $this;

        foreach ($this->scopes as $identifier => $scope) {
            if (!isset($builder->scopes[$identifier])) {
                continue;
            }

            $builder->callScope(function (self $builder) use ($scope) {
                // If the scope is a Closure we will just go ahead and call the scope with the
                // builder instance. The "callScope" method will properly group the clauses
                // that are added to this query so "where" clauses maintain proper logic.
                if ($scope instanceof \Closure) {
                    $scope($builder);
                }
            });
        }

        return $builder;
    }

    /**
     * Remove a registered global scope.
     *
     * @param  string  $scope
     * @return $this
     */
    public function withoutGlobalScope($scope)
    {
        if (! is_string($scope)) {
            $scope = get_class($scope);
        }

        unset($this->scopes[$scope]);

        $this->removedScopes[] = $scope;

        return $this;
    }

    /**
     * Remove all or passed registered global scopes.
     *
     * @param  array|null  $scopes
     * @return $this
     */
    public function withoutGlobalScopes(array $scopes = null)
    {
        if (! is_array($scopes)) {
            $scopes = array_keys($this->scopes);
        }

        foreach ($scopes as $scope) {
            $this->withoutGlobalScope($scope);
        }

        return $this;
    }

    /**
     * Apply the given scope on the current builder instance.
     *
     * @param  callable  $scope
     * @param  array  $parameters
     * @return mixed
     */
    protected function callScope(callable $scope, $parameters = [])
    {
        array_unshift($parameters, $this);

        // $query = $this->builder();
        // We will keep track of how many wheres are on the query before running the
        // scope so that we can properly group the added scope constraints in the
        // query as their own isolated nested where statement and avoid issues.
        // $originalWhereCount = count($query->QBWhere);

        $result = $scope(...array_values($parameters)) ?? $this;

        /*if (count((array) $query->QBWhere) > $originalWhereCount) {
            $this->addNewWheresWithinGroup($query, $originalWhereCount);
        }*/

        return $result;
    }

    /**
     * Get foreign key for model.
     *
     * @return string
     */
    public function getForeignKey()
    {
        return singular($this->table) . '_id';
    }

    /**
     * @return string
     */
    public function getKeyName()
    {
        // Return id for now
        return $this->primaryKey;
    }

    /**
     * Get the value of the model's primary key.
     *
     * @return mixed|void
     * @throws \Exception
     */
    public function getKey()
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Get table name.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Get a new query builder that doesn't have any global scopes or eager loading.
     *
     * @return BaseModel
     */
    public function newModelQuery()
    {
        return (new $this());
    }

    /**
     * Get a new query builder with no relationships loaded.
     *
     * @return BaseModel
     */
    public function newQueryWithoutRelationships()
    {
        // Add any global scopes if this is feature for the future
        return $this->newModelQuery();
    }

    /**
     * Get created at column field name.
     *
     * @return string
     */
    public function getCreatedAtColumn()
    {
        return $this->createdField;
    }

    /**
     * Get updated at column field name.
     *
     * @return string
     */
    public function getUpdatedAtColumn()
    {
        return $this->updatedField;
    }

    /**
     * Get the table qualified key name.
     *
     * @return string
     */
    public function getQualifiedKeyName()
    {
        return $this->qualifyColumn($this->getKeyName());
    }

    /**
     * Qualify the given column name by the model's table.
     *
     * @param  string  $column
     * @return string
     */
    public function qualifyColumn($column)
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $this->getTable().'.'.$column;
    }

    /**
     * Set if model is using timestamps.
     *
     * @param bool $using
     */
    public function setUseTimestamps($using)
    {
        $this->useTimestamps = $using;
    }

    /**
     * Get if model is using timestamps.
     *
     * @return bool
     */
    public function getUseTimestamps()
    {
        return $this->useTimestamps;
    }

    /**
     * Set validation rules.
     *
     * @param array $rules
     */
    public function setValidationRules(array $rules)
    {
        $this->validationRules = $rules;
    }

    /**
     * Update the model's update timestamp.
     *
     * @return bool
     * @throws \ReflectionException
     */
    public function touch()
    {
        if (! $this->getUseTimestamps()) {
            return false;
        }

        $this->updateTimestamps();

        return $this->save($this->getAttributes());
    }

    /**
     * Update the creation and update timestamps.
     *
     * @return void
     */
    protected function updateTimestamps()
    {
        $time = $this->freshTimestamp();

        if (! $this->isDirty($this->updatedField)) {
            $this->setUpdatedAt($time);
        }

        if (! $this->exists && ! $this->isDirty($this->createdField)) {
            $this->setCreatedAt($time);
        }
    }

    /**
     * Set the value of the "created at" attribute.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function setCreatedAt($value)
    {
        $this->{$this->createdField} = $value;

        return $this;
    }
    /**
     * Set the value of the "updated at" attribute.
     *
     * @param  mixed  $value
     * @return $this
     */
    public function setUpdatedAt($value)
    {
        $this->{$this->updatedField} = $value;

        return $this;
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return Date
     */
    public function freshTimestamp()
    {
        return Date::now();
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return string
     */
    public function freshTimestampString()
    {
        return $this->fromDateTime($this->freshTimestamp());
    }

    /**
     * Is soft deletes enabled?
     *
     * @return bool
     */
    public function isSoftDeleteEnabled()
    {
        return $this->useSoftDeletes;
    }

    /**
     * Get the name of the "deleted at" column.
     *
     * @return string
     */
    public function getDeletedAtColumn()
    {
        return $this->deletedField;
    }

    /**
     * Get the fully qualified "deleted at" column.
     *
     * @return string
     */
    public function getQualifiedDeletedAtColumn()
    {
        return $this->qualifyColumn($this->getDeletedAtColumn());
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param string $key
     * @return mixed|void
     * @throws \Exception
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Dynamically handle calls into the query instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        // Call scopes on query builder
        if (method_exists($this, $scope = 'scope'.ucfirst($method)))
        {
            return $this->callScope([$this, $scope], $parameters);
        }

        return parent::__call($method, $parameters);
    }
}