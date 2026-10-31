<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\Runtime;

/** One stateless informational callback adapter; owned GET remains authenticated and read-only. */
final class ReturnController extends \yii\web\Controller
{
    public $enableCsrfValidation = false;
    public function behaviors(): array
    {
        return ['verbs' => ['class' => \yii\filters\VerbFilter::class,'actions' => ['receive' => ['POST']]]];
    }
    public function actionReceive(string $id): \yii\web\Response
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new \yii\web\NotFoundHttpException();
        }
        $request = Runtime::web()->request;
        $size = (string)$request->headers->get('Content-Length', '');
        if ((ctype_digit($size) && (int)$size > 65536) || strlen($request->getRawBody()) > 65536) {
            Runtime::web()->response->statusCode = 413;
            return Runtime::web()->response;
        }
        // Do not load user/order, parse posted status/signature or perform a gateway request.
        Runtime::web()->response->headers->set('Cache-Control', 'no-store');
        $response = Runtime::web()->response;
        $response->statusCode = 303;
        $response->headers->set('Location', '/payments/' . $id . '/return');
        $response->data = '';
        return $response;
    }
}
