<?php

declare(strict_types=1);

namespace app\controllers;

use app\components\Runtime;
use app\components\{Store,Ui};
use app\models\User;
use yii\filters\{AccessControl,VerbFilter};

final class SiteController extends \yii\web\Controller
{
    public function behaviors(): array
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['login' => ['GET','POST'], 'logout' => ['POST'], 'orders' => ['GET']]],
            'access' => ['class' => AccessControl::class, 'only' => ['orders','logout'], 'rules' => [['allow' => true, 'roles' => ['@']]]],
        ];
    }
    public function actionLogin(): string|\yii\web\Response
    {
        $request = Runtime::web()->request;
        if (!$request->isPost) {
            return $this->render('login');
        }
        $email = $request->post('email');
        $password = $request->post('password');
        if (!is_string($email) || !is_string($password) || strlen($email) > 254 || strlen($password) > 1024) {
            throw new \yii\web\BadRequestHttpException();
        }
        $store = new Store(Runtime::db());
        $key = hash('sha256', (string) $request->userIP . ':' . strtolower($email));
        $window = intdiv(time(), 60);
        $store->execute('INSERT INTO login_limits(id,window,attempts) VALUES (?,?,1) ON CONFLICT(id) DO UPDATE SET attempts=CASE WHEN window=excluded.window THEN attempts+1 ELSE 1 END,window=excluded.window', [$key,$window]);
        $limit = $store->one('SELECT attempts FROM login_limits WHERE id=?', [$key]);
        if ($limit === null || (int) $limit['attempts'] > 5) {
            throw new \yii\web\TooManyRequestsHttpException();
        }
        $row = $store->one('SELECT id,password_hash FROM users WHERE email=?', [strtolower($email)]);
        if ($row === null || !password_verify($password, $row['password_hash'])) {
            Runtime::web()->response->statusCode = 401;
            return Ui::text('login_failed');
        }
        $user = User::findIdentity((int) $row['id']);
        if ($user === null || !Runtime::web()->user->login($user)) {
            throw new \yii\web\ForbiddenHttpException();
        }
        return $this->redirect(['/site/orders'], 303);
    }
    public function actionLogout(): \yii\web\Response
    {
        Runtime::web()->user->logout();
        return $this->redirect(['/site/login'], 303);
    }
    public function actionOrders(): string
    {
        $orders = (new Store(Runtime::db()))->all('SELECT * FROM orders WHERE user_id=? ORDER BY id', [(int) Runtime::web()->user->id]);
        Runtime::web()->response->headers->set('Cache-Control', 'no-store');
        return $this->render('orders', ['orders' => $orders]);
    }
}
