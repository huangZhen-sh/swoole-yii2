<?php

namespace tourze\swoole\yii2;

use Yii;
use swoole_http_request;
use swoole_http_response;
use swoole_http_server;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\Widget;

/**
 * @property swoole_http_request  serverRequest
 * @property swoole_http_response serverResponse
 * @property swoole_http_server   server
 * @property string rootPath
 */
class RpcApplication extends Application
{
    public $appInit;
    public $worker_id;

    /**
     * 初始化流程
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function bootstrap()
    {
        //if ( ! static::$webAliasInit)
        //{
        //    $request = $this->getRequest();
        //    Yii::setAlias('@webroot', dirname($request->getScriptFile()));
        //    Yii::setAlias('@web', $request->getBaseUrl());
        //    static::$webAliasInit = true;
        //}
        
        //服务自定义初始化
        if($this->appInit)
        {
            $class = Yii::createObject(['class'=>array_shift($this->appInit)]);
            $functionName = array_shift($this->appInit);
            if(method_exists($class, $functionName)){
                $class->$functionName();
            }
        }
        //$this->extensionBootstrap();
        //$this->moduleBootstrap();
    }

    /**
     * run之前先准备上下文信息
     */
    public function beforeRun()
    {
        Event::offAll();
        // widget计数器等要清空
        Widget::$counter = 0;
        Widget::$stack = [];
        $this->getErrorHandler()->setServerResponse($this->getServerResponse());
        // 上面处理了$_GET部分, 但是没处理$_POST部分.
        $this->getRequest()->setHostInfo('http://' . $this->getServerRequest()->header['host']);
        $this->getRequest()->setPathInfo($this->getServerRequest()->server['path_info']);
        $this->getRequest()->setServerRequest($this->getServerRequest());
        $this->getResponse()->setServerResponse($this->getServerResponse());
        foreach ($this->bootstrap as $k => $component)
        {
            if ( ! is_object($component))
            {
                if ($this->has($component))
                {
                    $component = $this->get($component);
                }
                elseif ($this->hasModule($component))
                {
                    $component = $this->getModule($component);
                }
            }
            if (in_array(get_class($component), $this->bootstrapRefresh))
            {
                /** @var BootstrapInterface $component */
                $component->bootstrap($this);
            }
            elseif ($component instanceof Refreshable)
            {
                $component->refresh();
            }
        }
    }
    
    /**
     * 预热一些可以浅复制的对象
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function prepare()
    {
        $this->getLog()->setLogger(Yii::getLogger());
        $this->getSecurity();
        $this->getUrlManager();
        $this->getRequest()->setBaseUrl('');
        $this->getRequest()->setScriptUrl('/index.php');
        $this->getRequest()->setScriptFile('/index.php');
        $this->getRequest()->setUrl(null);
        $this->getResponse();
        foreach ($this->getResponse()->formatters as $type => $class)
        {
            $this->getResponse()->formatters[$type] = Yii::createObject($class);
        }
        //$this->getSession();
        //$this->getAssetManager();
        //$this->getView();
        //$this->getDb();
        //$this->getUser();
        //$this->getMailer();
    }
    
    /**
     * 用于收尾
     * 这里因为用了swoole的task,所以性能很低
     */
    public function afterRun()
    {
        Yii::getLogger()->flush();
    }
    
}
