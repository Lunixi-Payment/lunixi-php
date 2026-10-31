<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\Runtime;
use app\components\Checkout;

final class WebhookController extends \yii\web\Controller
{
    // Only this signed server-to-server endpoint is excluded; browser controllers retain native CSRF.
    public $enableCsrfValidation = false;
    public function behaviors(): array
    {
        return ['verbs' => ['class' => \yii\filters\VerbFilter::class,'actions' => ['receive' => ['POST']]]];
    }
    public function actionReceive(): string
    {
        $body = Runtime::web()->request->getRawBody();
        if (strlen($body) > 1048576) {
            Runtime::web()->response->statusCode = 413;
            return '';
        }
        $headers = [];
        foreach (Runtime::web()->request->headers as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        try {
            $service = Runtime::web()->get('checkout');
            if (!$service instanceof Checkout) {
                throw new \RuntimeException('Payment service configuration invalid.');
            }
            $service->webhook($body, $headers);
            return 'OK';
        } catch (\Throwable $e) {
            Runtime::web()->response->statusCode = 503;
            return 'Retry delivery';
        }
    }
}
