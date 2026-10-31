<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Retain native protections without sending checkout URLs as referrers. */
final class SecureHeaders extends \CodeIgniter\Filters\SecureHeaders
{
    /** @param list<string>|null $arguments */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        parent::after($request, $response, $arguments);
        return $response->setHeader('Referrer-Policy', 'no-referrer');
    }
}
