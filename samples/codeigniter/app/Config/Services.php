<?php
declare(strict_types=1);
namespace Config;
final class Services extends \CodeIgniter\Config\BaseService
{
    public static function lunixi(bool $getShared = true): \App\Payments\Checkout
    {
        if ($getShared) { return static::getSharedInstance('lunixi'); }
        return new \App\Payments\Checkout(\App\Payments\Sql::connection(), \App\Payments\Settings::fromEnvironment());
    }
}
