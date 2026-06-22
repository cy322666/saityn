<?php

namespace Tests\Feature;

use App\Models\AmoCrmToken;
use App\Services\AmoCrm\AmoCrmClient;
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
}
