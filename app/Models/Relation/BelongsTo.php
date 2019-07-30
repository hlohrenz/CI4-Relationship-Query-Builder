<?php

namespace App\Models\Relation;

use App\Models\BaseModel;
use App\Models\Relation\Concerns\SupportsDefaultModels;
use CodeIgniter\Database\BaseBuilder;

class BelongsTo extends Relation
{
    use SupportsDefaultModels;

    /**
     * The child model instance of the relation.
     */
    protected $child;

    /**
     * The foreign key of the parent model.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * The associated key on the parent model.
     *
     * @var string
     */
    protected $ownerKey;

    /**
     * The name of the relationship.
     *
     * @var string
     */
    protected $relationName;

    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $selfJoinCount = 0;

    /**
     * Create a new belongs to relationship instance.
     *
     * @param  BaseBuilder  $query
     * @param  BaseModel  $parent
     * @param  BaseModel  $child
     * @param  string  $foreignKey
     * @param  string  $ownerKey
     * @param  string  $relationName
     *
     * @return void
     */
    public function __construct(BaseBuilder $query, BaseModel $parent, BaseModel $child, $foreignKey, $ownerKey, $relationName)
    {
        $this->ownerKey = $ownerKey;
        $this->relationName = $relationName;
        $this->foreignKey = $foreignKey;

        // In the underlying base relationship class, this variable is referred to as
        // the "parent" since most relationships are not inversed. But, since this
        // one is we will create a "child" variable for much better readability.
        $this->child = $child;

        parent::__construct($query, $child, $parent);
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        if (is_null($this->child->{$this->foreignKey})) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->related->first() ?: $this->getDefaultFor($this->parent);
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            // For belongs to relationships, which are essentially the inverse of has one
            // or has many relationships, we need to actually query on the primary key
            // of the related models matching on the foreign key that's on a parent.
            $table = $this->related->getTable();

            $this->query->where($table.'.'.$this->ownerKey, $this->child->{$this->foreignKey});
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
        // We'll grab the primary key name of the related models since it could be set to
        // a non-standard name and not "id". We will then construct the constraint for
        // our eagerly loading query so it returns the proper models from execution.
        $key = $this->related->getTable().'.'.$this->ownerKey;

        $this->query->whereIn($key, $this->getEagerModelKeys($models));
    }

    /**
     * Gather the keys from an array of related models.
     *
     * @param  array  $models
     * @return array
     */
    protected function getEagerModelKeys(array $models)
    {
        $keys = [];

        // First we need to gather all of the keys from the parent models so we know what
        // to query for via the eager loading query. We will add them to an array then
        // execute a "where in" statement to gather up all of those related records.
        foreach ($models as $model) {
            if (! is_null($value = $model->{$this->foreignKey})) {
                $keys[] = $value;
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
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
            $model->setLoadedRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
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
        $foreign = $this->foreignKey;
        $owner = $this->ownerKey;

        // First we will get to build a dictionary of the child models by their primary
        // key of the relationship, then we can easily match the children back onto
        // the parents using that dictionary and the primary key of the children.
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->getAttribute($owner)] = $result;
        }

        // Once we have the dictionary constructed, we can loop through all the parents
        // and match back onto their children using these keys of the dictionary and
        // the primary key of the children to map them onto the correct instances.
        foreach ($models as $model) {
            if (isset($dictionary[$model->{$foreign}])) {
                $model->setLoadedRelation($relation, $dictionary[$model->{$foreign}]);
            }
        }

        return $models;
    }

    /**
     * Update the parent model on the relationship.
     *
     * @param  array  $attributes
     * @return mixed
     */
    public function update(array $attributes)
    {
        return $this->getResults()->fill($attributes)->save();
    }

    /**
     * Associate the model instance to the given parent.
     *
     * @param $model
     * @return BaseModel
     * @throws \Exception
     */
    public function associate($model)
    {
        $ownerKey = $model instanceof BaseModel ? $model->getAttribute($this->ownerKey) : $model;

        $this->child->setAttribute($this->foreignKey, $ownerKey);

        if ($model instanceof BaseModel) {
            $this->child->setLoadedRelation($this->relationName, $model);
        } elseif ($this->child->isDirty($this->foreignKey)) {
            $this->child->unsetLoadedRelation($this->relationName);
        }

        return $this->child;
    }

    /**
     * Dissociate previously associated model from the given parent.
     *
     * @return \App\Models\BaseModel
     */
    public function dissociate()
    {
        $this->child->setAttribute($this->foreignKey, null);

        return $this->child->setLoadedRelation($this->relationName, null);
    }

    /**
     * Make a new related instance for the given model.
     *
     * @param  \App\Models\BaseModel  $parent
     * @return \App\Models\BaseModel
     */
    protected function newRelatedInstanceFor(BaseModel $parent)
    {
        return new $this->related();
    }

    /**
     * Get the child of the relationship.
     *
     * @return \App\Models\BaseModel
     */
    public function getChild()
    {
        return $this->child;
    }

    /**
     * Get the foreign key of the relationship.
     *
     * @return string
     */
    public function getForeignKeyName()
    {
        return $this->foreignKey;
    }

    /**
     * Get the fully qualified foreign key of the relationship.
     *
     * @return string
     */
    public function getQualifiedForeignKeyName()
    {
        return $this->child->qualifyColumn($this->foreignKey);
    }

    /**
     * Get the associated key of the relationship.
     *
     * @return string
     */
    public function getOwnerKeyName()
    {
        return $this->ownerKey;
    }

    /**
     * Get the fully qualified associated key of the relationship.
     *
     * @return string
     */
    public function getQualifiedOwnerKeyName()
    {
        return $this->related->qualifyColumn($this->ownerKey);
    }

    /**
     * Get the name of the relationship.
     *
     * @return string
     */
    public function getRelationName()
    {
        return $this->relationName;
    }

    /**
     * Get the name of the relationship.
     *
     * @return string
     * @deprecated The getRelationName() method should be used instead. Will be removed in Laravel 6.0.
     */
    public function getRelation()
    {
        return $this->relationName;
    }
}