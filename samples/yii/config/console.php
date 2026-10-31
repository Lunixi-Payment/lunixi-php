<?php
declare(strict_types=1);
return [
    'id' => 'lunixi-yii-console', 'basePath' => dirname(__DIR__), 'controllerNamespace' => 'app\\commands',
    'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite:' . (getenv('LUNIXI_DATABASE') ?: dirname(__DIR__) . '/runtime/lunixi.sqlite')]],
];
