# yii2-relation-behavior


### Installation
```
composer require ed-smartass/yii2-relation-behavior
```


### Example
```php
use Smartass\Yii2RelationBehavior\RelationBehavior;

// ...

/**
 * @inheritdoc
 */
public function behaviors()
{
    return [
        // ...
        'relation' => [
            'class' => RelationBehavior::class,
            'relations' => [
                // Many to one relation
                'manufacturer' => [
                    'target' => Manufacturer::class,
                    'link' => ['manufacturer_id' => 'manufacturer_id']
                ],
                // One to many relation
                'modelCategories' => [
                    'target' => ModelCategories::class,
                    'link' => ['model_id' => 'model_id'],
                    'multiple' => true
                ],
                // Many to many relation
                'categories' => [
                    'target' => Category::class,
                    'link' => ['category_id' => 'category_id'],
                    'multiple' => true,
                    'via' => 'modelCategories'
                ]
            ]
        ]
        // ...
    ];
}

// ...
```


### Relation settings

* **target** — target class
   * Type: `string`  
   * Required: `true`

* **link** — link condition (same as native yii2 declaration)
   * Type: `array`  
   * Required: `true`

* **multiple** — is multiple relation or not (if `true` relation will be like `$this->hasMany(...)` overwise `$this->hasOne(...)`)
   * Type: `bool`  
   * Required: `false`
   * Default: `false`

* **onCondition** — linking condition (will expand to `$this->hasMany(...)->onCondition(['status' => Category::STATUS_ACTIVE])`)
   * Type: `array|null`  
   * Required: `false`
   * Default: `null`

* **filter** — extra linking filter
   * Type: `array|string|Closure|null`  
   * Required: `false`
   * Default: `null`

* **via** — name of junction relation (will expand to `$this->hasMany(...)->via(...)`)
   * Type: `string|null`  
   * Required: `false`
   * Default: `false`

* **extraColumns** — extra column for linking many to many relations
   * Type: `array|null`  
   * Required: `false`
   * Default: `[]`

* **find** - Callback for searching related record from array (if you set relation value `$model->manufacturer = ['manufacturer_id' => 12]`)
   * Type: `Closure|null`  
   * Required: `false`
   * Default: `Closure` (Searching by all pk keys)


### How to use

This behavior will allow you to use related models like usuall without created extra methods like:
```php
public function getCategories()
{
    return $this->hasMany(...);
}
```
Instead, you just need to declare this in the behavior like on example.__
In addition, this behavior can create or save changes to the related model. To do this, behavior uses a transaction. So you can do this:
```php
$model->categories = [
    ['category_id' => 1], // Will find (or create if not exists) category with category_id `1`
    ['category_id' => 2, 'name' => 'New name'], // Will find (or create) category with category_id `1` and change name
    ['name' => 'New category'] // Will create new category
];
$model->save();
```
**Each related model will be validated before saving. If it fails, the transaction will be canceled.**__
**Don't foget add relations to model `rules` as `safe`**
```php
/**
 * @inheritdoc
 */
public function rules()
{
    return [
        // ...
        ['categories', 'safe'],
        // ...
    ];
}
```

### Limitation

This behavior will successfully save only truly basic relations
* One to many
* Many to one
* Many to many
