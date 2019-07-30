# CodeIgniter 4 Relationship Query Builder

## What does this add to CI4? How do I setup my project with this?

This is an addition to the CodeIgniter 4 framework. This adds scopes and relationship query builder methods onto the current query builder. You simply use this library by extending your models with the BaseModel found in app/Models. You can run & test all sorts of scenarios with this setup.

In order to use this setup, you must add the string_helper into your Helpers and load it in your public/index.php file along with the inflector helper:

```php
// Global helpers that should be available on every request
helper('string');
helper('inflector');
```

Also make sure to add the Support directory inside the app directory to your project!

## Relationships

Currently, there are 4 relationships available: HasOne, HasMany, BelongsTo, and BelongsToMany. You set these up in your models similar to how Laravel 5 does. The code was heavily inspired by the Laravel framework, but was adjusted to work with CodeIgniter's query builder.

#### HasOne (one to one)

This is used in one-to-one relationships where a User has one Address. Let's say you have a User Model and an Address Model. You would setup your relationship in your User Model like so:

```php
<?php

namespace App\Models;

class User extends BaseModel
{
    /**
     * Get the address record associated with the user.
     */
    public function address()
    {
        return $this->hasOne('address', 'App\Models\Address');
        // $this->hasOne('propertyName', 'model', 'foreign_key', 'local_key');
    }
}
```

The first argument passed into the method is the name of the relationship. When fetching this relationship, this is the property name you will use to retrieve that instance. The second parameter is the related model including it's namespace. The third parameter is the foreign key on the Address model, in this case by default, it will result in `user_id`. You can define the local key by providing the fourth parameter. You can grab the entire Address model by performing a chain such as:

```php
$user = (new User())->find(1);
$address = $user->address;
```

This will assign the address variable with the Address model instance that belongs to this user.

#### BelongsTo (one to one | many to one)

This is the inverse relationship to HasOne and HasMany.

```php
<?php

namespace App\Models;

class Address extends BaseModel
{
    /**
     * Get the user that lives at this address.
     */
    public function user()
    {
        return $this->belongsTo('user', 'App\Models\User');
        // $this->belongsTo('propertyName', 'model', 'foreign_key', 'owner_key');
    }
}
```

The default foreign key is determined by the related model's table name in singular format appended with an `_id`. In this case, it will use `user_id` since the table for our User model is `users`.

#### HasMany (one to many)

A one to many is when the parent model has multiple child models. In this case, it could be a forum. Think if posts having multiple comments. You have one table containing your posts with the title, author, etc. Then you have another table which stores all of it's comments with created dates and authors.

```php
<?php

namespace App\Models;

class Post extends BaseModel
{
    /**
     * Get the comments for the blog post.
     */
    public function comments()
    {
        return $this->hasMany('comments', 'App\Models\Comment');
        // $this->hasMany('propertyName', 'model', 'foreign_key', 'local_key');
    }
}
```

So for this foreign key instance, it will use `post_id`. If the foreign key is something else, you can specify it as the third argument. You can retrieve all a posts comments by doing something like:

```php
$comments = (new Post())->find(1)->comments;

foreach ($comments as $comment) {
    //
}
```

Relationships also serve as a query builder so feel free to append any method you would with the query builder like before:

```php
$comment = $post->comments()->where('title', 'foo')->first();
```

#### BelongsToMany (many to many)

For this example, we will use a User model and a Role model. User's have many roles and roles have many users.

```php
<?php

namespace App\Models;

class User extends BaseModel
{
    /**
     * The roles that belong to the user.
     */
    public function roles()
    {
        return $this->belongsToMany('roles', 'App\Models\Role');
        // $this->belongsToMany('roles', 'App\Models\Role', 'user_roles', 'user_id', 'role_id', 'id', 'id');
        // $this->belongsToMany('propertyName', 'model', 'associative_table_name', 'foreign_pivot_key', 'related_pivot_key', 'parent_key', 'related_key');
    }
}
```

You can retrieve a user's roles how you would in the hasMany examples above:

```php
$user = (new User())->find(1);

foreach ($user->roles as $role) {
    //
}
```

You can also chain query builder methods in case if you need to add an ORDER BY clause: `$user->roles()->orderBy('name')->findAll()`. You can also define the inverse relationship on the Role model the same way we did above.

##### BelongsToMany Pivot

You can also retrieve pivot table information. By default, the data from the associative table will belong in a `pivot` property.

```php
foreach ($user->roles as $role) {
    echo $role->pivot->created_at;
}
```

If your pivot table contains extra attributes you need selected, you can do so by specifying it on the relationship.

```php
// When defining the relationship
$this->belongsToMany('roles', 'App\Models\Role')->withPivot('name', 'hex_code');
```

You can also customize the pivot property name on the relationship. If you prefer it to be called `info` in this scenario:

```php
$this->belongsToMany('roles', 'App\Models\Role')->as('info');
```

If your pivot table has timestamps created on it, you can also specify that on your relationship so that the `$createdField` and `$updatedField` properties will return:

```php
$this->belongsToMany('roles', 'App\Models\Role')->withTimestamps();
```

If you need to filter on the associative table, you can do so on the relationship:

```php
// Relationship
$this->belongsToMany('roles', 'App\Models\Role')->wherePivot('approved', 1);
```

## Querying Relations

All of the relationships specified above also serve as a query builder so you can chain any query builder method you would originally onto these relationships.

#### Lazy Loading/Eager Loading

When you call a relationship property on your model, the data is lazy loaded and is only loaded when you actually need the data. Developers tend to eager load their data since they know it will be accessed after loading the model. This also provides significant performance increase due to minimizing the SQL queries needed.

In order to reduce running a query within a for loop of models, you can use the `with` method on your model.

```php
$posts = (new App\Models\Post())->with('author')->findAll();

foreach ($posts as $post) {
    echo $post->author->username;
}
```

This operation will only perform TWO queries instead of N+1:

```
select * from posts

select * from users where id in (1, 2, 3, 4, 5, ...)
```

Huge performance increase! :) You can eager load multiple relations by passing in an array of relation names to the `with()` method like so:

```php
$posts = (new App\Models\Post())->with(['author', 'firstComment']);
```

##### Constraining Eager Loaded Relationships

You can also add constraints to your eager loaded relationships:

```php
$posts = (new App\Models\Post())->with(['comments' => function ($query) {
    $query->where('body', 'like', '%bunny%');
}])->findAll();
```

This will eager load all posts and only the comments where the body contains the word "bunny".

**TODO: add whereHas() to constraint models when being retrieved based on relation condition.**

### Lazy Eager Load

You may eager load a relationship after a model has already been retrieved. For instance: `$posts->load('author')`. This will load the author relationship of the models that are held inside the `$posts` variable.

## Scopes

You can now add scopes in your models for frequently used query scenarios such as ORDER BY clauses or WHERE conditions.

Say you wanted to add a scope to your User model where you only retrieve users who status is confirmed.

```php
<?php

namespace App\Models;

class User extends BaseModel
{
    /**
     * Scope to constrain users who are confirmed.
     *
     * @param $query
     * @return mixed
     */
    public function scopeConfirmed($query)
    {
        return $query->where('confirmed', 1);
    }
}
```

You can now perform queries on your User model such as:

```php
$users = (new User())->confirmed()->orderBy('created_at')->findAll();
```

## Repository Requirements

In order to use this package, you must have the latest CodeIgniter framework installed (this only works with version 4).
