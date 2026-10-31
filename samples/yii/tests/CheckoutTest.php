<?php
declare(strict_types=1);
use app\components\{Checkout,NativeLedger,Schema,Settings,Store};
use app\models\User;
use Lunixi\Sdk\Auth\Ed25519Signer;
use PHPUnit\Framework\TestCase;
use yii\web\{Application,Request,HttpException};

final class CheckoutTest extends TestCase
{
    private Application $app;
    private Store $db;
    private Checkout $checkout;
    private GatewayFixture $http;
    protected function setUp(): void
    {
        putenv('YII_COOKIE_KEY='.str_repeat('C',32));
        $_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/web/index.php';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['HTTP_HOST']='billing.example';$_SERVER['HTTPS']='on';$_SERVER['REQUEST_URI']='/';$_SERVER['REQUEST_METHOD']='GET';
        $config=require dirname(__DIR__).'/config/web.php';
        $config['components']['db']['dsn']='sqlite::memory:';
        $config['components']['session']['useCookies']=false;
        $this->app=new Application($config);$this->app->errorHandler->unregister();
        $this->app->session->open();$this->app->session->removeAll();
        $this->db=new Store($this->app->db);Schema::install($this->db);
        for($n=1;$n<=2;++$n){$this->db->execute('INSERT INTO users(id,email,password_hash,auth_key) VALUES (?,?,?,?)',[$n,'user'.$n.'@example.test',password_hash('test-only-long-password',PASSWORD_DEFAULT),bin2hex(random_bytes(32))]);$this->db->execute('INSERT INTO orders(id,user_id,merchant_id,environment,amount,currency,mode,preauth) VALUES (?,?,?,?,?,?,?,?)',[$n,$n,'merchant-a','TEST',1990,'TRY','hosted',0]);}
        $this->http=new GatewayFixture();
        $settings=new Settings('merchant-a','TEST','fixture-key',Ed25519Signer::generateKeyPair()['privateKey'],'fixture-webhook-secret',str_repeat('K',32),'https://billing.example');
        $this->checkout=new Checkout($this->db,$settings,$this->http);$this->app->set('checkout',$this->checkout);
    }
    protected function tearDown():void
    {
        $this->app->session->removeAll();$this->app->session->close();$this->app->db->close();Yii::$app=null;
        parent::tearDown();
    }
    private function request(string $method,string $path,array $params=[],?int $owner=null,string $raw='',array $headers=[]): \yii\web\Response
    {
        $_SERVER['REQUEST_METHOD']=$method;$_SERVER['REQUEST_URI']='/'.$path;
        $request=new Request(['cookieValidationKey'=>str_repeat('C',32),'enableCsrfCookie'=>false,'csrfParam'=>'_csrf','scriptUrl'=>'/index.php','baseUrl'=>'','hostInfo'=>'https://billing.example']);
        $request->setPathInfo(explode('?',$path)[0]);$request->setBodyParams($params);$request->setRawBody($raw);
        foreach($headers as $name=>$value){$request->headers->set($name,$value);}
        $this->app->set('request',$request);$this->app->set('response',new \yii\web\Response());
        $this->app->user->setIdentity($owner===null?null:User::findIdentity($owner));
        try{return $this->app->handleRequest($request);}catch(HttpException $error){$this->app->response->statusCode=$error->statusCode;return $this->app->response;}
    }
    private function csrf():array{return ['_csrf'=>$this->app->request->getCsrfToken()];}
    private function signed(string $eventId='evt-native'):array
    {
        $body=json_encode(['id'=>$eventId,'type'=>'payment.captured','environment'=>'TEST','data'=>['intentId'=>'pi-1','orderId'=>'yii:order:1']],JSON_THROW_ON_ERROR);$ts=gmdate('c');
        return [$body,['x-lunixi-event-id'=>$eventId,'x-lunixi-signature-timestamp'=>$ts,'x-lunixi-signature'=>'v2='.hash_hmac('sha256',$eventId.'.'.$ts.'.'.$body,'fixture-webhook-secret')]];
    }
    public function testNativeOwnerCsrfPostStartsHostedCheckoutWithEncryptedToken():void
    {
        $response=$this->request('POST','payments/1',$this->csrf(),1);
        self::assertSame(303,$response->statusCode);self::assertSame('https://pay.lunixi.com/payment/backend-page-pi-1',$response->headers->get('Location'));
        self::assertStringNotContainsString('fixture-browser-token',$this->db->one('SELECT payload FROM lunixi_attempts')['payload']);self::assertSame(1990,$this->http->payments['pi-1']['data']['amount']);
    }
    public function testNativeOwnerAndVerbGatesBeforePaymentNetwork():void
    {
        self::assertSame(404,$this->request('POST','payments/1',$this->csrf(),2)->statusCode);
        self::assertSame(405,$this->request('GET','payments/1',[],1)->statusCode);
        self::assertSame([],$this->http->calls);
    }
    public function testNativeCsrfCannotBeDisabledForBrowserCheckout():void
    {
        self::assertSame(400,$this->request('POST','payments/1',[],1)->statusCode);self::assertSame([],$this->http->calls);
    }
    public function testBrowserReturnCannotWritePaidState():void
    {
        $_GET=['status'=>'CAPTURED','amount'=>1990,'paymentId'=>'forged'];
        self::assertSame(200,$this->request('GET','payments/1/return',[],1)->statusCode);
        self::assertSame('pending',$this->checkout->order(1)['state']);self::assertSame([],$this->http->calls);
    }
    public function testNativeRawWebhookRouteCommitsPaymentAndInboxOnce():void
    {
        $this->checkout->start(1,1);$this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();
        self::assertSame(200,$this->request('POST','webhooks/lunixi',[],null,$body,$headers)->statusCode);
        self::assertSame(200,$this->request('POST','webhooks/lunixi',[],null,$body,$headers)->statusCode);
        self::assertSame('paid',$this->checkout->order(1)['state']);self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) AS n FROM lunixi_events')['n']);self::assertSame(1,(int)$this->db->one('SELECT COUNT(*) AS n FROM payment_outbox')['n']);
    }
    public function testNativeSqlFailureRollsBackOrderInboxAndOutbox():void
    {
        $this->checkout->start(1,1);$this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();
        $this->db->connection->pdo->exec("CREATE TRIGGER fail_native BEFORE UPDATE ON orders BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
        try{$this->checkout->webhook($body,$headers);self::fail('Expected native rollback.');}catch(\yii\db\Exception $expected){}
        self::assertSame('pending',$this->checkout->order(1)['state']);self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS n FROM lunixi_events')['n']);self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS n FROM payment_outbox')['n']);
        $this->db->execute('DROP TRIGGER fail_native');$this->checkout->webhook($body,$headers);self::assertSame('paid',$this->checkout->order(1)['state']);
    }
    public function testLostCreateResponseRetainsExactRequestAndKey():void
    {
        $this->http->loseCreate=true;try{$this->checkout->start(1,1);}catch(RuntimeException $expected){}$this->checkout->start(1,1);
        $calls=array_values(array_filter($this->http->calls,fn($c)=>str_ends_with($c['url'],'/payments/intents')));
        self::assertCount(2,$calls);self::assertSame($calls[0]['body'],$calls[1]['body']);self::assertSame($calls[0]['headers']['Idempotency-Key'],$calls[1]['headers']['Idempotency-Key']);
    }
    public function testSignedOpaqueRecoveryAfterLostCreateResponse():void
    {
        $this->http->loseCreate=true;try{$this->checkout->start(1,1);}catch(RuntimeException $expected){}$this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();$this->checkout->webhook($body,$headers);
        self::assertSame('paid',$this->checkout->order(1)['state']);self::assertCount(1,$this->http->createKeys);
    }
    public function testTamperAndWrongScopeCannotConsumeEvent():void
    {
        $this->checkout->start(1,1);$this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();
        try{$this->checkout->webhook($body.' ',$headers);self::fail('Tamper accepted.');}catch(\Lunixi\Sdk\Exception\WebhookVerificationException $expected){}
        $body=str_replace('TEST','LIVE',$body);$ts=$headers['x-lunixi-signature-timestamp'];$headers['x-lunixi-signature']='v2='.hash_hmac('sha256','evt-native.'.$ts.'.'.$body,'fixture-webhook-secret');
        try{$this->checkout->webhook($body,$headers);self::fail('Wrong environment accepted.');}catch(DomainException $expected){}
        self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS n FROM lunixi_events')['n']);
    }
    public function testChangedNativeOrderCannotBePaid():void
    {
        $this->checkout->start(1,1);$this->http->settle('CAPTURED',1990);$this->db->execute('UPDATE orders SET amount=2990 WHERE id=1');[$body,$headers]=$this->signed();
        try{$this->checkout->webhook($body,$headers);self::fail('Changed native amount accepted.');}catch(RuntimeException $expected){}
        self::assertSame('pending',$this->checkout->order(1)['state']);self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS n FROM lunixi_events')['n']);
    }
    public function testYiiOuterTransactionIsRefused():void
    {
        $ledger=new NativeLedger($this->db);$tx=$this->db->connection->beginTransaction();
        try{$ledger->transaction('yii:order:1',fn()=>null);self::fail('Nested transaction accepted.');}catch(RuntimeException $expected){}finally{$tx->rollBack();}
        self::assertNull($this->db->connection->getTransaction());
    }
    public function testPartialCaptureDoesNotCreatePaidOutboxAndFeeKeepsBaseAmount():void
    {
        $this->checkout->start(1,1);$this->http->settle('PARTIALLY_CAPTURED',1000);$this->checkout->reconcile(1);self::assertSame('partially_captured',$this->checkout->order(1)['state']);self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS n FROM payment_outbox')['n']);
        $this->http->payments['pi-1']['data']['amount']=2100;$this->http->settle('CAPTURED',2100);$this->checkout->reconcile(1);$order=$this->checkout->order(1);self::assertSame(1990,(int)$order['amount']);self::assertSame(2100,(int)$order['charged']);
    }
    public function testNativeLoginUsesStoredPasswordHashAndUserIdentity():void
    {
        $response=$this->request('POST','login',$this->csrf()+['email'=>'user1@example.test','password'=>'test-only-long-password']);
        self::assertSame(303,$response->statusCode);self::assertSame(1,(int)$this->app->user->id);
    }
    public function testFractionalMinorUnitsAndCancelledOrderFailBeforeGateway():void
    {
        $this->db->execute('UPDATE orders SET amount=19.5 WHERE id=1');
        try{$this->checkout->start(1,1);self::fail('Fractional minor value accepted.');}catch(InvalidArgumentException $expected){}
        $this->db->execute('UPDATE orders SET amount=1990,state=? WHERE id=1',['cancelled']);
        try{$this->checkout->start(1,1);self::fail('Cancelled order accepted.');}catch(DomainException $expected){}
        self::assertSame([],$this->http->calls);
    }

    public function testUnknownCreatePastRetentionNeverRepostsButSignedRecoveryStillWorks():void
    {
        $this->http->loseCreate=true;try{$this->checkout->start(1,1);}catch(RuntimeException $expected){}
        $record=json_decode($this->db->one('SELECT payload FROM lunixi_attempts')['payload'],true);$record['createdAt']=time()-86401;
        $this->db->execute('UPDATE lunixi_attempts SET payload=?',[json_encode($record,JSON_THROW_ON_ERROR)]);
        try{$this->checkout->start(1,1);self::fail('Expired uncertain request replayed.');}catch(RuntimeException $expected){}
        self::assertCount(1,array_filter($this->http->calls,fn($c)=>str_ends_with($c['url'],'/payments/intents')));
        $this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();$this->checkout->webhook($body,$headers);self::assertSame('paid',$this->checkout->order(1)['state']);
    }
    public function testFailedOutcomeStaysReviewAndNeverGeneratesNewIntent():void
    {
        $this->checkout->start(1,1);$this->http->settle('FAILED',0);$this->checkout->reconcile(1);self::assertSame('review',$this->checkout->order(1)['state']);
        try{$this->checkout->start(1,1);self::fail('Unknown failed outcome restarted.');}catch(DomainException $expected){}
        self::assertCount(1,$this->http->createKeys);
    }

    public function testNativeEmbeddedFormProducesObjectAfterOneJsonParse():void
    {
        $this->db->execute('UPDATE orders SET mode=? WHERE id=1',['embedded']);$response=$this->request('POST','payments/1',$this->csrf(),1);self::assertSame(200,$response->statusCode);
        self::assertSame(1,preg_match('~<script id="lunixi-config" type="application/json">(.*?)</script>~s',$response->data,$matches));
        $config=json_decode($matches[1],true,512,JSON_THROW_ON_ERROR);self::assertIsArray($config);self::assertSame('payment',$config['type']);self::assertSame('lunixi-checkout-form',$config['target']);self::assertSame('fixture-browser-token',$config['options']['token']);
    }

    public function testAnonymousFormCallbackOnlyRedirectsToAuthenticatedOwnedGet():void
    {
        $response=$this->request('POST','payments/1/return',[],null,'status=CAPTURED&paymentId=forged&_signature=not-webhook-proof');
        self::assertSame(303,$response->statusCode);self::assertSame('/payments/1/return',$response->headers->get('Location'));self::assertEmpty($response->data);
        $anonymous=$this->request('GET','payments/1/return');self::assertSame(302,$anonymous->statusCode);
        self::assertSame('pending',$this->checkout->order(1)['state']);self::assertSame([],$this->http->calls);self::assertSame(0,(int)$this->db->one('SELECT COUNT(*) AS n FROM lunixi_attempts')['n']);
        self::assertSame(303,$this->request('POST','payments/999/return')->statusCode);
        self::assertSame(413,$this->request('POST','payments/1/return',[],null,str_repeat('x',65537))->statusCode);
    }

}
