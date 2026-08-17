<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Subscription customer-portal bootstrap. The merchant backend mints an
 * origin-bound SDK session that the browser uses to initialise the Lunixi portal
 * SDK — which then runs the entire subscription screen for the end customer.
 *
 * Bearer auth (merchant token); `origin` must be allow-listed for the portal.
 */
final class PortalClient
{
    private const BASE = '/api/v1/subscriptions/portal';
    private const PANEL_BASE = '/api/panel/v1/subscriptions/portal';

    public const MODE_FULL = 'FULL';
    public const MODE_HEADLESS = 'HEADLESS';
    public const MODE_HYBRID = 'HYBRID';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Mints an SDK session for a browser embed.
     *
     * @param string      $customerId The Lunixi customer the portal will manage.
     * @param string      $origin     The browser origin that will host the SDK (must be allow-listed).
     * @param string|null $sdkMode    FULL | HEADLESS | HYBRID (optional).
     * @param string|null $portalLinkId Optional pre-created portal link to bind.
     */
    public function createSdkSession(string $customerId, string $origin, ?string $sdkMode = null, ?string $portalLinkId = null): PortalSession
    {
        $body = ['customerId' => $customerId, 'origin' => $origin];
        if ($sdkMode !== null && $sdkMode !== '') {
            $body['sdkMode'] = $sdkMode;
        }
        if ($portalLinkId !== null && $portalLinkId !== '') {
            $body['portalLinkId'] = $portalLinkId;
        }

        $response = $this->api->request('POST', self::BASE . '/sdk-session', $body);

        return new PortalSession($response);
    }

    /**
     * Creates a hosted portal link (redirect mode). The returned token powers a
     * hosted Lunixi portal URL the merchant redirects the customer to (the
     * customer then verifies via OTP). The alternative to the embedded session.
     *
     * @param array<string,mixed> $options allowedPlanIds, defaultPlanId, permissions,
     *                                      sessionDurationMinutes, expiresAt, requireEmailVerification, …
     */
    public function createPortalLink(string $customerId, array $options = []): PortalLink
    {
        $body = array_merge(['customerId' => $customerId], $options);
        $response = $this->api->request('POST', self::PANEL_BASE . '/links', $body);

        return new PortalLink(Helpers::dataOf($response));
    }

    /**
     * Lists portal links.
     *
     * @param array{customerId?:string, status?:string, limit?:int} $filters
     * @return array<string,mixed>
     */
    public function listLinks(array $filters = []): array
    {
        return $this->api->request('GET', self::PANEL_BASE . '/links', null, ['query' => Helpers::query($filters)]);
    }

    /** @return array<string,mixed> */
    public function getLink(string $linkId): array
    {
        return $this->api->request('GET', self::PANEL_BASE . '/links/' . rawurlencode($linkId));
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function updateLink(string $linkId, array $changes): array
    {
        return $this->api->request('PATCH', self::PANEL_BASE . '/links/' . rawurlencode($linkId), $changes);
    }

    /** @return array<string,mixed> */
    public function revokeLink(string $linkId, ?string $reason = null): array
    {
        $body = ($reason !== null && $reason !== '') ? ['reason' => $reason] : null;

        return $this->api->request('DELETE', self::PANEL_BASE . '/links/' . rawurlencode($linkId), $body);
    }

    /** @return array<string,mixed> */
    public function linkActivity(string $linkId): array
    {
        return $this->api->request('GET', self::PANEL_BASE . '/links/' . rawurlencode($linkId) . '/activity');
    }

    /**
     * Emails a portal invite to a customer.
     *
     * @param array<string,mixed> $invite customerId, email?, allowedPlanIds?…
     * @return array<string,mixed>
     */
    public function sendInvite(array $invite): array
    {
        return $this->api->request('POST', self::PANEL_BASE . '/invite', $invite);
    }

    /** @return array<string,mixed> Merchant portal settings (branding/origins). */
    public function getSettings(): array
    {
        return $this->api->request('GET', self::PANEL_BASE . '/settings');
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public function updateSettings(array $settings): array
    {
        return $this->api->request('PUT', self::PANEL_BASE . '/settings', $settings);
    }
}
