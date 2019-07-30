<?php

namespace App\Models\Concerns;

use App\Models\BaseModel;
use App\Models\Relation\BelongsTo;
use App\Models\Relation\BelongsToMany;
use App\Models\Relation\HasMany;
use App\Models\Relation\HasManyThrough;
use App\Models\Relation\HasOne;
use CodeIgniter\Database\BaseBuilder;

trait HasRelations
{
    /**
     * The loaded relationships for the model.
     *
     * @var array
     */
    protected $relations = [];

    /**
     * The relationships that should be touched on save.
     *
     * @var array
     */
    protected $touches = [];

    /**
     * Define a one-to-one relationship.
     *
     * @param  string  $relation
     * @param  string  $related
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return HasOne
     */
    public function hasOne(string $relation, string $related, string $foreignKey = null, string $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasOne($instance->builder(), $this, $instance,$instance->getTable().'.'.$foreignKey, $localKey);
    }

    /**
     * Instantiate a new HasOne relationship.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $parent
     * @param  BaseModel  $related
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return HasOne
     */
    protected function newHasOne(BaseBuilder $query, BaseModel $parent, BaseModel $related, string $foreignKey, string $localKey)
    {
        return new HasOne($query, $parent, $related, $foreignKey, $localKey);
    }

    /**
     * Define an inverse one-to-one or many relationship.
     *
     * @param  string  $relation
     * @param  string  $related
     * @param  string  $foreignKey
     * @param  string  $ownerKey
     * @return BelongsTo
     */
    public function belongsTo(string $relation, string $related, string $foreignKey = null, string $ownerKey = null)
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        $instance = $this->newRelatedInstance($related);

        // If no foreign key was supplied, we can use a backtrace to guess the proper
        // foreign key name by using the name of the relationship function, which
        // when combined with an "_id" should conventionally match the columns.
        if (is_null($foreignKey)) {
            $foreignKey = str_snake($relation).'_'.$instance->getKeyName();
        }

