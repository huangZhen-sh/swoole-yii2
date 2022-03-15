<?php

namespace tourze\swoole\yii2\server;

use Swoole;

class RpcNetworkServer extends Swoole\Network\Server
{
    /**
     * 投递任务
     *
     * @param mixed $data
     * @param int $dst_worker_id
     * @return bool
     */
    static public function task($data, $dst_worker_id = -1)
    {
        return self::$swoole->task($data, $dst_worker_id);
    }

    /**
     * 自动推断扩展支持
     * 默认使用swoole扩展,其次是libevent,最后是select(支持windows)
     * @param      $host
     * @param      $port
     * @param bool $ssl
     * @return Server
     */
    static function autoCreate($host, $port, $ssl = false)
    {
        if (class_exists('\\swoole_server', false))
        {
            return new self($host, $port, $ssl);
        }
        else
        {
            return parent::autoCreate($host, $port, $ssl);
        }
    }
}