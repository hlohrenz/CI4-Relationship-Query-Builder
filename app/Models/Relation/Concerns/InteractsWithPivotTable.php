<?php

namespace App\Models\Relation\Concerns;

use App\Models\BaseModel;
use Carbon\Traits\Date;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ResultInterface;

trait InteractsWithPivotTable
{
    /**
     * Attach a model to the parent.
     *
     * @param $id
     * @param array $attributes
     * @param bool $touch
     * @throws \ReflectionException
     */
    public function attach($id, array $attributes = [], $touch = true)
    {
        if ($this->using) {
            $this->attachUsingCustomClass($id, $attributes);
        } else {
            // Here we will insert the attachment records into the pivot table. Once we have
            // inserted the records, we will touch the relationships if necessary and the
            // function will return. We can parse the IDs before inserting the records.
            $this->newPivotStatement()->insertBatch($this->formatAttachRecords(
                $this->parseIds($id), $attributes
            ));
        }

        if ($touch) {
            $this->touchIfTouching();
        }
    }

    /**
     * Detach models from the relationship.
     *
     * @param  mixed  $ids
     * @param  bool  $touch
     * @return array|ResultInterface
     */
    public function detach($ids = null, $touch = true)
    {
        if ($this->using && ! empty($ids) && empty($this->pivotWheres) && empty($this->pivotWhereIns)) {
            $results = $this->detachUsingCustomClass($ids);
        } else {
            $query = $this->newPivotQuery();

            // If associated IDs were passed to the method we will only delete those
            // associations, otherwise all of the association ties will be broken.
            // We'll return the numbers of affected rows when we do the deletes.
            if (! is_null($ids)) {
                $ids = $this->parseIds($ids);

                if (empty($ids)) {
                    return [];
                }

                $query->whereIn($this->relatedPivotKey, (array) $ids);
            }

            // Once we have all of the conditions set on the statement, we are ready
            // to run the delete on the pivot table. Then, if the touch parameter
            // is true, we will go ahead and touch all related models to sync.
            $results = $query->delete();
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return $results;
    }

    /**
     * Detach models from the relationship using a custom class.
     *
     * @param  mixed  $ids
     * @return array
     */
    protected function detachUsingCustomClass($ids)
    {
        $results = [];

        foreach ($this->parseIds($ids) as $id) {
            $instance = $this->newPivot();
            $results[] = $instance
                ->where($this->foreignPivotKey, $this->parent->{$this->parentKey})
                ->where($this->relatedPivotKey, $id)
                ->delete();
        }

        return $results;
    }

    /**
     * Attach a model to the parent using a custom class.
     *
     * @param $id
     * @param array $attributes
     * @throws \ReflectionException
     */
    protected function attachUsingCustomClass($id, array $attributes)
    {
        $records = $this->formatAttachRecords(
            $this->parseIds($id), $attributes
        );

        foreach ($records as $record) {
            $model = $this->newPivot($record, false);
            $model->save($model->getAttributes());
        }
    }

    /**
     * Create an array of records to insert into the pivot table.
     *
     * @param $ids
     * @param array $attributes
     * @return array
     * @throws \Exception
     */
    protected function formatAttachRecords($ids, array $attributes)
    {
        $records = [];

        $hasTimestamps = ($this->hasPivotColumn($this->createdAt()) ||
            $this->hasPivotColumn($this->updatedAt()));

        // To create the attachment records, we will simply spin through the IDs given
        // and create a new record to insert for each ID. Each ID may actually be a
        // key in the array, with extra attributes to be placed in other columns.
        foreach ($ids as $key => $value) {
            $records[] = $this->formatAttachRecord(
                $key, $value, $attributes, $hasTimestamps
            );
        }

        return $records;
    }

    /**
     * Create a full attachment record payload.
     *
     * @param $key
     * @param $value
     * @param $attributes
     * @param $hasTimestamps
     * @return array
     * @throws \Exception
     */
    protected function formatAttachRecord($key, $value, $attributes, $hasTimestamps)
    {
        [$id, $attributes] = $this->extractAttachIdAndAttributes($key, $value, $attributes);

        return array_merge(
            $this->baseAttachRecord($id, $hasTimestamps), $this->castAttributes($attributes)
        );
    }

    /**
     * Get the attach record ID and extra attributes.
     *
     * @param  mixed  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return array
     */
    protected function extractAttachIdAndAttributes($key, $value, array $attributes)
    {
        return is_array($value)
            ? [$key, array_merge($value, $attributes)]
            : [$value, $attributes];
    }

    /**
     * Create a new pivot attachment record.
     *
     * @param  int   $id
     * @param  bool  $timed
     * @return array
     */
    protected function baseAttachRecord($id, $timed)
    {
        $record[$this->relatedPivotKey] = $id;

        $record[$this->foreignPivotKey] = $this->parent->{$this->parentKey};

        // If the record needs to have creation and update timestamps, we will make
        // them by calling the parent model's "freshTimestamp" method which will
        // provide us with a fresh timestamp in this model's preferred format.
        if ($timed) {
            $record = $this->addTimestampsToAttachment($record);
        }

        foreach ($this->pivotValues as $value) {
            $record[$value['column']] = $value['value'];
        }

        return $record;
    }

    /**
     * Set the creation and update timestamps on an attach record.
     *
     * @param  array  $record
     * @param  bool   $exists
     * @return array
     */
    protected function addTimestampsToAttachment(array $record, $exists = false)
    {
        $fresh = Date::now();

        if (! $exists && $this->hasPivotColumn($this->createdAt())) {
            $record[$this->createdAt()] = $fresh;
        }

        if ($this->hasPivotColumn($this->updatedAt())) {
            $record[$this->updatedAt()] = $fresh;
        }

        return $record;
    }

    /**
     * Determine whether the given column is defined as a pivot column.
     *
     * @param  string  $column
     * @return bool
     */
    protected function hasPivotColumn($column)
    {
        return in_array($column, $this->pivotColumns);
    }

    /**
     * Set the columns on the pivot table to retrieve.
     *
     * @param  array|mixed  $columns
     * @return $this
     */
    public function withPivot($columns)
    {
        $this->pivotColumns = array_merge(
            $this->pivotColumns, is_array($columns) ? $columns : func_get_args()
        );

        return $this;
    }

    /**
     * Get all of the IDs from the given mixed value.
     *
     * @param  mixed  $value
     * @return array
     */
    protected function parseIds($value)
    {
        if ($value instanceof BaseModel) {
            return [$value->{$this->relatedKey}];
        }

        return (array) $value;
    }

    /**
     * Get a new plain query builder for the pivot table.
     *
     * @return BaseBuilder
     */
    public function newPivotStatement()
    {
        return $this->using ? new $this->using() : $this->newPivot();
    }

    /**
     * Get a new pivot statement for a given "other" ID.
     *
     * @param  mixed  $id
     * @return BaseBuilder
     */
    public function newPivotStatementForId($id)
    {
        return $this->newPivotQuery()->whereIn($this->relatedPivotKey, $this->parseIds($id));
    }
    /**
     * Create a new query builder for the pivot table.
     *
     * @return BaseBuilder
     */
    protected function newPivotQuery()
    {
        $query = $this->newPivotStatement();

        foreach ($this->pivotWheres as $arguments) {
            call_user_func_array([$query, 'where'], $arguments);
        }

        foreach ($this->pivotWhereIns as $arguments) {
            call_user_func_array([$query, 'whereIn'], $arguments);
        }

        return $query->where($this->foreignPivotKey, $this->parent->{$this->parentKey});
    }

    /**
     * Create a new pivot model instance.
     *
     * @param  array  $attributes
     * @param  bool   $exists
     * @return \App\Models\Relation\Pivot
     */
    public function newPivot(array $attributes = [], $exists = false)
    {
        $pivot = $this->related->newPivot(
            $this->parent, $attributes, $this->table, $exists, $this->using
        );

        return $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey);
    }

    /**
     * Create a new existing pivot model instance.
     *
     * @param  array  $attributes
     * @return \App\Models\Relation\Pivot
     */
    public function newExistingPivot(array $attributes = [])
    {
        return $this->newPivot($attributes, true);
    }

    /**
     * Cast the given pivot attributes.
     *
     * @param $attributes
     * @return array
     * @throws \Exception
     */
    protected function castAttributes($attributes)
    {
        return $this->using
            ? $this->newPivot()->fill($attributes)->getAttributes()
            : $attributes;
    }
}