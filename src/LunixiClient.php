<?php

declare(strict_types=1);

namespace Lunixi\Sdk;

use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Auth\TokenStoreInterface;
use Lunixi\Sdk\Http\CurlHttpClient;
use Lunixi\Sdk\Http\HttpClientInterface;
use Lunixi\Sdk\Fraud\FraudClient;
use Lunixi\Sdk\Identity\IdentityClient;
use Lunixi\Sdk\Kyc\KycClient;
use Lunixi\Sdk\Marketplace\MarketplaceClient;
use Lunixi\Sdk\Payment\PaymentClient;
use Lunixi\Sdk\Payment\PaymentLinkClient;
use Lunixi\Sdk\Sanction\SanctionClient;
use Lunixi\Sdk\Subscription\SubscriptionClient;
use Lunixi\Sdk\Wallet\WalletClient;
use Lunixi\Sdk\Webhook\WebhookVerifier;

/**
 * Entry point / facade. Wires the signer, token manager, API client and webhook
 * verifier from a Configuration. Integrations inject their own HTTP client
 * (e.g. a WordPress `wp_remote_request` adapter) and a durable token store.
 *
 *   $lunixi = LunixiClient::create([
 *       'baseUrl'    => 'https://api-gateway.lunixi.com',
 *       'keyId'      => $kid,
 *       'privateKey' => $pemPrivateKey,
 *       'environment'=> 'LIVE',
 *   ]);
 *   $event = $lunixi->webhooks()->verify($body, $headers, $secret);
 *
 * Service clients (PaymentClient, …) hang off this facade as they land.
 */
final class LunixiClient
{
    private Configuration $config;
    private Ed25519Signer $signer;
    private TokenManager $tokens;
    private ApiClient $api;
    private PaymentClient $payments;
    private PaymentLinkClient $paymentLinks;
    private SubscriptionClient $subscriptions;
    private FraudClient $fraud;
    private KycClient $kyc;
    private SanctionClient $sanction;
    private MarketplaceClient $marketplace;
    private IdentityClient $identity;
    private WalletClient $wallet;
    private WebhookVerifier $webhooks;

    public function __construct(
        Configuration $config,
        ?HttpClientInterface $http = null,
        ?TokenStoreInterface $tokenStore = null
    ) {
        $http = $http ?? new CurlHttpClient();

        $this->config = $config;
        $this->signer = new Ed25519Signer($config->privateKey());
        $this->tokens = new TokenManager($config, $this->signer, $http, $tokenStore);
        $this->api = new ApiClient($config, $this->signer, $this->tokens, $http);
        $this->payments = new PaymentClient($this->api);
        // The transport also carries the link image PUT to storage, outside ApiClient (no credentials).
        $this->paymentLinks = new PaymentLinkClient($this->api, $http, $config->timeout());
        $this->subscriptions = new SubscriptionClient($this->api);
        $this->fraud = new FraudClient($this->api);
        $this->kyc = new KycClient($this->api);
        $this->sanction = new SanctionClient($this->api);
        $this->marketplace = new MarketplaceClient($this->api);
        $this->identity = new IdentityClient($this->api);
        $this->wallet = new WalletClient($this->api);
        $this->webhooks = new WebhookVerifier();
    }

    /**
     * @param array<string,mixed> $options Configuration options (see Configuration).
     */
    public static function create(
        array $options,
        ?HttpClientInterface $http = null,
        ?TokenStoreInterface $tokenStore = null
    ): self {
        return new self(new Configuration($options), $http, $tokenStore);
    }

    public function config(): Configuration
    {
        return $this->config;
    }

    public function signer(): Ed25519Signer
    {
        return $this->signer;
    }

    public function tokens(): TokenManager
    {
        return $this->tokens;
    }

    public function api(): ApiClient
    {
        return $this->api;
    }

    public function payments(): PaymentClient
    {
        return $this->payments;
    }

    public function paymentLinks(): PaymentLinkClient
    {
        return $this->paymentLinks;
    }

    public function subscriptions(): SubscriptionClient
    {
        return $this->subscriptions;
    }

    public function fraud(): FraudClient
    {
        return $this->fraud;
    }

    public function kyc(): KycClient
    {
        return $this->kyc;
    }

    public function sanction(): SanctionClient
    {
        return $this->sanction;
    }

    public function marketplace(): MarketplaceClient
    {
        return $this->marketplace;
    }

    public function identity(): IdentityClient
    {
        return $this->identity;
    }

    /**
     * Closed-loop wallet (`/api/v1/wallet/*`): end users, transfers, top-ups,
     * payments, QR codes, bulk payouts. Sub-clients hang off it as properties,
     * e.g. `$lunixi->wallet()->transfers->initiateW2W(...)`.
     */
    public function wallet(): WalletClient
    {
        return $this->wallet;
    }

    public function webhooks(): WebhookVerifier
    {
        return $this->webhooks;
    }
}
