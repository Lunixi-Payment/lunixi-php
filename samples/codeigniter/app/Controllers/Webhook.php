<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Payments\{Sql,Ui};
use Config\Services;

final class Webhook extends BaseController
{
    public function receive(): \CodeIgniter\HTTP\ResponseInterface
    {
        $body = $this->request->getBody();
        if (!is_string($body) || strlen($body) > 1048576) {
            return $this->response->setStatusCode(413);
        }
        $headers = [];
        foreach ($this->request->headers() as $name => $header) {
            $headers[$name] = $this->request->getHeaderLine($name);
        }
        try {
            Services::lunixi()->webhook($body, $headers);
            return $this->response->setStatusCode(200)->setBody('OK');
        } catch (\Throwable $error) {
            // Never log raw body, headers, bearer/browser token, private key or buyer data.
            return $this->response->setStatusCode(503)->setBody('Retry delivery');
        }
    }
}
