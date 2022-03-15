<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace tourze\swoole\yii2\db;

use yii\debug\components\search\matchers\Base;
/**
 * Checks if the given value is lower than the base one.
 *
 * @author Mark Jebri <mark.github@yandex.ru>
 * @since 2.0
 */
class MatchersIn extends Base
{
    /**
     * @inheritdoc
     */
    public function match($value)
    {
        return  in_array($value, $this->baseValue);
    }
}
