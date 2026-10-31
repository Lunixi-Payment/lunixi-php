<?php
declare(strict_types=1);
use app\components\{Checkout,Settings,Store};
$cookieKey = (string) getenv('YII_COOKIE_KEY');
if (strlen($cookieKey) < 32) { throw new RuntimeException('Set a separate YII_COOKIE_KEY of at least 32 characters.'); }
$local = YII_ENV_DEV && getenv('YII_LOCAL_HTTP') === '1';
return [
    'id' => 'lunixi-yii-checkout', 'basePath' => dirname(__DIR__), 'defaultRoute' => 'site/orders',
    'language' => getenv('APP_LANGUAGE') === 'tr' ? 'tr' : 'en',
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite:' . (getenv('LUNIXI_DATABASE') ?: dirname(__DIR__) . '/runtime/lunixi.sqlite')],
        'request' => ['cookieValidationKey' => $cookieKey, 'enableCsrfCookie' => false, 'csrfParam' => '_csrf', 'trustedHosts' => []],
        'session' => ['class' => yii\web\Session::class, 'savePath' => '@app/runtime', 'useCookies' => true, 'cookieParams' => ['httpOnly' => true, 'secure' => !$local, 'sameSite' => 'Lax']],
        'user' => ['identityClass' => app\models\User::class, 'enableAutoLogin' => false, 'loginUrl' => ['site/login']],
        'checkout' => static fn () => new Checkout(new Store(Yii::$app->db), Settings::fromEnvironment()),
        'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false, 'enableStrictParsing' => true, 'rules' => [
            '' => 'site/orders', 'login' => 'site/login', 'logout' => 'site/logout', 'orders' => 'site/orders',
            'POST payments/<id:\d+>/return' => 'return/receive',
            'payments/<id:\d+>' => 'payment/checkout', 'payments/<id:\d+>/return' => 'payment/returned', 'webhooks/lunixi' => 'webhook/receive',
        ]],
        'i18n' => ['translations' => ['lunixi' => ['class' => yii\i18n\PhpMessageSource::class, 'basePath' => '@app/messages', 'sourceLanguage' => 'en-US', 'forceTranslation' => true]]],
        'errorHandler' => ['class' => yii\web\ErrorHandler::class],
        'log' => ['targets' => []],
    ],
];
