<?php

namespace App\Http\Controllers;

use App\Services\AmoCrm\AmoCrmClient;
use App\Services\AmoCrm\AmoCrmTokenStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AmoCrmOAuthController extends Controller
{
    public function redirect(AmoCrmClient $amoCrm): RedirectResponse
    {
        return redirect()->away($amoCrm->authorizationUrl());
    }

    public function callback(Request $request, AmoCrmClient $amoCrm, AmoCrmTokenStore $tokens): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'referer' => ['nullable', 'string'],
        ]);

        $baseDomain = $data['referer'] ?? config('services.amocrm.base_domain');
        $token = $amoCrm->exchangeAuthorizationCode($data['code'], $baseDomain);
        $tokens->store($baseDomain, $token);

        return response()->json([
            'ok' => true,
            'account_base_domain' => $baseDomain,
        ]);
    }
}

