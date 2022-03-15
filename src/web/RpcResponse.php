<?php
/**
 * Created by PhpStorm.
 * User: 16020028
 * Date: 2018/1/15
 * Time: 19:07
 */
namespace tourze\swoole\yii2\web;

use Yii;
use tourze\swoole\yii2\server\RpcServer;


/**
 * 内部实现response
 *
 * @property swoole_http_response serverResponse
 */
class RpcResponse extends Response
{
    
    public $status = 0;
    
    protected $fd;

    protected $_header;

    public function setFd($fd)
    {
        $this->fd = $fd;
    }

    public function setHeader($header)
    {
        $this->_header = $header;
    }

    public function status(int $code)
    {
        $this->status = $code;
    }


    /**
     * @inheritdoc
     */
    protected function sendContent()
    {



        $content = is_array($this->content)? $this->content : json_decode($this->content, true);
        $content = array('errno' => $this->status, 'data' => $content);

        if($this->status){
            print_r($content);
        }

        //发送响应
        $ret = $this->getServerResponse()->send($this->fd, RpcServer::encode($content, $this->_header['type'], $this->_header['uid'], $this->_header['serid']));
        if ($ret === false)
        {
            trigger_error("SendToClient failed. params=".var_export(Yii::$app->request->getServerRequest(), true)."\nheaders=".var_export($this->_header, true), E_USER_WARNING);
        }

    }


    /**
     * @inheritdoc
     */
    protected function sendHeaders()
    {
        return;
    }
}
