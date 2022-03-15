<?php

namespace tourze\swoole\yii2\commands;

use tourze\swoole\yii2\server\HttpServer;
use tourze\swoole\yii2\server\ApiServer;
use tourze\swoole\yii2\server\RpcServer;
use Yii;
use yii\console\Controller;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use yii\helpers\FileHelper;

class SwooleController extends Controller
{
    public $serverName = '\tourze\swoole\yii2\server\ApiServer';

    /**
     * @var 端口
     */
    public $port;

    /**
     * @var 帮助
     */
    public $helpHand;

    /**
     * @var 启动命令
     */
    public $rpcCommonds = ['start'=>'start rpc service (default)',
                           'reload'=>'reload rpc service',
                           'stop'=>'shutdown rpc service',
                           'restart'=>'restart rpc service',
                        ];

    /**
     * @var 持久化
     */
    public $daemonize = null;

    /**
     * Run swoole http server
     *
     * @param string $app Running app
     * @throws \yii\base\InvalidConfigException
     */
    public function actionHttp($app)
    {
        /** @var HttpServer $server */
        $server = new HttpServer;
        $server->run($app);
    }

    /**
     * swoole api server RPC 协议服务
     * @param null   $app
     * @param string $commond
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function actionRpcs($app=null, $commond='start')
    {
        $this->serverName = '\tourze\swoole\yii2\server\RpcServer';
        $this->actionRpc($app, $commond);
    }
    
    /**
     * swoole rpc server RPC接口服务
     * @desp 一次只能开启一个服务
     *
     * @param string $app service name
     * @param string $commond cmd
     * @param string $port service port
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function actionRpc($app=null, $commond='start')
    {
        $config = Yii::$app->params['swooleServer'];
        $baseRoot = ArrayHelper::remove($config, 'baseRoot');

        $service = scandir($baseRoot);
        $service = array_filter($service, function ($a){
            return strpos($a, '.')!==0;
        });
        sort($service);

        if(array_key_exists($app, $this->rpcCommonds)){
            $commond = $app;
            $app = null;
        }
        if(!$app || !in_array($app, $service) || $this->helpHand){
            $this->rpcHelp($baseRoot, $service);

            //帮助
            if($this->helpHand){
                return;
            }
        }

        //选择应用
        while (!(in_array($app, $service))) {
            $appId = $this->select("plase select $commond rpcService, port:".($this->port?$this->port:'default')."\n", $service);
            $app   = isset($service[$appId]) ? $service[$appId] : '';
        }

        $rpcConfigFile = $baseRoot.DIRECTORY_SEPARATOR.$app.DIRECTORY_SEPARATOR.'config/swoole.service.php';
        $appConfigFile = $baseRoot.DIRECTORY_SEPARATOR.$app.DIRECTORY_SEPARATOR.'config/yii.application.php';
        if(!file_exists($appConfigFile) || !file_exists($rpcConfigFile) ){
            $this->resOut("service config (config/swoole.service.php;config/yii.application.php) not exists",  Console::FG_RED);
            return 1;
        }
        $conf = require($rpcConfigFile);
        $appConf = require($appConfigFile);


        if($this->port){
            $conf['port'] = $this->port;
        }

        if($this->daemonize !== null){
            $conf['server']['daemonize'] = (bool)$this->daemonize;
        }

        if(!$conf['server']['daemonize']){
            $conf['server']['log_file'] = null;
        }

        $conf['serverName'] = $app;
        $server = new $this->serverName;
        $server->pidFile = $this->getPidPath()."/rpc_{$conf['port']}.pid";

        //关闭服务
        if($commond=='stop'){
            if(!$server->isRunning()){
                $this->resOut("stop $app warning, port: {$conf['port']} not runing", Console::FG_YELLOW);
                return 1;
            }


            $res = $server->stop();
            if($res){
                $this->resOut("stop $app success",  Console::FG_GREEN);
            }else{
                $this->resOut( "stop $app fail, Please try again later",  Console::FG_RED);
            }
            return $res?0:1;
        }

        //开启服务
        if($commond=='start'){
            if($server->isRunning() && $conf['server']['daemonize']){
                $this->resOut("start $app warning, port: {$conf['port']} has been occupied, plase sure service is already runing", Console::FG_YELLOW);
                return 1;
            }

            $this->resOut("start $app success, port: {$conf['port']}, name: {$appConf['name']}", Console::FG_GREEN);
            $server->run($conf);
            return 1;
        }

        //重新启动服务
        if($commond=='restart'){
            $this->stdout("                    Information Panel                    \n", Console::FG_GREEN);
            $this->stdout("*********************************************************\n", Console::FG_GREEN);

            if($server->isRunning()){
                $server->stop();
                $this->stdout("stop $app success, port: {$conf['port']}\n",  Console::FG_GREEN);
            }else{
                $this->stdout("stop $app warning, port: {$conf['port']} not runing\n", Console::FG_YELLOW);
            }
            $this->stdout("start $app success, port: {$conf['port']}, name: {$appConf['name']}\n", Console::FG_GREEN);
            $this->stdout("*********************************************************\n", Console::FG_GREEN);
            $server->run($conf);
            return 1;
        }

        if($commond=='reload'){
            if(!$server->isRunning()){
                $this->resOut("reload $app error, port: {$conf['port']}  is not runing", Console::FG_RED);
                return 1;
            }

            $res = $server->reload();
            if($res){
                $this->resOut("reload $app success, port: {$conf['port']}, name: {$appConf['name']}", Console::FG_GREEN);
            }else{
                $this->resOut("reload $app fail, port: {$conf['port']}, name: {$appConf['name']}", Console::FG_RED);
            }
        }

        //$this->stdout(" service:$app start success\n", Console::FG_GREEN);
    }


    /**
     * 输出
     * @param     $text
     * @param int $args
     */
    public function resOut($text,$args=Console::FG_YELLOW){
        //$args = func_get_args();
        //array_shift($args);
        $this->stdout("                    Information Panel                    \n", $args);
        $this->stdout("*********************************************************\n", $args);
        $this->stdout("$text\n", $args);
        $this->stdout("*********************************************************\n", $args);
    }



