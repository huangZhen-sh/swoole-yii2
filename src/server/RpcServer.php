<?php

namespace tourze\swoole\yii2\server;

use swoole_http_request;
use swoole_http_response;
use swoole_http_server;
use Swoole;
use tourze\swoole\yii2\async\RpcTask;
use tourze\swoole\yii2\RpcApplication;
use tourze\swoole\yii2\async\Task;
use tourze\swoole\yii2\Container;
use tourze\swoole\yii2\log\Logger;
use tourze\swoole\yii2\web\Request;
use tourze\swoole\yii2\web\RpcResponse;
use Yii;
use yii\base\ErrorException;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use api\components\utils\YMLog;

/**
 * HTTP服务器
 *
 * @package tourze\swoole\yii2\server
 */
class RpcServer extends \Swoole\Protocol\RPCServer
{
    use ServerTrait;

    /**
     * @var 配置
     */
    public $config;

    /**
     * @var Application
     */
    public $app;

    /**
     * @var string
     */
    public $root;

    /**
     * @var string 缺省文件名
     */
    public $indexFile = 'index.php';


    /**
     * run之前先准备上下文信息
     */
    public function beforeRun($config)
    {
        //初始化原子变量，可多进程共用
        $atomic = ArrayHelper::remove($config,'swooleInit');
        foreach ($atomic as $item){
            $functionName = $item['buildTableFunction'];
            $item['className']::$functionName();
            //$item['className']::${$item['staticVar']} = new \swoole_atomic($item['defaultValue']);
        }
        
        $serverFind =  ArrayHelper::remove($config,'rpcServerFinder');
        if(!empty($serverFind)){
            call_user_func_array($serverFind, [$config]);
        }
    }

    function run($config)
    {
        $this->beforeRun($config);


        $AppSvr = new RpcServer;
        $AppSvr->setLogger(new \Swoole\Log\EchoLog(true)); //Logger
        $AppSvr->config = $config;
        $AppSvr->root = $this->config['root'];
        /**
         * 注册一个自定义的命名空间到SOA服务器
         * 默认使用 apps/classes
         */
       // $AppSvr->addNameSpace('BL', __DIR__ );
        /**
         * IP白名单设置
         */
        $AppSvr->addAllowIP('127.0.0.1');
        $AppSvr->addAllowIP('127.0.0.2');

        /**
         * 设置用户名密码
         */
       // $AppSvr->addAllowUser('chelun', 'chelun@123456');

        Swoole\Error::$echo_html = false;
        $this->server = RpcNetworkServer::autoCreate($AppSvr->config['host'], $AppSvr->config['port']);
        $this->server::setPidFile($this->pidFile);
        $this->server->setProtocol($AppSvr);
        $this->server->setProcessName('swoole-rpcs');
        //$server->daemonize(); //作为守护进程
        parent::run(array_merge(array(
                'worker_num' => 4,
                'max_request' => 5000,
                'dispatch_mode' => 3,
                'open_length_check' => 1,
                'package_max_length' => $AppSvr->packet_maxlen,
                'package_length_type' => 'N',
                'package_body_offset' => RpcServer::HEADER_SIZE,
                'package_length_offset' => 0,
            ), $config['server']));

    }

    function onReceive($serv, $fd, $reactor_id, $data)
    {
        if (!isset($this->_buffer[$fd]) or $this->_buffer[$fd] === '')
        {
            //超过buffer区的最大长度了
            if (count($this->_buffer) >= $this->buffer_maxlen)
            {
                $n = 0;
                foreach ($this->_buffer as $k => $v)
                {
                    $this->close($k);
                    $n++;
                    //清理完毕
                    if ($n >= $this->buffer_clear_num)
                    {
                        break;
                    }
                }
                $this->log("clear $n buffer");
            }
            //解析包头
            $header = unpack(self::HEADER_STRUCT, substr($data, 0, self::HEADER_SIZE));
            //错误的包头
            if ($header === false)
            {
                $this->close($fd);
            }
            $header['fd'] = $fd;
            $this->_headers[$fd] = $header;
            //长度错误
            if ($header['length'] - self::HEADER_SIZE > $this->packet_maxlen or strlen($data) > $this->packet_maxlen)
            {
                return $this->sendErrorMessage($fd, self::ERR_TOOBIG);
            }
            //加入缓存区
            $this->_buffer[$fd] = substr($data, self::HEADER_SIZE);
        }
        else
        {
            $this->_buffer[$fd] .= $data;
        }

        //长度不足
        if (strlen($this->_buffer[$fd]) < $this->_headers[$fd]['length'])
        {
            return true;
        }

        //数据解包
        $request = self::decode($this->_buffer[$fd], $this->_headers[$fd]['type']);
        if ($request === false)
        {
            $this->sendErrorMessage($fd, self::ERR_UNPACK);
        }
        //执行远程调用
        else
        {
            //当前请求的头
            self::$requestHeader = $_header = $this->_headers[$fd];

            //调用端环境变量
            if (!empty($request['env']))
            {
                self::$clientEnv = $request['env'];
            }

            //socket信息
            self::$clientEnv['_socket'] = $this->server->connection_info($_header['fd']);

            $response = $this->handleRequest($request, $fd, $_header);


            //$response = array('errno' => 0, 'data' => ['dd']);
            ////发送响应
            //$ret = $this->server->send($fd, self::encode($response, $_header['type'], $_header['uid'], $_header['serid']));
            //
            //if ($ret === false)
            //{
            //    trigger_error("SendToClient failed. code=".$this->server->getLastError()." params=".var_export($request, true)."\nheaders=".var_export($_header, true), E_USER_WARNING);
            //}
            //退出进程
            if (self::$stop)
            {
                exit(0);
            }
        }
        //清理缓存
        unset($this->_buffer[$fd], $this->_headers[$fd]);
        return true;
    }


