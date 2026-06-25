<?php

namespace App\Services\AmoCrm;

use App\Models\AmoCrmToken;
use Illuminate\Support\Carbon;
use League\OAuth2\Client\Token\AccessTokenInterface;

class AmoCrmTokenStore
{
    public function store(string $baseDomain, array $token): AmoCrmToken
    {
        return AmoCrmToken::updateOrCreate(
            ['account_base_domain' => $this->normalizeDomain($baseDomain)],
            [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'token_type' => $token['token_type'] ?? 'Bearer',
                'expires_at' => isset($token['expires'])
                    ? Carbon::createFromTimestamp((int) $token['expires'])
                    : Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 86400)),
            ],
        );
    }

    public function storeAccessToken(string $baseDomain, AccessTokenInterface $token): AmoCrmToken
    {
        return AmoCrmToken::updateOrCreate(
            ['account_base_domain' => $this->normalizeDomain($baseDomain)],
            [
                'access_token' => $token->getToken(),
                'refresh_token' => $token->getRefreshToken(),
                'token_type' => 'Bearer',
                'expires_at' => $token->getExpires() ? Carbon::createFromTimestamp($token->getExpires()) : null,
            ],
        );
    }

    public function forDomain(?string $baseDomain = null): ?AmoCrmToken
    {
        $baseDomain ??= config('services.amocrm.base_domain');

        if (! $baseDomain) {
            return null;
        }

        $normalizedDomain = $this->normalizeDomain($baseDomain);
        $storedToken = AmoCrmToken::query()
            ->where('account_base_domain', $this->normalizeDomain($baseDomain))
            ->first();

        if ($storedToken) {
            return $storedToken;
        }

        $longLivedToken = config('services.amocrm.long_lived_token');

        if (! $longLivedToken) {
            return null;
        }

        return AmoCrmToken::make([
            'account_base_domain' => $normalizedDomain,
            'access_token' => $longLivedToken,
            'refresh_token' => null,
            'token_type' => 'Bearer',
            'expires_at' => null,
        ]);
    }

    public function normalizeDomain(string $baseDomain): string
    {
        return str($baseDomain)
            ->replace(['https://', 'http://'], '')
            ->trim('/')
            ->lower()
            ->toString();
    }
}
