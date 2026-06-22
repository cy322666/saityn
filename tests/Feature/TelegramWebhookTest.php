<?php

namespace Tests\Feature;

use App\Models\IntegrationEvent;
use App\Models\Seller;
use App\Models\TelegramUpdate;
use App\Services\AmoCrm\AmoCrmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_telegram_webhook_updates(): void
    {
        $response = $this->postJson('/api/telegram/webhook', [
            'update_id' => 10001,
            'message' => [
                'chat' => ['id' => 555],
                'text' => 'hello',
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas(TelegramUpdate::class, [
            'update_id' => '10001',
            'message_chat_id' => '555',
            'message_text' => 'hello',
        ]);

        $this->assertDatabaseHas(IntegrationEvent::class, [
            'provider' => 'telegram',
            'type' => 'webhook.update',
            'external_id' => '10001',
            'status' => 'processed',
        ]);
    }

    public function test_it_rejects_webhook_with_wrong_secret(): void
    {
        config(['services.telegram.webhook_secret' => 'expected-secret']);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 10002,
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
        ])->assertForbidden();

        $this->assertDatabaseMissing(TelegramUpdate::class, [
            'update_id' => '10002',
        ]);
    }

    public function test_upload_command_exports_requested_pending_records_to_amocrm(): void
    {
        config([
            'services.telegram.bot_token' => 'telegram-token',
            'services.telegram.command_chat_id' => null,
            'services.amocrm.base_domain' => 'example.amocrm.ru',
            'services.amocrm.max_export_batch' => 100,
        ]);

        $seller1 = Seller::create([
            'seller_id' => 'seller-1',
            'deal_name' => 'Lead 1',
            'director_full_name' => 'Client 1',
            'work_mobile_phones' => '+79990000001',
        ]);
        $seller2 = Seller::create([
            'seller_id' => 'seller-2',
            'deal_name' => 'Lead 2',
            'director_full_name' => 'Client 2',
            'work_emails' => 'client2@example.test',
        ]);
        Seller::create([
            'seller_id' => 'seller-3',
            'deal_name' => 'Lead 3',
        ]);

        $this->mock(AmoCrmClient::class)
            ->shouldReceive('createLeadFromSeller')
            ->twice()
            ->andReturnUsing(fn (Seller $seller) => [
                'lead_id' => $seller->id === $seller1->id ? 101 : 102,
                'contact_id' => $seller->id === $seller1->id ? 201 : 202,
                'company_id' => $seller->id === $seller1->id ? 301 : 302,
                'action' => $seller->id === $seller1->id ? 'created' : 'updated',
            ]);

        Http::fake([
            'https://api.telegram.org/bottelegram-token/sendMessage' => Http::response(['ok' => true]),
        ]);

        $response = $this->postJson('/api/telegram/webhook', [
            'update_id' => 10003,
            'message' => [
                'chat' => ['id' => 555],
                'text' => '/upload 2',
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas(Seller::class, [
            'seller_id' => 'seller-1',
            'is_exported' => true,
            'lead_id' => '101',
            'contact_id' => '201',
            'company_id' => '301',
        ]);
        $this->assertDatabaseHas(Seller::class, [
            'seller_id' => 'seller-2',
            'is_exported' => true,
            'lead_id' => '102',
            'contact_id' => '202',
            'company_id' => '302',
        ]);
        $this->assertDatabaseHas(Seller::class, [
            'seller_id' => 'seller-3',
            'is_exported' => false,
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '555'
            && str_contains($request['text'], 'Отчет по выгрузке amoCRM')
            && str_contains($request['text'], 'Успешно загружено: 2')
            && str_contains($request['text'], 'Создано новых сделок: 1')
            && str_contains($request['text'], 'Обновлено дублей: 1')
            && str_contains($request['text'], 'ID сделок amoCRM: 101, 102'));
    }

    public function test_it_ignores_commands_from_unconfigured_chat(): void
    {
        config([
            'services.telegram.bot_token' => 'telegram-token',
            'services.telegram.command_chat_id' => '-5452931046',
        ]);

        Seller::create([
            'seller_id' => 'seller-1',
            'deal_name' => 'Lead 1',
        ]);

        $this->mock(AmoCrmClient::class)
            ->shouldNotReceive('createLeadFromSeller');

        Http::fake([
            'https://api.telegram.org/bottelegram-token/sendMessage' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 10004,
            'message' => [
                'chat' => ['id' => 555],
                'text' => '/upload 1',
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas(Seller::class, [
            'seller_id' => 'seller-1',
            'is_exported' => false,
        ]);

        $this->assertDatabaseHas(IntegrationEvent::class, [
            'provider' => 'telegram',
            'type' => 'webhook.ignored_chat',
            'external_id' => '10004',
            'status' => 'ignored',
        ]);

        Http::assertNothingSent();
    }
}