    /**
     * 提示rpc帮助
     * @param $baseRoot
     * @param $service
     */
    private function rpcHelp($baseRoot, $service){
        $len = 30;
        $this->stdout($this->ansiFormat("Used\n", Console::FG_YELLOW));
        $this->stdout('    ' .$this->ansiFormat("php yii swoole/rpc [RpcService|Command] [Command] [-Options==Value ...] \n",  Console::FG_PURPLE));

        $this->stdout("\n");
        $this->stdout($this->ansiFormat("RpcServiceLists\n", Console::FG_YELLOW));
        foreach ($service as $key=>$ser){
            $this->stdout('    ' .$this->ansiFormat("$key, $ser", Console::FG_GREEN));
            $this->stdout(str_repeat(' ', $len + 4 - strlen($ser)));
            $appConfigFile = $baseRoot.DIRECTORY_SEPARATOR.$ser.DIRECTORY_SEPARATOR.'config/yii.application.php';
            $rpcConfigFile = $baseRoot.DIRECTORY_SEPARATOR.$ser.DIRECTORY_SEPARATOR.'config/swoole.service.php';
            if(!file_exists($appConfigFile) || !file_exists($rpcConfigFile) ){
                $this->stdout(Console::wrapText("config is not available", $len + 4 + 2));
            }else{
                $yiiConf = require($appConfigFile);
                $swooleConf = require($rpcConfigFile);
                $this->stdout($yiiConf['name']);
                $this->stdout(str_repeat(' ', $len - mb_strlen($yiiConf['name'])*2));
                $this->stdout('default port:'.$swooleConf['port'], Console::FG_GREY);
            }
            $this->stdout("\n");
        }

        $this->stdout("\n");
        $this->stdout($this->ansiFormat("Commands\n", Console::FG_YELLOW));
        $commonds = $this->rpcCommonds;
        foreach ($commonds as $com=>$desp){
            $this->stdout('    ' .$this->ansiFormat($com, Console::FG_GREEN));
            $this->stdout(str_repeat(' ', $len + 4 - strlen($com)));
            $this->stdout(Console::wrapText($desp, $len + 4 + 2));
            $this->stdout("\n");
        }

        $this->stdout("\n");
        $this->stdout($this->ansiFormat("Options\n", Console::FG_YELLOW));
        $commonds = ['-p'=>'swoole service port', '-h'=> 'show help', '-d' => 'daemonize',];
        foreach ($commonds as $com=>$desp) {
            $this->stdout('    '.$this->ansiFormat($com, Console::FG_GREEN));
            $this->stdout(str_repeat(' ', $len + 4 - strlen($com)));
            $this->stdout(Console::wrapText($desp, $len + 4 + 2));
            $this->stdout("\n");
        }

        $this->stdout("\n\n");
    }

    /**
     * 动态参数
     * @param string $actionID
     *
     * @return array|\string[]
     */
    public function options($actionID)
    {
        $parentOptions = parent::options($actionID);
        if($actionID=='rpc' || $actionID=='rpcs' ){
            $parentOptions = ['port', 'helpHand', 'daemonize'];
        }

        return $parentOptions;
    }

    /**
     * 参数别名
     * @return array
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'p' => 'port',
            'h' => 'helpHand',
            'd' => 'daemonize',
        ]);
    }

    /**
     * 获取pid文件保存目录
     * @return string
     * @throws \yii\base\Exception
     */
    public function getPidPath()
    {
        $path = Yii::$app->getRuntimePath() . '/swoole-rpc';
        if (!is_dir($path)) {
            FileHelper::createDirectory($path, 0775, true);
        }

        return $path;
    }
}
