<?php

namespace tourze\swoole\yii2\db;

use \Swoole\Table;
use \Swoole\lock;
use yii\db\BaseActiveRecord;
use tourze\swoole\yii2\db\Filter;
use yii\debug\components\search\matchers;
use yii\helpers\ArrayHelper;

class SwooleTableActiveRecord extends BaseActiveRecord
{
    //swoole表
    protected static $swooleTable;

    //同步时间
    public static $atomicSyncTime;

    /**
     * 创建内存表
     */
    public static function buildTable()
    {
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    /**
     * 取得内存表所有区块配置信息
     * @return array
     */
    public static function findAll($params=[], $orders=[])
    {
        $filter = new Filter();

        if(self::count()==0){
            return [];
        }

        foreach ($params as $key=>$value){
            if(is_array($value)){
                if(count($value)==3 && is_int($key)){
                    list($compare, $attribute, $v) = $value;
                    switch (strtolower($compare)){
                        case "like":
                            $filter->addMatcher($attribute, new matchers\SameAs(['value' => $v, 'partial' => true]));
                            break;
                        case ">":
                            $filter->addMatcher($attribute, new matchers\GreaterThan(['value' => $v]));
                            break;
                        case "<":
                            $filter->addMatcher($attribute, new matchers\LowerThan(['value' => $v]));
                            break;
                        case "in":
                            $filter->addMatcher($attribute, new MatchersIn(['value' => $v]));
                            break;
                        default:
                            break;
                    }
                }else{
                    $filter->addMatcher($key, new MatchersIn(['value' => $value]));
                }

            }elseif(!is_array($value)){
                $filter->addMatcher($key, new matchers\SameAs(['value' => $value, 'partial' => false]));
            }elseif(is_array($value)){

            }
        }

        //筛选
        $list = $filter->filter(static::$swooleTable);
        //排序
        ArrayHelper::multisort($list, array_keys($orders), array_values($orders));
        return $list;
    }

    /**
     * 生成 key
     */
    public function getKey($old=false){
        $attributes = $old? $this->getOldAttributes() : $this->getAttributes();
        if(isset($attributes[slef::primaryKey()])){
            return $attributes[slef::primaryKey()];
        }
        throw new NotSupportedException(__METHOD__ . ' is not supported.');
    }

    public static function primaryKey()
    {
        return ['_id'];
    }

    /**
     * @inheritdoc
     * @return ActiveQuery the newly created [[ActiveQuery]] instance.
     */
    public static function find()
    {
        return Yii::createObject(SwooleTableActiveRecord::className(), [get_called_class()]);
    }

    /**
     * Returns the database connection used by this AR class.
     * By default, the "db" application component is used as the database connection.
     * You may override this method if you want to use a different database connection.
     * @return Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return self::$swooleTable;
    }

    /**
     * Inserts a row into the associated Mongo collection using the attribute values of this record.
     * @param bool $runValidation
     * @param null $attributes
     *
     * @return bool
     */
    public function insert($runValidation = true, $attributes = null)
    {
        if ($runValidation && !$this->validate($attributes)) {
            return false;
        }
        $result = $this->insertInternal($attributes);

        return $result;
    }

    /**
     * @see ActiveRecord::insert()
     */
    protected function insertInternal($attributes = null)
    {
        if (!$this->beforeSave(true)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values)) {
            $currentAttributes = $this->getAttributes();
            foreach ($this->primaryKey() as $key) {
                if (isset($currentAttributes[$key])) {
                    $values[$key] = $currentAttributes[$key];
                }
            }
        }

        static::$swooleTable->set($this->getKey(), $values);

        $changedAttributes = array_fill_keys(array_keys($values), null);
        $this->setOldAttributes($values);
        $this->afterSave(true, $changedAttributes);

        return true;
    }

    /**
     * @param Table $table设置表
     */
    protected static function setTable(Table $table)
    {
        static::$swooleTable = $table;
    }

    /**
     * 判断key是否存在
     * @param $key
     *
     * @return mixed
     */
    public static function existByKey(string $key)
    {
        return static::$swooleTable->exist($key);
    }

    /**
     * 获取指定key的数据
     * @param string $key
     *
     * @return mixed
     */
    public static function findByKey(string $key)
    {
        $row = static::$swooleTable->get($key);
        if(!$row)
            return false;

        $class      = get_called_class();
        $model      = $class::instantiate($row);
        $modelClass = get_class($model);
        $modelClass::populateRecord($model, $row);
        return $model;
    }

    /**
     * 增加计数器
     * @param string $key
     * @param string $column
     * @param int $incrby
     *
     * @return mixed
     */
    public static function updateCountersByKey(string $key, string $column, $incrby=1)
    {
        if($incrby < 0){
            return static::$swooleTable->decr($key, $column, $incrby);
        }else{
            return static::$swooleTable->incr($key, $column, abs($incrby));
        }
    }

    /**
     * 更新
     * @param bool $runValidation
     * @param null $attributeNames
     *
     * @return bool
     */
    public function update($runValidation = true, $attributeNames = null)
    {
        if ($runValidation && !$this->validate($attributeNames)) {
            return false;
        }
        $del = static::$swooleTable->del($this->getKey(true));
        if($del){
            return static::$swooleTable->set($this->getKey(), array_merge($this->toArray()));
        }
        return $del;
    }

    /**
     * Updates all documents in the collection using the provided attribute values and conditions.
     * For example, to change the status to be 1 for all customers whose status is 2:
     *
     * ```php
     * Customer::updateAll(['status' => 1], ['status' => 2]);
     * ```
     *
     * @param array $attributes attribute values (name-value pairs) to be saved into the collection
     * @param array $condition description of the objects to update.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @param array $options list of options in format: optionName => optionValue.
     * @return int the number of documents updated.
     */
    public static function updateAll($attributes, $condition = [], $options = [])
    {

        $models = self::findAll($condition);
        $return  = false;
        foreach($models as $row){
            $class = get_called_class();
            $model = $class::instantiate($row);
            $modelClass = get_class($model);
            $modelClass::populateRecord($model, $row);

            $del = static::$swooleTable->del($model->getKey(true));
            if($del){
                $set = static::$swooleTable->set($model->getKey(), array_merge($model->toArray(), $attributes));
                $return = $return || $set;
            }
        }
        return $return;
    }

    /**
     * 删除所有符合条件的记录
     * @param null $condition
     */
    public static function deleteAll($condition = null)
    {
        $lock = new Lock(SWOOLE_MUTEX);
        $lock->lock();
        $list = self::findAll($condition);

        $return  = true;
        foreach ($list as $item){
            $class      = get_called_class();
            $model      = $class::instantiate($item);
            $modelClass = get_class($model);
            $modelClass::populateRecord($model, $item);

            $res = $model->delete();
            $return = $return && $res;
        }
        $lock->unlock();
        unset($lock);
        return $return;
    }

    /**
     * 删除指定key的记录
     * @param null $condition
     */
    public static function deleteByKey($key)
    {
        return static::$swooleTable->del($key);
    }

    /**
     * 删除
     * @return mixed
     */
    public function delete()
    {
        return self::deleteByKey($this->getKey());
    }

    /**
     * 删除
     * @return mixed
     */
    public static function count()
    {
        return static::$swooleTable->count();
    }
}