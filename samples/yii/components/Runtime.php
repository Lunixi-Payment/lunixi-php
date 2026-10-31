<?php

declare(strict_types=1);

namespace app\components;

final class Runtime
{
    /** @return \yii\web\Application<\yii\web\IdentityInterface> */
    public static function web(): \yii\web\Application
    {
        $app = \Yii::$app;
        if (!$app instanceof \yii\web\Application) {
            throw new \LogicException('Native web application is required.');
        }
        return $app;
    }
    public static function db(): \yii\db\Connection
    {
        $app = \Yii::$app;
        if (!$app instanceof \yii\web\Application && !$app instanceof \yii\console\Application) {
            throw new \LogicException('Native application is required.');
        }
        return $app->getDb();
    }
}
