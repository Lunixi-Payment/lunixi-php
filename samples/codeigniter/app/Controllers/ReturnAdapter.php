<?php

declare(strict_types=1);

namespace App\Controllers;

/** Informational merchantCallback POST: no session/order lookup, no submitted fields and no financial writes. */
final class ReturnAdapter extends BaseController
{
    public function receive(string $id): \CodeIgniter\HTTP\ResponseInterface
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            return $this->response->setStatusCode(404);
        }
        $size = $this->request->getHeaderLine('Content-Length');
        if ((ctype_digit($size) && (int)$size > 65536) || strlen((string)$this->request->getBody()) > 65536) {
            return $this->response->setStatusCode(413);
        }
        // SameSite=Lax cookies may be absent on this cross-site POST. The following owned GET authenticates.
        return $this->response->setStatusCode(303)->setHeader('Location', '/payments/' . $id . '/return')->setHeader('Cache-Control', 'no-store')->setBody('');
    }
}
