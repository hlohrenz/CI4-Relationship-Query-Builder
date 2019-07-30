<?php

namespace App\Models\Relation\Concerns;

use App\Models\BaseModel;
use CodeIgniter\Database\BaseBuilder;

trait AsPivot
{
    /**
     * The parent model of the relationship.
     *
     * @var \App\Models\BaseModel
     */
    public $pivotParent;

    /**
     * The name of the foreign key column.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * The name of the "other key" column.
     *
     * @var string
     */
    protected $relatedKey;

    /**
     * Create a new pivot model instance.
     *
     * @param  \App\Models\BaseModel  $parent
     * @param  array   $attributes
     * @param  string  $table
     * @param  bool    $exists
     * @return static
     */
    public static function fromAttributes(BaseModel $parent, $attributes, $table, $exists = false)
    {
        $instance = new static;

        $instance->setUseTimestamps($instance->hasTimestampAttributes($attributes));

        // The pivot model is a "dynamic" model since we will set the tables dynamically
        // for the instance. This allows it work for any intermediate tables for the
        // many to many relationship that are defined by this developer's classes.
        $instance->setTable($table)
            ->forceFill($attributes)
            ->syncOriginal();

        // We store off the parent instance so we will access the timestamp column names
        // for the model, since the pivot model timestamps aren't easily configurable
        // from the developer's point of view. We can use the parents to get these.
        $instance->pivotParent = $parent;

        $instance->exists = $exists;

        return $instance;
    }

    /**
     * Create a new pivot model from raw values returned from a query.
     *
     * @param  \App\Models\BaseModel  $parent
     * @param  array   $attributes
     * @param  string  $table
     * @param  bool    $exists
     * @return static
     */
    public static function fromRawAttributes(BaseModel $parent, $attributes, $table, $exists = false)
    {
        $instance = static::fromAttributes($parent, [], $table, $exists);

        $instance->setUseTimestamps($instance->hasTimestampAttributes($attributes));

        $instance->setRawAttributes($attributes, true);

        return $instance;
    }

    /**
     * Set the keys for a save update query.
     *
     * @param  BaseBuilder  $query
     * @return BaseBuilder
     */
    protected function setKeysForSaveQuery(BaseBuilder $query)
    {
        $query->where($this->getKeyName(), $this->getKey());

        $query->where($this->foreignKey, $this->getOriginal(
            $this->foreignKey, $this->getAttribute($this->foreignKey)
        ));

        return $query->where($this->relatedKey, $this->getOriginal(
            $this->relatedKey, $this->getAttribute($this->relatedKey)
        ));
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        if (! isset($this->table)) {
            $this->setTable(str_replace(
                '\\', '', str_snake(singular(class_basename($this)))
            ));
        }

        return $this->table;
    }

    /**
     * Get the foreign key column name.
     *
     * @return string
     */
    public function getForeignKey()
    {
        return $this->foreignKey;
    }

    /**
     * Get the "related key" column name.
     *
     * @return string
     */
    public function getRelatedKey()
    {
        return $this->relatedKey;
    }

    /**
     * Get the "related key" column name.
     *
     * @return string
     */
    public function getOtherKey()
    {
        return $this->getRelatedKey();
    }

    /**
     * Set the key names for the pivot model instance.
     *
     * @param  string  $foreignKey
     * @param  string  $relatedKey
     * @return $this
     */
    public function setPivotKeys($foreignKey, $relatedKey)
    {
        $this->foreignKey = $foreignKey;

        $this->relatedKey = $relatedKey;

        return $this;
    }

    /**
     * Determine if the pivot model or given attributes has timestamp attributes.
     *
     * @param  $attributes  array|null
     * @return bool
     */
    public function hasTimestampAttributes($attributes = null)
    {
        return array_key_exists($this->getCreatedAtColumn(), $attributes ?? $this->attributes);
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function getCreatedAtColumn()
    {
        return $this->pivotParent
            ? $this->pivotParent->getCreatedAtColumn()
            : parent::getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function getUpdatedAtColumn()
    {
        return $this->pivotParent
            ? $this->pivotParent->getUpdatedAtColumn()
            : parent::getUpdatedAtColumn();
    }

    /**
     * Get the queueable identity for the entity.
     *
     * @return mixed
     */
    public function getQueueableId()
    {
        if (isset($this->attributes[$this->getKeyName()])) {
            return $this->getKey();
        }

        return sprintf(
            '%s:%s:%s:%s',
            $this->foreignKey, $this->getAttribute($this->foreignKey),
            $this->relatedKey, $this->getAttribute($this->relatedKey)
        );
    }
}