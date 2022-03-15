<?php
/**
 * Created by PhpStorm.
 * User: 16020028
 * Date: 2018/1/20
 * Time: 13:21
 */

namespace tourze\swoole\yii2\web;

use swoole_http_response;
use tourze\swoole\yii2\Application;
use Yii;
use yii\base\ExitException;
use yii\base\UserException;
use yii\web\HttpException;

/**
 * @property swoole_http_response serverResponse
 */
class RpcErrorHandler extends ErrorHandler
{


    /**
     * @inheritdoc
     */
    protected function renderException($exception)
    {
        if ( ! Application::$workerApp)
        {
            parent::renderException($exception);
            return;
        }
        Yii::error($exception, $category);

        ////非致命性的错误
        //if (!$exception instanceof ExitException) {
        //    return;
        //}
        $response = Yii::$app->response;
        $useErrorView = $response->format === Response::FORMAT_HTML && ( ! YII_DEBUG || $exception instanceof UserException);

        if ($useErrorView && $this->errorAction !== null)
        {
            $result = Yii::$app->runAction($this->errorAction);
            if ($result instanceof Response)
            {
                $response = $result;
            }
            else
            {
                $response->data = $result;
            }
        }
        elseif ($response->format === Response::FORMAT_HTML)
        {
            if (YII_ENV_TEST || isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')
            {
                // AJAX request
                $response->data = '<pre>' . $this->htmlEncode(static::convertExceptionToString($exception)) . '</pre>';
            }
            else
            {
                // if there is an error during error rendering it's useful to
                // display PHP error in debug mode instead of a blank screen
                if (YII_DEBUG)
                {
                    ini_set('display_errors', 1);
                }
                $file = $useErrorView ? $this->errorView : $this->exceptionView;
                $response->data = $this->renderFile($file, [
                    'exception' => $exception,
                ]);
            }
        }
        elseif ($response->format === Response::FORMAT_RAW)
        {
            $response->data = static::convertExceptionToString($exception);
        }
        else
        {
            $response->data = $this->convertExceptionToArray($exception);
        }

        if ($exception instanceof HttpException)
        {
            $response->setStatusCode($exception->statusCode);
        }
        else
        {
            $response->setStatusCode(500);
        }
        $response->send();
    }

    /**
     * @inheritdoc
     */
    public function handleException($exception)
    {
        if ( ! $this->getServerResponse())
        {
            echo "ddd\n";exit;
            parent::handleException($exception);
            return;
        }
        if ($exception instanceof ExitException)
        {
            Yii::$app->response->end('');
            return;
        }

        $this->exception = $exception;
        Yii::$app->response->status(500);

        try
        {
            $this->logException($exception);
            if ($this->discardExistingOutput)
            {
                $this->clearOutput();
            }
            $this->renderException($exception);
        }
        catch (\Exception $e)
        {
            // an other exception could be thrown while displaying the exception
            $msg = "An Error occurred while handling another error:\n";
            $msg .= (string) $e;
            $msg .= "\nPrevious exception:\n";
            $msg .= (string) $exception;

            $retData = [
                'time_taken'    => bcsub(sprintf("%.20f", microtime(true)), sprintf("%.20f",$_SERVER['REQUEST_TIME_FLOAT']), 8),// 本次请求消耗的时间
                //'status'        => (string) $e->getCode(),// 返回的状态码
                //'status_txt'    => $e->getMessage(),// 相关状态的提示信息，一般成功时，无返回
                //'results'       => [],// 返回的结果，始终返回一个数组
                //'links'         => [],// 暂无使用
                //'time'          => (string) time(),// 请求完结时的时间戳
                //'errorData'     => [
                //        'message' => $e->getMessage(),
                //        'file' => " {$e->getFile()}({$e->getLine(}))",
                //        'trace'=> $e->getTraceAsString(),
                //    ],
            ];

            print_r($retData);exit;
            Yii::$app->response->data = $retData;
            Yii::$app->response->send();
        }

        $this->exception = null;
    }

}
