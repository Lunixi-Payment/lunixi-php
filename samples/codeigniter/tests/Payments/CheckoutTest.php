<?php
declare(strict_types=1);
use App\Payments\{Checkout,NativeLedger,Schema,Settings};
use Config\Services;
use Lunixi\Sdk\Auth\Ed25519Signer;
use CodeIgniter\Test\{CIUnitTestCase,FeatureTestTrait};

final class CheckoutTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    private $nativeDb;
    private Checkout $checkout;
    private GatewayFixture $http;
    protected function setUp(): void
    {
        parent::setUp();
        $this->nativeDb=db_connect();
        foreach (['payment_outbox','lunixi_events','lunixi_attempts','orders','users'] as $table) { $this->nativeDb->query('DROP TABLE IF EXISTS '.$table); }
        Schema::install($this->nativeDb);
        for ($n=1;$n<=2;++$n) {
            $this->nativeDb->query('INSERT INTO users(id,email,password_hash) VALUES (?,?,?)',[$n,'user'.$n.'@example.test',password_hash('test-only-long-password',PASSWORD_DEFAULT)]);
            $this->nativeDb->query('INSERT INTO orders(id,user_id,merchant_id,environment,amount,currency,mode,preauth) VALUES (?,?,?,?,?,?,?,?)',[$n,$n,'merchant-a','TEST',1990,'TRY','hosted',0]);
        }
        $this->http=new GatewayFixture();
        $settings=new Settings('merchant-a','TEST','fixture-key',Ed25519Signer::generateKeyPair()['privateKey'],'fixture-webhook-secret',str_repeat('K',32),'https://billing.example');
        $this->checkout=new Checkout($this->nativeDb,$settings,$this->http);
        Services::injectMock('lunixi',$this->checkout);
    }
    private function signed(string $eventId='evt-native'): array
    {
        $body=json_encode(['id'=>$eventId,'type'=>'payment.captured','environment'=>'TEST','data'=>['intentId'=>'pi-1','orderId'=>'ci:order:1']],JSON_THROW_ON_ERROR);
        $ts=gmdate('c');
        return [$body,['x-lunixi-event-id'=>$eventId,'x-lunixi-signature-timestamp'=>$ts,'x-lunixi-signature'=>'v2='.hash_hmac('sha256',$eventId.'.'.$ts.'.'.$body,'fixture-webhook-secret')]];
    }
    public function testNativeOwnerPostStartsHostedCheckoutWithEncryptedToken(): void
    {
        $hash=csrf_hash();$token=csrf_token();
        $result=$this->withSession(['user_id'=>1,$token=>$hash])->post('/payments/1',[$token=>$hash]);
        $result->assertStatus(303);
        self::assertStringStartsWith('https://pay.lunixi.com/payment/', $result->response()->getHeaderLine('Location'));
        $record=$this->nativeDb->query('SELECT payload FROM lunixi_attempts')->getRowArray()['payload'];
        self::assertStringNotContainsString('fixture-browser-token',$record);
        self::assertSame(1990,$this->http->payments['pi-1']['data']['amount']);
    }
    public function testUnauthenticatedAndWrongOwnerNeverRequestPayment(): void
    {
        $this->get('/orders')->assertStatus(401);
        $hash=csrf_hash();$token=csrf_token();
        $this->withSession(['user_id'=>2,$token=>$hash])->post('/payments/1',[$token=>$hash])->assertStatus(404);
        self::assertSame([],$this->http->calls);
    }
    public function testNativeCsrfRejectsMissingToken(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->withSession(['user_id'=>1])->post('/payments/1');
    }
    public function testAuthenticatedReturnCannotMarkPaidFromBrowserFields(): void
    {
        $this->withSession(['user_id'=>1])->get('/payments/1/return?status=CAPTURED&amount=1990&paymentId=forged')->assertStatus(200);
        self::assertSame('pending',$this->checkout->order(1)['state']);self::assertSame([],$this->http->calls);
    }
    public function testSignedRawWebhookAndAuthenticatedGetPayOnceOnNativeRoute(): void
    {
        $this->checkout->start(1,1);$this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();
        $this->withHeaders($headers)->withBody($body)->post('/webhooks/lunixi')->assertStatus(200);
        $this->withHeaders($headers)->withBody($body)->post('/webhooks/lunixi')->assertStatus(200);
        self::assertSame('paid',$this->checkout->order(1)['state']);
        self::assertSame(1,$this->nativeDb->table('lunixi_events')->countAllResults());
        self::assertSame(1,$this->nativeDb->table('payment_outbox')->countAllResults());
    }
    public function testNativeDatabaseFailureRollsBackInboxOrderAndOutbox(): void
    {
        $this->checkout->start(1,1);$this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();
        $this->nativeDb->query("CREATE TRIGGER fail_native BEFORE UPDATE ON orders BEGIN SELECT RAISE(ABORT,'synthetic native failure'); END");
        try {$this->checkout->webhook($body,$headers);self::fail('Expected native rollback.');}catch(Throwable $expected){}
        self::assertSame('pending',$this->checkout->order(1)['state']);
        self::assertSame(0,$this->nativeDb->table('lunixi_events')->countAllResults());
        self::assertSame(0,$this->nativeDb->table('payment_outbox')->countAllResults());
        $this->nativeDb->query('DROP TRIGGER fail_native');
        $this->checkout->webhook($body,$headers);self::assertSame('paid',$this->checkout->order(1)['state']);
    }
    public function testLostResponseRetriesSameKeyAndRawBytes(): void
    {
        $this->http->loseCreate=true;
        try{$this->checkout->start(1,1);}catch(RuntimeException $e){}
        $this->checkout->start(1,1);
        $calls=array_values(array_filter($this->http->calls,fn($c)=>str_ends_with($c['url'],'/payments/intents')));
        self::assertCount(2,$calls);self::assertSame($calls[0]['body'],$calls[1]['body']);self::assertSame($calls[0]['headers']['Idempotency-Key'],$calls[1]['headers']['Idempotency-Key']);
    }
    public function testLostResponseRecoveryRequiresSignedOpaqueAttempt(): void
    {
        $this->http->loseCreate=true;try{$this->checkout->start(1,1);}catch(RuntimeException $e){}
        $this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();$this->checkout->webhook($body,$headers);
        self::assertSame('paid',$this->checkout->order(1)['state']);self::assertCount(1,$this->http->createKeys);
    }
    public function testTamperedAndWrongEnvironmentWebhookNeverConsumesEvent(): void
    {
        $this->checkout->start(1,1);$this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();
        try{$this->checkout->webhook($body.' ',$headers);self::fail('Tampered bytes accepted.');}catch(Throwable $expected){}
        $body=str_replace('TEST','LIVE',$body);$ts=$headers['x-lunixi-signature-timestamp'];$headers['x-lunixi-signature']='v2='.hash_hmac('sha256','evt-native.'.$ts.'.'.$body,'fixture-webhook-secret');
        try{$this->checkout->webhook($body,$headers);self::fail('Wrong scope accepted.');}catch(Throwable $expected){}
        self::assertSame(0,$this->nativeDb->table('lunixi_events')->countAllResults());self::assertSame('pending',$this->checkout->order(1)['state']);
    }
    public function testChangedNativeOrderRollsBackVerifiedWebhook(): void
    {
        $this->checkout->start(1,1);$this->http->settle('CAPTURED',1990);$this->nativeDb->query('UPDATE orders SET amount=2990 WHERE id=1');[$body,$headers]=$this->signed();
        try{$this->checkout->webhook($body,$headers);self::fail('Changed native total accepted.');}catch(RuntimeException $expected){}
        self::assertSame(0,$this->nativeDb->table('lunixi_events')->countAllResults());self::assertSame('pending',$this->checkout->order(1)['state']);
    }
    public function testNativeOuterTransactionIsRefused(): void
    {
        $ledger=new NativeLedger($this->nativeDb);$this->nativeDb->transBegin();
        try{$ledger->transaction('ci:order:1',fn()=>null);self::fail('Outer transaction accepted.');}catch(RuntimeException $expected){}finally{$this->nativeDb->transRollback();}
        self::assertSame(0,$this->nativeDb->transDepth);
    }
    public function testPartialCaptureStaysHeldAndInstallmentFeeIsSeparateBalance(): void
    {
        $this->checkout->start(1,1);$this->http->settle('PARTIALLY_CAPTURED',1000);$this->checkout->reconcile(1);
        self::assertSame('partially_captured',$this->checkout->order(1)['state']);self::assertSame(0,$this->nativeDb->table('payment_outbox')->countAllResults());
        $this->http->payments['pi-1']['data']['amount']=2100;$this->http->settle('CAPTURED',2100);$this->checkout->reconcile(1);
        $order=$this->checkout->order(1);self::assertSame(1990,(int)$order['amount']);self::assertSame(2100,(int)$order['charged']);
    }
    public function testNativeLoginVerifiesHashAndRegeneratesSession(): void
    {
        $hash=csrf_hash();$token=csrf_token();
        $result=$this->withSession([$token=>$hash])->post('/login',[$token=>$hash,'email'=>'user1@example.test','password'=>'test-only-long-password']);
        $result->assertStatus(303);$result->assertSessionHas('user_id',1);
    }
    public function testFractionalMinorUnitsAndCancelledOrderNeverReachGateway(): void
    {
        $this->nativeDb->query('UPDATE orders SET amount=19.5 WHERE id=1');
        try{$this->checkout->start(1,1);self::fail('Fractional minor value accepted.');}catch(InvalidArgumentException $expected){}
        $this->nativeDb->query('UPDATE orders SET amount=1990,state=? WHERE id=1',['cancelled']);
        try{$this->checkout->start(1,1);self::fail('Cancelled order accepted.');}catch(DomainException $expected){}
        self::assertSame([],$this->http->calls);
    }

    public function testUnknownCreateOlderThanGatewayRetentionNeverPostsAgainButCanReconcile(): void
    {
        $this->http->loseCreate=true;try{$this->checkout->start(1,1);}catch(RuntimeException $expected){}
        $record=json_decode($this->nativeDb->query('SELECT payload FROM lunixi_attempts')->getRowArray()['payload'],true);$record['createdAt']=time()-86401;
        $this->nativeDb->query('UPDATE lunixi_attempts SET payload=?',[json_encode($record,JSON_THROW_ON_ERROR)]);
        try{$this->checkout->start(1,1);self::fail('Expired uncertain create was replayed.');}catch(RuntimeException $expected){}
        self::assertCount(1,array_filter($this->http->calls,fn($c)=>str_ends_with($c['url'],'/payments/intents')));
        $this->http->settle('CAPTURED',1990);[$body,$headers]=$this->signed();$this->checkout->webhook($body,$headers);self::assertSame('paid',$this->checkout->order(1)['state']);
    }
    public function testFailedOutcomeRemainsReviewWithoutNewIntent(): void
    {
        $this->checkout->start(1,1);$this->http->settle('FAILED',0);$this->checkout->reconcile(1);self::assertSame('review',$this->checkout->order(1)['state']);
        try{$this->checkout->start(1,1);self::fail('Unknown failed outcome restarted.');}catch(DomainException $expected){}
        self::assertCount(1,$this->http->createKeys);
    }

    public function testNativeEmbeddedFormSerializesObjectExactlyOnce(): void
    {
        $this->nativeDb->query('UPDATE orders SET mode=? WHERE id=1',['embedded']);
        $hash=csrf_hash();$token=csrf_token();
        $result=$this->withSession(['user_id'=>1,$token=>$hash])->post('/payments/1',[$token=>$hash]);$result->assertStatus(200);
        self::assertSame(1,preg_match('~<script id="lunixi-config" type="application/json">(.*?)</script>~s',$result->response()->getBody(),$matches));
        $config=json_decode($matches[1],true,512,JSON_THROW_ON_ERROR);self::assertIsArray($config);self::assertSame('payment',$config['type']);self::assertSame('lunixi-checkout-form',$config['target']);self::assertSame('fixture-browser-token',$config['options']['token']);
    }

    public function testAnonymousCallbackPostIsOnlyBounded303ToOwnedGet(): void
    {
        $result=$this->withSession([])->withBody('status=CAPTURED&paymentId=forged&_signature=not-webhook-proof')->post('/payments/1/return');
        $result->assertStatus(303);self::assertSame('/payments/1/return',$result->response()->getHeaderLine('Location'));self::assertSame('',$result->response()->getBody());
        $this->withSession([])->get('/payments/1/return')->assertStatus(401);
        self::assertSame('pending',$this->checkout->order(1)['state']);self::assertSame([],$this->http->calls);self::assertSame(0,$this->nativeDb->table('lunixi_attempts')->countAllResults());
        $this->withSession([])->withBody('status=CAPTURED')->post('/payments/999/return')->assertStatus(303);
        $this->withSession([])->withBody(str_repeat('x',65537))->post('/payments/1/return')->assertStatus(413);
    }

}
