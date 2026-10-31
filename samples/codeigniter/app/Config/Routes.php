<?php
declare(strict_types=1);
use CodeIgniter\Router\RouteCollection;
/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('/', 'Account::index');
$routes->get('login', 'Account::login');
$routes->post('login', 'Account::authenticate');
$routes->post('logout', 'Account::logout', ['filter' => 'authenticated']);
$routes->get('orders', 'Payments::index', ['filter' => 'authenticated']);
$routes->post('payments/(:num)', 'Payments::checkout/$1', ['filter' => 'authenticated']);
$routes->post('payments/(:num)/return', 'ReturnAdapter::receive/$1');
$routes->get('payments/(:num)/return', 'Payments::returned/$1', ['filter' => 'authenticated']);
$routes->post('webhooks/lunixi', 'Webhook::receive');
