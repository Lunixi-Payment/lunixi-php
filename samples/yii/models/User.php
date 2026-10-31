<?php

declare(strict_types=1);

namespace app\models;

use app\components\Runtime;
use app\components\Store;

final class User implements \yii\web\IdentityInterface
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }
    /** @param mixed $id */
    public static function findIdentity($id): ?self
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
            return null;
        }
        $row = (new Store(Runtime::db()))->one('SELECT * FROM users WHERE id=?', [$id]);
        return $row === null ? null : new self($row);
    }
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new \yii\base\NotSupportedException('This example uses native session identity only.');
    }
    public function getId(): int
    {
        return (int) $this->data['id'];
    }
    public function getAuthKey(): string
    {
        return $this->data['auth_key'];
    }
    /** @param mixed $authKey */
    public function validateAuthKey($authKey): bool
    {
        return is_string($authKey) && hash_equals($this->getAuthKey(), $authKey);
    }
}
