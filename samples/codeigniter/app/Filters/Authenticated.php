<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\{RequestInterface,ResponseInterface};

final class Authenticated implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $id = session()->get('user_id');
        if (!is_int($id) || $id < 1) {
            return \CodeIgniter\Config\Services::response()->setStatusCode(401)->setBody(\App\Payments\Ui::text('login_required'));
        }
        return null;
    }
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return null;
    }
}
