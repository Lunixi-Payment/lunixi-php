<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Payments\{Sql,Ui};
use App\Payments\Checkout;
use Config\Services;

final class Payments extends BaseController
{
    protected $helpers = ['form'];
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = Sql::all(Sql::connection(), 'SELECT * FROM orders WHERE user_id=? ORDER BY id', [session()->get('user_id')]);
        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('orders', ['orders' => $rows]));
    }
    public function checkout(string $rawId): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $id = $this->id($rawId);
            $service = Services::lunixi();
            $session = $service->start($id, session()->get('user_id'));
            $order = $service->order($id, session()->get('user_id'));
            $this->response->setHeader('Cache-Control', 'no-store')->setHeader('Referrer-Policy', 'no-referrer');
            if ($order['mode'] === 'hosted') {
                return redirect()->to($session->hostedUrl())->setStatusCode(303)
                    ->setHeader('Cache-Control', 'no-store')->setHeader('Referrer-Policy', 'no-referrer');
            }
            return $this->response->setBody(view('checkout', ['config' => $session->browserConfig()]));
        } catch (\DomainException $error) {
            return $this->response->setStatusCode(404)->setBody(Ui::text('not_found'));
        } catch (\Throwable $error) {
            return $this->response->setStatusCode(409)->setBody(Ui::text('review'));
        }
    }
    public function returned(string $rawId): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            // Query status/amount/token parameters from the browser are deliberately ignored.
            $order = Services::lunixi()->order($this->id($rawId), session()->get('user_id'));
            return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('orders', ['orders' => [$order]]));
        } catch (\Throwable $error) {
            return $this->response->setStatusCode(404);
        }
    }
    private function id(string $id): int
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new \DomainException('Invalid order.');
        }
        return (int) $id;
    }
}