    /**
     * 处理请求
     * @param $request
     */
    protected function handleRequest($request, $fd, $_header){
        $webRequest = new Request;
        $webRequest->setServerRequest($request);
        list($appName, $uri) = explode('::', $request['call']);

        $response = clone $this->app->getResponse();
        $response->setFd($fd);
        $response->setHeader($_header);

        //请求的是当前应用
        if($this->config['serverName'] != $appName){
            if(!$this->config['repeatRoute']){
                $response = array('errno' => 1, 'msg' => '');
                return $this->server->send($fd, self::encode($response, $_header['type'], $_header['uid'], $_header['serid']));
            }

            $request['params']['rpcUri'] = $uri;
            $request['params']['rpcServerName'] = $appName;
            $uri = $this->config['repeatRoute'];

        }

        $uri = parse_url($uri);


        $_GET = $_POST = $_COOKIE = $_SERVER = [];

        if($uri['query']){
            parse_str( $uri['query'], $_GET);
        }

        if($request['params']){
            $_POST = $request['params'];
        }

        $_SERVER['REQUEST_URI'] = $uri['path'];
        if (isset($request->server['query_string']) && $request->server['query_string'])
        {
            $_SERVER['REQUEST_URI'] = $uri['path']. '?' . $uri['query'];
        }

        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $_SERVER['DOCUMENT_ROOT'] = $this->root;
        $_SERVER['DOCUMENT_URI'] = $_SERVER['SCRIPT_NAME'] = '/' . $this->indexFile;
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);


        // 使用clone, 原型模式
        // 所有请求都clone一个原生$app对象
        $this->app->getRequest()->setUrl(null);
        $app = clone $this->app;
        Yii::$app =& $app;
        $app->setServerRequest($request);
        $app->setServerResponse($this->server);
        $app->setErrorHandler(clone $this->app->getErrorHandler());
        $app->setRequest(clone $this->app->getRequest());
        $app->setResponse($response);
        //$app->setView(clone $this->app->getView());die('ddd');
        //$app->setSession(clone $this->app->getSession());
        //$app->setUser(clone $this->app->getUser());
        // 部分组件是可以复用的, 所以直接引用
        //$app->setUrlManager($this->app->getUrlManager());

        try
        {
            $app->run();
            $app->afterRun();
        }
        catch (ErrorException $e)
        {
            $app->afterRun();
            if ($this->debug)
            {
                echo (string) $e;
                echo "\n";
                $response->end('');
            }
            else
            {
                $app->getErrorHandler()->handleException($e);
            }
        }
        catch (\Exception $e)
        {
            $app->afterRun();
            if ($this->debug)
            {
                echo (string) $e;
                echo "\n";
                $response->end('');
            }
            else
            {
                $app->getErrorHandler()->handleException($e);
            }
        }
        // 还原环境变量
        Yii::$app = $this->app;
        unset($app);
        $_SERVER = $backupServerInfo;
    }

    /**
     * 处理异步任务
     *
     * @param swoole_server $serv
     * @param mixed $task_id
     * @param mixed $from_id
     * @param string $data
     */
    public function onTask($serv, $task_id, $from_id, $data)
    {
        //echo "New AsyncTask[id=$task_id]".PHP_EOL;
        //$serv->finish("$data -> OK");
        Task::runTask($data, $task_id);
    }

    /**
     * 处理异步任务的结果
     *
     * @param swoole_server $serv
     * @param mixed $task_id
     * @param string $data
     */
    public function onFinish($serv, $task_id, $data)
    {
        //echo "AsyncTask[$task_id] Finish: $data".PHP_EOL;
    }

    /**
     * Worker启动时触发
     *
     * @param swoole_http_server $serv
     * @param $worker_id
     */
    public function onWorkerStart($serv , $worker_id)
    {

        // 初始化一些变量, 下面这些变量在进入真实流程时是无效的
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_URI'] = $_SERVER['SCRIPT_NAME'] = '';


        // 关闭Yii2自己实现的异常错误
        defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
        // 每个worker都创建一个独立的app实例

        // 加载文件和一些初始化配置
        if (isset($this->config['bootstrapFile']))
        {
            foreach ($this->config['bootstrapFile'] as $file)
            {
                require $file;
            }
        }
        $config = [];

        foreach ($this->config['configFile'] as $file)
        {
            $config = ArrayHelper::merge($config, include $file);
        }

        if (isset($this->config['bootstrapRefresh']))
        {
            $config['bootstrapRefresh'] = $this->config['bootstrapRefresh'];
        }

        // 为Yii分配一个新的DI容器
        if (isset($this->config['persistClasses']))
        {
            Container::$persistClasses = ArrayHelper::merge(Container::$persistClasses, $this->config['persistClasses']);
            Container::$persistClasses = array_unique(Container::$persistClasses);
        }
        //重定义response类
        Container::$classAlias = array_merge(Container::$classAlias, [
            'yii\web\Response'     => 'tourze\swoole\yii2\web\RpcResponse',
            'yii\web\ErrorHandler' => 'tourze\swoole\yii2\web\RpcErrorHandler',
        ]);
        Yii::$container = new Container();

        if ( ! isset($config['components']['assetManager']['basePath']))
        {
            $config['components']['assetManager']['basePath'] = $this->root . '/assets';
        }
        $config['aliases']['@webroot'] = $this->root;
        $config['aliases']['@web'] = '/';
        $this->app = RpcApplication::$workerApp = new RpcApplication($config);
        Yii::setLogger(new Logger());
        $this->app->setRootPath($this->root);
        $this->app->setServer($this->server);
        $this->app->prepare();

    }

}
