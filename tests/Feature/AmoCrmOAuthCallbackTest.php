<?php

namespace Tests\Feature;

use App\Models\AmoCrmToken;
use App\Services\AmoCrm\AmoCrmClient;
use App\Services\AmoCrm\AmoCrmTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmoCrmOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exchanges_code_and_stores_amocrm_tokens(): void
    {
        config([
            'services.amocrm.client_id' => 'client-id',
            'services.amocrm.client_secret' => 'client-secret',
            'services.amocrm.redirect_uri' => 'https://app.test/amocrm/oauth/callback',
        ]);

        $this->mock(AmoCrmClient::class)
            ->shouldReceive('exchangeAuthorizationCode')
            ->once()
            ->with('auth-code', 'example.amocrm.ru')
            ->andReturn([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]);

        $response = $this->getJson('/amocrm/oauth/callback?code=auth-code&referer=example.amocrm.ru');

        $response->assertOk()->assertJson([
            'ok' => true,
            'account_base_domain' => 'example.amocrm.ru',
        ]);

        $this->assertDatabaseHas(AmoCrmToken::class, [
            'account_base_domain' => 'example.amocrm.ru',
            'token_type' => 'Bearer',
        ]);
    }

    public function test_token_store_can_use_configured_long_lived_token(): void
    {
        config([
            'services.amocrm.base_domain' => 'https://TheSaitunLtd.amocrm.ru/',
            'services.amocrm.long_lived_token' => 'long-lived-token',
        ]);

        $token = app(AmoCrmTokenStore::class)->forDomain();

        $this->assertNotNull($token);
        $this->assertFalse($token->exists);
        $this->assertSame('thesaitunltd.amocrm.ru', $token->account_base_domain);
        $this->assertSame('long-lived-token', $token->access_token);
        $this->assertNull($token->refresh_token);
    }
}
