<?php

namespace App\Models\Relation;

class HasMany extends HasOneOrMany
{
    /**
     * Get the results of the relationship.
     *
     * @return \App\Models\BaseModel|\CodeIgniter\Database\ResultInterface|mixed
     * @throws \Exception
     */
    public function getResults()
    {
        return ! is_null($this->getParentKey())
            ? $this->related->findAll()
            : $this->related->newModelQuery();
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
     * Match the eagerly loaded results to their parents.
     *
     * @param  array   $models
     * @param  array   $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, array $results, $relation)
    {
        return $this->matchMany($models, $results, $relation);
    }
}