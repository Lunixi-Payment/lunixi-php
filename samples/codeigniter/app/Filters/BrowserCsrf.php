<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\{IncomingRequest,RequestInterface,RedirectResponse};

/** Native CSRF remains enabled everywhere except the signed webhook and stateless POST-to-GET adapter. */
final class BrowserCsrf extends \CodeIgniter\Filters\CSRF
{
    public function before(RequestInterface $request, $arguments = null): ?RedirectResponse
    {
        if ($request instanceof IncomingRequest && $request->getMethod() === 'POST') {
            $path = ltrim($request->getPath(), '/');
            if ($path === 'webhooks/lunixi' || preg_match('~^payments/[1-9][0-9]{0,17}/return$~D', $path)) {
                return null;
            }
        }
        return parent::before($request, $arguments);
    }
}
