<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\Runtime;
use app\components\{Checkout,Ui};
use yii\filters\{AccessControl,VerbFilter};

final class PaymentController extends \yii\web\Controller
{
    public function behaviors(): array
    {
        return ['access' => ['class' => AccessControl::class,'rules' => [['allow' => true,'roles' => ['@']]]], 'verbs' => ['class' => VerbFilter::class,'actions' => ['checkout' => ['POST'],'returned' => ['GET']]]];
    }
    public function actionCheckout(string $id): string|\yii\web\Response
    {
        try {
            $service = $this->checkout();
            $number = $this->id($id);
            $owner = (int) Runtime::web()->user->id;
            $session = $service->start($number, $owner);
            $order = $service->order($number, $owner);
            Runtime::web()->response->headers->set('Cache-Control', 'no-store');
            Runtime::web()->response->headers->set('Referrer-Policy', 'no-referrer');
            if ($order['mode'] === 'hosted') {
                return $this->redirect($session->hostedUrl(), 303);
            }
            return $this->render('checkout', ['config' => $session->browserConfig()]);
        } catch (\DomainException $e) {
            throw new \yii\web\NotFoundHttpException(Ui::text('not_found'));
        } catch (\Throwable $e) {
            throw new \yii\web\ConflictHttpException(Ui::text('review'));
        }
    }
    public function actionReturned(string $id): string
    {
        // Browser status/amount/payment ID never updates an order or calls a financial API.
        try {
            $order = $this->checkout()->order($this->id($id), (int) Runtime::web()->user->id);
        } catch (\Throwable $e) {
            throw new \yii\web\NotFoundHttpException(Ui::text('not_found'));
        }
        Runtime::web()->response->headers->set('Cache-Control', 'no-store');
        return $this->render('/site/orders', ['orders' => [$order]]);
    }
    private function checkout(): Checkout
    {
        $service = Runtime::web()->get('checkout');
        if (!$service instanceof Checkout) {
            throw new \RuntimeException('Payment service configuration is invalid.');
        }
        return $service;
    }
    private function id(string $id): int
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            throw new \DomainException('Order unavailable.');
        }
        return (int) $id;
    }
}
