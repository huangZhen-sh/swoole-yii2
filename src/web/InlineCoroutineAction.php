<?php

namespace tourze\swoole\yii2\web;

use Swoole;
use swoole_http_response;
use tourze\swoole\yii2\Application;
use Yii;
use yii\base\ExitException;
use yii\base\UserException;
use yii\web\HttpException;

class InlineCoroutineAction extends \yii\base\InlineAction
{

    /**
     * Runs this action with the specified parameters.
     * This method is mainly invoked by the controller.
     * @param array $params action parameters
     * @return mixed the result of the action
     */
    public function runWithParams($params)
    {
        $args = $this->controller->bindActionParams($this, $params);
        Yii::trace('Running action: ' . get_class($this->controller) . '::' . $this->actionMethod . '()', __METHOD__);
        if (Yii::$app->requestedParams === null) {
            Yii::$app->requestedParams = $args;
        }

        return Swoole\Coroutine::call_user_func_array([$this->controller, $this->actionMethod], $args);
    }

}