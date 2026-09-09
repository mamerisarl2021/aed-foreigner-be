<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PublicConfigurationTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const PAYLOAD_KEYS = [
        'TX_BASE_URL',
        'TX_CLIENT_ID',
        'TX_SCOPE',
        'TX_REDIRECT_PATH',
        'TX_ADMINS_LOGGED_AS',
        'TX_CLIENTS_LOGGED_AS',
        'KC_STAFF_ISSUER',
        'KC_STAFF_CLIENT_ID',
        'KC_STAFF_SCOPE',
    ];

    #[Test]
    public function configuration_is_public_and_does_not_expose_secrets(): void
    {
        config([
            'trustedx.base_url' => 'https://tx-pki.gouv.bj',
            'trustedx.client_id' => 'appdde',
            'trustedx.client_secret' => 'must-not-leak',
            'trustedx.scope' => 'urn:gob:basic:profile',
            'trustedx.redirect_path' => '/etranger/connexion/callback',
            'trustedx.admins_logged_as' => 'admin-as',
            'trustedx.clients_logged_as' => 'main-as',
            'keycloak.staff.issuer' => 'https://auth.gouv.bj/realms/pki-portal',
            'keycloak.staff.client_id' => 'backoffice-stranger',
            'keycloak.staff.scope' => 'openid profile email',
        ]);

        $response = $this->getJson($this->api('/configuration'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', null)
            ->assertJsonMissing(['TX_CLIENT_SECRET' => 'must-not-leak']);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertSame(self::PAYLOAD_KEYS, array_keys($data));
        $this->assertArrayNotHasKey('TX_CLIENT_SECRET', $data);
        $this->assertSame('https://tx-pki.gouv.bj', $data['TX_BASE_URL']);
        $this->assertSame('appdde', $data['TX_CLIENT_ID']);
        $this->assertSame('urn:gob:basic:profile', $data['TX_SCOPE']);
        $this->assertSame('/etranger/connexion/callback', $data['TX_REDIRECT_PATH']);
        $this->assertSame('admin-as', $data['TX_ADMINS_LOGGED_AS']);
        $this->assertSame('main-as', $data['TX_CLIENTS_LOGGED_AS']);
        $this->assertSame('https://auth.gouv.bj/realms/pki-portal', $data['KC_STAFF_ISSUER']);
        $this->assertSame('backoffice-stranger', $data['KC_STAFF_CLIENT_ID']);
        $this->assertSame('openid profile email', $data['KC_STAFF_SCOPE']);
    }

    #[Test]
    public function configuration_does_not_require_gateway_jwt_when_keycloak_enabled(): void
    {
        config(['consul.keycloak.enabled' => true]);

        $this->getJson($this->api('/configuration'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function configuration_prefixes_https_when_tx_base_url_has_no_scheme(): void
    {
        config([
            'trustedx.base_url' => 'tx-pki.gouv.bj',
            'trustedx.redirect_path' => '',
            'trustedx.redirect_url' => 'http://localhost:4200/etranger/connexion/callback',
        ]);

        $this->getJson($this->api('/configuration'))
            ->assertOk()
            ->assertJsonPath('data.TX_BASE_URL', 'https://tx-pki.gouv.bj')
            ->assertJsonPath('data.TX_REDIRECT_PATH', '/etranger/connexion/callback');
    }
}
