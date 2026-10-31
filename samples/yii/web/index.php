<?php
declare(strict_types=1);
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', getenv('APP_ENV') === 'dev' ? 'dev' : 'prod');
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
(new yii\web\Application(require __DIR__ . '/../config/web.php'))->run();