        // Once we have the foreign key names, we'll just create a new Eloquent query
        // for the related models and returns the relationship instance which will
        // actually be responsible for retrieving and hydrating every relations.
        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return $this->newBelongsTo(
            $instance->builder(), $instance, $this, $foreignKey, $ownerKey, $relation
        );
    }

    /**
     * Instantiate a new BelongsTo relationship.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $parent
     * @param  BaseModel  $child
     * @param  string  $foreignKey
     * @param  string  $ownerKey
     * @param  string  $relation
     * @return BelongsTo
     */
    protected function newBelongsTo(BaseBuilder $query, BaseModel $parent, BaseModel $child, string $foreignKey, string $ownerKey, string $relation)
    {
        return new BelongsTo($query, $parent, $child, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Define a one-to-many relationship.
     *
     * @param  string  $relation
     * @param  string  $related
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return HasMany
     */
    public function hasMany(string $relation, string $related, string $foreignKey = null, string $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasMany(
            $instance->builder(), $this, $instance,$instance->getTable().'.'.$foreignKey, $localKey
        );
    }

    /**
     * Instantiate a new HasMany relationship.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $parent
     * @param  BaseModel  $related
     * @param  string  $foreignKey
     * @param  string  $localKey
     * @return HasMany
     */
    protected function newHasMany(BaseBuilder $query, BaseModel $parent, BaseModel $related, string $foreignKey, string $localKey)
    {
        return new HasMany($query, $parent, $related, $foreignKey, $localKey);
    }

    /**
     * Define a has-many-through relationship.
     *
     * @param  string  $related
     * @param  string  $through
     * @param  string|null  $firstKey
     * @param  string|null  $secondKey
     * @param  string|null  $localKey
     * @param  string|null  $secondLocalKey
     * @return HasManyThrough
     */
    public function hasManyThrough(string $related, string $through, string $firstKey = null, string $secondKey = null, string $localKey = null, string $secondLocalKey = null)
    {
        $through = new $through;

        $firstKey = $firstKey ?: $this->getForeignKey();
        $secondKey = $secondKey ?: $through->getForeignKey();

        return $this->newHasManyThrough(
            $this->newRelatedInstance($related)->builder(), $this, $through,
            $firstKey, $secondKey, $localKey ?: $this->getKeyName(),
            $secondLocalKey ?: $through->getKeyName()
        );
    }

    /**
     * Instantiate a new HasManyThrough relationship.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $farParent
     * @param  BaseModel  $throughParent
     * @param  string  $firstKey
     * @param  string  $secondKey
     * @param  string  $localKey
     * @param  string  $secondLocalKey
     * @return HasManyThrough
     */
    protected function newHasManyThrough(BaseBuilder $query, BaseModel $farParent, BaseModel $throughParent, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey)
    {
        return new HasManyThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Define a many-to-many relationship.
     *
     * @param  string  $relation
     * @param  string  $related
     * @param  string  $table
     * @param  string  $foreignPivotKey
     * @param  string  $relatedPivotKey
     * @param  string  $parentKey
     * @param  string  $relatedKey
     * @return \App\Models\Relation\BelongsToMany
     */
    public function belongsToMany(string $relation, string $related, string $table = null, string $foreignPivotKey = null, string $relatedPivotKey = null,
                                  string $parentKey = null, string $relatedKey = null)
    {
        // First, we'll need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we'll make the query
        // instances as well as the relationship instances we need for this.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // If no table name was provided, we can guess it by concatenating the two
        // models using underscores in alphabetical order. The two model names
        // are transformed to snake case from their default CamelCase also.
        if (is_null($table)) {
            $table = $this->joiningTable($related, $instance);
        }

        return $this->newBelongsToMany(
            $instance->builder(), $instance, $this, $table, $foreignPivotKey,
            $relatedPivotKey, $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(), $relation
        );
    }

    /**
     * Instantiate a new BelongsToMany relationship.
     *
     * @param  BaseBuilder  $query
     * @param  \App\Models\BaseModel  $related
     * @param  \App\Models\BaseModel  $parent
     * @param  string  $table
     * @param  string  $foreignPivotKey
     * @param  string  $relatedPivotKey
     * @param  string  $parentKey
     * @param  string  $relatedKey
     * @param  string  $relationName
     * @return \App\Models\Relation\BelongsToMany
     */
    protected function newBelongsToMany(BaseBuilder $query, BaseModel $related, BaseModel $parent, string $table, string $foreignPivotKey, string $relatedPivotKey,
                                        string $parentKey, string $relatedKey, string $relationName = null)
    {
        return new BelongsToMany($query, $related, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    /**
     * Create a new model instance for a related model.
     *
     * @param  string  $class
     * @return mixed
     */
    protected function newRelatedInstance(string $class)
    {
        return new $class();
    }

    /**
     * Get the joining table name for a many-to-many relation.
     *
     * @param  string  $related
     * @param  \App\Models\BaseModel|null  $instance
     * @return string
     */
    public function joiningTable(string $related, $instance = null)
    {
        // The joining table name, by convention, is simply the snake cased models
        // sorted alphabetically and concatenated with an underscore, so we can
        // just sort the models and join them together to get the table name.
        $segments = [
            $instance ? $instance->joiningTableSegment()
                : str_snake(class_basename($related)),
            $this->joiningTableSegment(),
        ];
        // Now that we have the model names in an array we can just sort them and
        // use the implode function to join them together with an underscores,
        // which is typically used by convention within the database system.
        sort($segments);
        return strtolower(implode('_', $segments));
    }

    /**
     * Get this model's half of the intermediate table name for belongsToMany relationships.
     *
     * @return string
     */
    public function joiningTableSegment()
    {
        return str_snake(class_basename($this));
    }

    /**
     * Determine if the model touches a given relation.
     *
     * @param  string  $relation
     * @return bool
     */
    public function touches(string $relation)
    {
        return in_array($relation, $this->touches);
    }

    /**
     * Determine if the given relation is loaded.
     *
     * @param  string  $key
     * @return bool
     */
    public function relationLoaded(string $key)
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Get the relationships that are touched on save.
     *
     * @return array
     */
    public function getTouchedRelations()
    {
        return $this->touches;
    }

    /**
     * Set the relationships that are touched on save.
     *
     * @param  array  $touches
     * @return $this
     */
    public function setTouchedRelations(array $touches)
    {
        $this->touches = $touches;

        return $this;
    }

    /**
     * Get all the loaded relations for the instance.
     *
     * @return array
     */
    public function getLoadedRelations()
    {
        return $this->relations;
    }

    /**
     * Get a specified relationship.
     *
     * @param  string  $relation
     * @return mixed
     */
    public function getLoadedRelation(string $relation)
    {
        return $this->relations[$relation];
    }

    /**
     * Set the given relationship on the model.
     *
     * @param  string  $relation
     * @param  mixed  $value
     * @return $this
     */
    public function setLoadedRelation(string $relation, $value)
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    /**
     * Unset a loaded relationship.
     *
     * @param  string  $relation
     * @return $this
     */
    public function unsetLoadedRelation(string $relation)
    {
        unset($this->relations[$relation]);

        return $this;
    }

    /**
     * Set the entire relations array on the model.
     *
     * @param  array  $relations
     * @return $this
     */
    public function setLoadedRelations(array $relations)
    {
        $this->relations = $relations;

        return $this;
    }
}