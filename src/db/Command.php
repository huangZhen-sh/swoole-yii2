<?php

namespace tourze\swoole\yii2\db;

use Yii;
use yii\db\DataReader;

class Command extends \yii\db\Command
{

    /**
     * @var int 重连次数
     */
    public $reconnectTimes = 3;

    /**
     * @var int 当前重连次数
     */
    public $reconnectCount = 0;
    
    /**
     * @var array pending parameters to be bound to the current PDO statement.
     */
    private $_pendingParams = [];
    
    /**
     * @var array bindView
     */
    public $_pendingParamsBak = [];
    
    /**
     * 检查指定的异常是否为可以重连的错误类型
     *
     * @param \Exception $exception
     * @return bool
     */
    public function isConnectionError($exception)
    {
        if ($exception instanceof \PDOException)
        {
            $errorInfo = $this->pdoStatement->errorInfo();
            //var_dump($errorInfo);
            if ($errorInfo[1] == 70100 || $errorInfo[1] == 2006 || $errorInfo[0]=='HY093')
            {
                return true;
            }
        }
        $message = $exception->getMessage();
        if ((strpos($message, 'Error while sending QUERY packet. PID=') !== false)
            || (strpos($message, 'server has gone away') !== false))
        {
            return true;
        }
        return false;
    }

    /**
     * 上一层对PDO的异常返回封装了一次
     *
     * @inheritdoc
     */
    public function execute()
    {
        $sql = $this->getSql();

        $rawSql = $this->getRawSql();

        Yii::info($rawSql, __METHOD__);

        if ($sql == '')
        {
            return 0;
        }

        $this->prepare(false);

        $token = $rawSql;
        try
        {
            Yii::beginProfile($token, __METHOD__);

            $this->pdoStatement->execute();
            $n = $this->pdoStatement->rowCount();

            Yii::endProfile($token, __METHOD__);

            $this->refreshTableSchema();

            $this->reconnectCount = 0;
            return $n;
        }
        catch (\Exception $e)
        {
            Yii::endProfile($token, __METHOD__);
            if ($this->reconnectCount >= $this->reconnectTimes)
            {
                throw $this->db->getSchema()->convertException($e, $rawSql);
            }
            $isConnectionError = $this->isConnectionError($e);
            if ($isConnectionError)
            {
                $this->cancel();
                $this->db->close();
                $this->db->open();
                $this->reconnectCount++;
                return $this->execute();
            }
            throw $this->db->getSchema()->convertException($e, $rawSql);
        }
    }

    /**
     * 上一层对PDO的异常返回封装了一次,
     *
     * @inheritdoc
     */
    public function queryInternal($method, $fetchMode = null)
    {
        $rawSql = $this->getRawSql();

        Yii::info($rawSql, 'yii\db\Command::query');

        if ($method !== '')
        {
            $info = $this->db->getQueryCacheInfo($this->queryCacheDuration, $this->queryCacheDependency);
            if (is_array($info))
            {
                /* @var $cache \yii\caching\Cache */
                $cache = $info[0];
                $cacheKey = [
                    __CLASS__,
                    $method,
                    $fetchMode,
                    $this->db->dsn,
                    $this->db->username,
                    $rawSql,
                ];
                $result = $cache->get($cacheKey);
                if (is_array($result) && isset($result[0]))
                {
                    Yii::trace('Query result served from cache', 'yii\db\Command::query');
                    return $result[0];
                }
            }
        }

        $this->prepare(true);

        $token = $rawSql;
        try
        {
            Yii::beginProfile($token, 'yii\db\Command::query');

            $this->pdoStatement->execute();

            if ($method === '')
            {
                $result = new DataReader($this);
            }
            else
            {
                if ($fetchMode === null)
                {
                    $fetchMode = $this->fetchMode;
                }
                $result = call_user_func_array([$this->pdoStatement, $method], (array) $fetchMode);
                $this->pdoStatement->closeCursor();
            }

            Yii::endProfile($token, 'yii\db\Command::query');
        }
        catch (\Exception $e)
        {
            Yii::endProfile($token, 'yii\db\Command::query');
            if ($this->reconnectCount >= $this->reconnectTimes)
            {
                throw $this->db->getSchema()->convertException($e, $rawSql);
            }
            $isConnectionError = $this->isConnectionError($e);
            //var_dump($isConnectionError);
            if ($isConnectionError)
            {
                $this->cancel();
                $this->db->close();
                $this->db->open();
                $this->reconnectCount++;
    
                $this->cancel();
                $this->bindValues($this->_pendingParamsBak);
                $this->_pendingParamsBak = [];
                
                return $this->queryInternal($method, $fetchMode);
            }
            throw $this->db->getSchema()->convertException($e, $rawSql);
        }

        if (isset($cache, $cacheKey, $info))
        {
            $cache->set($cacheKey, [$result], $info[1], $info[2]);
            Yii::trace('Saved query result in cache', 'yii\db\Command::query');
        }

        $this->reconnectCount = 0;
        return $result;
    }
    
    /**
     * Binds pending parameters that were registered via [[bindValue()]] and [[bindValues()]].
     * Note that this method requires an active [[pdoStatement]].
     */
    protected function bindPendingParams() {
        $this->_pendingParamsBak = $this->_pendingParams;
        foreach ($this->_pendingParams as $name => $value) {
            $this->pdoStatement->bindValue($name, $value[0], $value[1]);
        }
        $this->_pendingParams = [];

    }

    /**
     * Binds a value to a parameter.
     * @param string|int $name Parameter identifier. For a prepared statement
     * using named placeholders, this will be a parameter name of
     * the form `:name`. For a prepared statement using question mark
     * placeholders, this will be the 1-indexed position of the parameter.
     * @param mixed $value The value to bind to the parameter
     * @param int $dataType SQL data type of the parameter. If null, the type is determined by the PHP type of the value.
     * @return $this the current command being executed
     * @see http://www.php.net/manual/en/function.PDOStatement-bindValue.php
     */
    public function bindValue($name, $value, $dataType = null)
    {
        if ($dataType === null) {
            $dataType = $this->db->getSchema()->getPdoType($value);
        }
        $this->_pendingParams[$name] = [$value, $dataType];
        $this->params[$name] = $value;

        return $this;
    }

    /**
     * Binds a list of values to the corresponding parameters.
     * This is similar to [[bindValue()]] except that it binds multiple values at a time.
     * Note that the SQL data type of each value is determined by its PHP type.
     * @param array $values the values to be bound. This must be given in terms of an associative
     * array with array keys being the parameter names, and array values the corresponding parameter values,
     * e.g. `[':name' => 'John', ':age' => 25]`. By default, the PDO type of each value is determined
     * by its PHP type. You may explicitly specify the PDO type by using an array: `[value, type]`,
     * e.g. `[':name' => 'John', ':profile' => [$profile, \PDO::PARAM_LOB]]`.
     * @return $this the current command being executed
     */
    public function bindValues($values)
    {
        if (empty($values)) {
            return $this;
        }

        $schema = $this->db->getSchema();
        foreach ($values as $name => $value) {
            if (is_array($value)) {
                $this->_pendingParams[$name] = $value;
                $this->params[$name] = $value[0];
            } else {
                $type = $schema->getPdoType($value);
                $this->_pendingParams[$name] = [$value, $type];
                $this->params[$name] = $value;
            }
        }

        return $this;
    }

}
