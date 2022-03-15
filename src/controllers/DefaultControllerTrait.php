<?php
/**
 * Created by PhpStorm.
 * User: 16020028
 * Date: 2018/1/18
 * Time: 14:31
 */
namespace tourze\swoole\yii2\controllers;

use Yii;
use yii\web\Response;
use yii\rest\Controller;
use api\convention\ApiCode;
use yii\filters\ContentNegotiator;
use api\convention\format\AppDataFormat;

/**
 * 用于优化控制器的createAction方法
 * 在需要优化的控制器中use即可
 *
 * @package tourze\swoole\yii2\controllers
 */
trait DefaultControllerTrait
{

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::className(),
                'formats' => [
                    'application/json' => Response::FORMAT_JSON,
                    'application/xml' => Response::FORMAT_XML,
                ],
            ],
        ];
    }

    /**
     * 引导，task异步执行示例
     * @return array
     */
    public function actionIndex($id=null)
    {
        //设置输出格式为json
        return AppDataFormat::getResponseData(ApiCode::SUCC, 'welcome swoole server', [
            'name' => Yii::$app->name,
            'port' =>Yii::$app->getServer()->port
        ]);
    }

    /**
     * 关闭服务
     * @return array
     */
    public function actionShutdown()
    {

        $res = Yii::$app->getServer()->shutdown();

        if($res){
            return AppDataFormat::getResponseData(ApiCode::SUCC, "shutdown success");
        }else{
            return AppDataFormat::getResponseData(ApiCode::SYS_ERROR, "shutdown error");
        }
    }

    /**
     * 服务重载
     * @return array
     */
    public function actionReload()
    {
        $res = Yii::$app->getServer()->reload();

        if($res){
            return AppDataFormat::getResponseData(ApiCode::SUCC, "reload success");
        }else{
            return AppDataFormat::getResponseData(ApiCode::SYS_ERROR, "reload error");
        }
    }
}
