<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Payments\{Sql,Ui};

final class Account extends BaseController
{
    protected $helpers = ['form'];
    public function index(): \CodeIgniter\HTTP\RedirectResponse
    {
        return redirect()->to('/orders');
    }
    public function login(): string
    {
        return view('login');
    }
    public function authenticate(): \CodeIgniter\HTTP\ResponseInterface
    {
        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        if (!is_string($email) || !is_string($password) || strlen($email) > 254 || strlen($password) > 1024) {
            return $this->response->setStatusCode(400);
        }
        $limit = 'login_' . hash('sha256', $this->request->getIPAddress() . ':' . strtolower($email));
        if (!\CodeIgniter\Config\Services::throttler()->check($limit, 5, 60)) {
            return $this->response->setStatusCode(429);
        }
        $row = Sql::one(Sql::connection(), 'SELECT id,password_hash FROM users WHERE email=?', [strtolower($email)]);
        if ($row === null || !password_verify($password, $row['password_hash'])) {
            return $this->response->setStatusCode(401)->setBody(Ui::text('login_failed'));
        }
        session()->regenerate(true);
        session()->set('user_id', (int) $row['id']);
        return redirect()->to('/orders')->setStatusCode(303);
    }
    public function logout(): \CodeIgniter\HTTP\RedirectResponse
    {
        session()->destroy();
        return redirect()->to('/login')->setStatusCode(303);
    }
}
