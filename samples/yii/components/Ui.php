<?php

declare(strict_types=1);

namespace app\components;

final class Ui
{
    public static function text(string $key): string
    {
        return \Yii::t('lunixi', $key);
    }
    public static function escape(string $text): string
    {
        return \yii\helpers\Html::encode($text);
    }
}
