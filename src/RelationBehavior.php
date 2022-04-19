<?php

namespace Smartass\Yii2RelationBehavior;

use Closure;
use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\base\ModelEvent;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\BaseActiveRecord;
use yii\db\Transaction;
use yii\helpers\ArrayHelper;

/**
 * Поведения для свзяи моделей. 
 * 
 * @property ActiveRecord $owner
 * 
 * @author Smartass <ed.smartass@gmail.com>
 */
class RelationBehavior extends Behavior
{
    /**
     * @var array
     */
    public $relations = [];

    /**
     * @var BaseActiveRecord[]
     */
    protected $_values = [];

    /**
     * @var array
     */
    protected $_getters = [];

    /**
     * @var array
     */
    protected $_setters = [];

    /**
     * @var Transaction|null
     */
    protected $_transaction;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        if (!is_array($this->relations)) {
            throw new InvalidConfigException('`relations` must an array');
        }

        foreach($this->relations as $attribute => $config) {
            if (!is_array($config)) {
                throw new InvalidConfigException('`' . $attribute . '` must an array');
            }

            if (!isset($config['target'])) {
                throw new InvalidConfigException('`' . $attribute . '` must have `target`');
            }

            if (!isset($config['link'])) {
                throw new InvalidConfigException('`' . $attribute . '` must have `link`');
            } else if (!is_array($config['link'])) {
                throw new InvalidConfigException('`link` for `' . $attribute . '` must be an array');
            }

            $this->relations[$attribute] = array_merge([
                'find' => function($item) use ($config) {
                    /** @var \yii\db\ActiveRecordInterface */
                    $targetClass = $config['target'];
                    return $targetClass::findOne(array_combine($targetClass::primaryKey(), array_map(function($pk) use ($item) {
                        return ArrayHelper::getValue($item, $pk);
                    }, $targetClass::primaryKey())));
                },
                'multiple' => false,
                'onCondition' => false,
                'filter' => false,
                'via' => false,
                'extraColumns' => []
            ], $config);

            $this->_getters['get' . $attribute] = $attribute;
            $this->_getters['get' . ucfirst($attribute)] = $attribute;

            $this->_setters['set' . $attribute] = $attribute;
            $this->_setters['set' . ucfirst($attribute)] = $attribute;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return [
            BaseActiveRecord::EVENT_BEFORE_INSERT => [$this, 'saveRelations'],
            BaseActiveRecord::EVENT_BEFORE_UPDATE => [$this, 'saveRelations']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function canGetProperty($name, $checkVars = true)
    {
        return array_key_exists($name, $this->relations) || parent::canGetProperty($name, $checkVars);
    }

    /**
     * {@inheritdoc}
     */
    public function canSetProperty($name, $checkVars = true)
    {
        return array_key_exists($name, $this->relations) || parent::canGetProperty($name, $checkVars);
    }

    /**
     * {@inheritdoc}
     */
    public function hasMethod($name)
    {
        return isset($this->_getters[$name]) || isset($this->_setters[$name]) || parent::hasMethod($name);
    }

    /**
     * {@inheritdoc}
     */
    public function __get($name)
    {
        if (array_key_exists($name, $this->_values) && $this->owner->isRelationPopulated($name)) {
            return $this->_values[$name];
        } else if (array_key_exists($name, $this->relations)) {
            unset($this->_values[$name]);
            return $this->getRelation($name);
        }

        return parent::__get($name);
    }

    /**
     * {@inheritdoc}
     */
    public function __set($name, $value)
    {
        if (array_key_exists($name, $this->relations)) {
            $this->setRelation($name, $value);
        } else {
            parent::__set($name, $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __call($name, $params)
    {
        if (isset($this->_getters[$name])) {
            return $this->getRelation($this->_getters[$name]);
        }

        if (isset($this->_setters[$name])) {
            return $this->setRelation($this->_setters[$name], ArrayHelper::getValue($params, 0));
        }

        return parent::__call($name, $params);
    }

    /**
     * @param string $name
     * @return ActiveQuery
     */
    protected function getRelation($name)
    {
        $relation = $this->relations[$name];

        if ($relation['multiple']) {
            $query = $this->owner->hasMany($relation['target'], $relation['link']);
        } else {
            $query = $this->owner->hasOne($relation['target'], $relation['link']);
        }

        if ($relation['onCondition']) {
            $query->andOnCondition($relation['onCondition']);
        }

        if ($relation['filter']) {
            if ($relation['filter'] instanceof Closure || (is_array($relation['filter']) && is_callable($relation['filter']))) {
                $relation['filter']($query);
            } else {
                $query->andWhere($relation['filter']);
            }
        }

        if ($relation['via']) {
            $query->via($relation['via']);
        }

        return $query;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return ActiveQueryInterface
     */
    protected function setRelation($name, $value)
    {
        Yii::debug('Setting relation for `' . $name . '`');

        $relation = $this->relations[$name];
        
        if ($relation['multiple']) {
            $relationValue = [];

            if (is_array($value)) {
                $keys = [];

                if ($relation['multiple'] && !$relation['via']) {
                    $keys = array_keys($relation['link']);
                }

                $relationValue = array_map(function($item) use ($relation, $keys) {
                    foreach($keys as $key) {
                        $item[$key] = $this->owner->{$relation['link'][$key]};
                    }

                    $model = $relation['find']($item);

                    if (!$model) {
                        /** @var BaseActiveRecord */
                        $model = new $relation['target']();
                    }

                    $model->load($item, '');

                    return $model;
                }, $value);
            }
        } else {
            $relationValue = $relation['find']($value);

            if (!$relationValue) {
                /** @var BaseActiveRecord */
                $relationValue = new $relation['target']();
            }

            $relationValue->load($value, '');
        }

        $this->_values[$name] = $relationValue;
        $this->owner->populateRelation($name, $relationValue);
    }

    /**
     * @return void
     */
    public function beginTransaction()
    {
        if ($this->_transaction) {
            $this->rollBackTransaction();
        }

        $this->_transaction = $this->owner::getDb()->beginTransaction();
    }

    /**
     * @return void
     */
    public function commitTransaction()
    {
        if ($this->_transaction) {
            $this->_transaction->commit();
            $this->_transaction = null;
        }
    }

    /**
     * @return void
     */
    public function rollBackTransaction()
    {
        if ($this->_transaction) {
            $this->_transaction->rollBack();
            $this->_transaction = null;
        }
    }

    /**
     * @param ModelEvent $event
     * @return void
     */
    public function saveRelations($event)
    {
        Yii::debug('Saving relations for `' . get_class($this->owner) . '`');

        $this->beginTransaction();

        foreach($this->_values as $name => $value) {
            if (!$this->owner->isRelationPopulated($name)) {
                unset($this->_values[$name]);
                continue;
            }

            $relation = $this->relations[$name];

            if ($relation['multiple']) {
                $this->saveManyRelation($name, $value);
            } else {
                if (!$this->saveOneRelation($name, $value)) {
                    $event->isValid = false;
                }
            }

            unset($this->owner->$name);
            unset($this->_values[$name]);
        }
        
        $this->owner->on($this->owner->isNewRecord ? BaseActiveRecord::EVENT_AFTER_INSERT : BaseActiveRecord::EVENT_AFTER_UPDATE, [$this, 'commitTransaction']);
    }

    /**
     * @param string $name
     * @param BaseActiveRecord $value
     * @return boolean
     */
    protected function saveOneRelation($name, $value)
    {
        Yii::debug('Saving relation `' . $name . '` for `' . get_class($this->owner) . '`');

        if ($this->owner->hasErrors()) {
            return;
        }

        $relation = $this->relations[$name];

        if (!$value && !$this->isNewRecord && $this->owner->$name) {
            $this->owner->unlink($name, $this->owner->$name);
        }

        if ($value) {
            if (!$value->validate()) {
                foreach($value->errors as $field => $messages) {
                    foreach($messages as $message) {
                        $this->owner->addError($name . '.' . $field, $message);
                    }
                }

                $this->rollBackTransaction();

                return false;
            }

            try {
                if (!$value->save()) {
                    $this->rollBackTransaction();
                    throw new Exception('Error at saving `' . $name . '`');
                }
            } catch (\Throwable $th) {
                $this->rollBackTransaction();
                throw $th;
            }

            foreach ($relation['link'] as $pk => $fk) {
                $valuePk = $value->$pk;
                if ($valuePk === null) {
                    $this->rollBackTransaction();
                    throw new InvalidCallException('Unable to link models: the primary key of ' . get_class($value) . ' is null.');
                }
                if (is_array($this->owner->$fk)) {
                    $this->owner->{$fk}[] = $valuePk;
                } else {
                    $this->owner->{$fk} = $valuePk;
                }
            }
        }

        return true;
    }

    /**
     * @param string $name
     * @param BaseActiveRecord[] $value
     * @return void
     */
    protected function saveManyRelation($name, $value)
    {
        Yii::debug('Saving relation `' . $name . '` for `' . get_class($this->owner) . '`');
        
        $isNewRecord = $this->owner->isNewRecord;
        $this->owner->on($isNewRecord ? BaseActiveRecord::EVENT_AFTER_INSERT : BaseActiveRecord::EVENT_AFTER_UPDATE, function() use ($name, $value, $isNewRecord) {
            if (!$this->_transaction) {
                return;
            }

            $relation = $this->relations[$name];

            $keys = [];

            if ($relation['multiple'] && !$relation['via']) {
                $keys = array_keys($relation['link']);
            }

            if (!$value) {
                $this->owner->unlinkAll($name, (bool)$relation['via'] || (bool)$keys);
                return;
            }

            $hasErrors = false;

            if ($keys) {
                foreach($value as $i => $item) {
                    foreach($keys as $key) {
                        $item->$key = $this->owner->{$relation['link'][$key]};
                    }
                }
            }

            foreach($this->getRelation($name)->each() as $oldModel) {
                $index = false;

                foreach($value as $i => $newModel) {
                    $newModel->validate();
                    if ($newModel->equals($oldModel)) {
                        $index = $i;
                        break;
                    }
                }

                if ($index === false) {
                    try {
                        $this->owner->unlink($name, $oldModel, (bool)$relation['via'] || (bool)$keys);
                    } catch (\Throwable $th) {
                        $this->rollBackTransaction();
                        throw $th;
                    }
                } else {
                    $item = $value[$index];

                    if (!$item->validate()) {
                        foreach($item->errors as $field => $messages) {
                            foreach($messages as $message) {
                                $this->owner->addError($name . '.' . $i . '.' . $field, $message);
                                $hasErrors = true;
                            }
                        }
                    } else if (!$item->save()) {
                        throw new Exception('Error at saving `' . $name . '.' . $i . '`');
                    }
                    unset($value[$index]);
                }
            }

            foreach($value as $item) {
                try {
                    if (!$item->validate()) {
                        foreach($item->errors as $field => $messages) {
                            foreach($messages as $message) {
                                $this->owner->addError($name . '.' . $i . '.' . $field, $message);
                                $hasErrors = true;
                            }
                        }
                    } else if (!$item->save(false)) {
                        throw new Exception('Error at saving `' . $name . '.' . $i . '`');
                    }

                    if (!$this->owner->hasErrors()) {
                        $this->owner->link($name, $item, $relation['extraColumns']);
                    }
                } catch (\Throwable $th) {
                    $this->rollBackTransaction();
                    throw $th;
                }
                
            }

            if ($hasErrors) {
                $this->rollBackTransaction();

                if (!$isNewRecord) {
                    try {
                        $this->owner->refresh();
                    } catch (\Throwable $th) {
                        $this->rollBackTransaction();
                        throw $th;
                    }
                }
                return;
            }
        }, null, false);
    }
}