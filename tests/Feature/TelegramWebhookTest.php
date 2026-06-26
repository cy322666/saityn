<?php

namespace Tests\Feature;

use App\Models\IntegrationEvent;
use App\Models\Seller;
use App\Models\TelegramUpdate;
use App\Services\AmoCrm\AmoCrmClient;
use App\Services\Telegram\TelegramBotClient;
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
            && str_contains($request['text'], "Отчет по выгрузке\n\nЗапрошено: 2")
            && str_contains($request['text'], 'Запрошено: 2')
            && str_contains($request['text'], 'Успешно загружено: 2')
            && str_contains($request['text'], 'Ошибок: 0')
            && str_contains($request['text'], 'Статус: успешно')
            && str_contains($request['text'], 'Всего в БД: 3')
            && str_contains($request['text'], 'Ждут выгрузки: 1')
            && ! str_contains($request['text'], 'Создано новых сделок')
            && ! str_contains($request['text'], 'Обновлено дублей')
            && ! str_contains($request['text'], 'ID сделок amoCRM'));
    }

    public function test_upload_command_defaults_to_ten_and_caps_requested_count(): void
    {
        config([
            'services.telegram.bot_token' => 'telegram-token',
            'services.telegram.command_chat_id' => null,
            'services.amocrm.base_domain' => 'example.amocrm.ru',
            'services.amocrm.max_export_batch' => 100,
        ]);

        for ($i = 1; $i <= 12; $i++) {
            Seller::create([
                'seller_id' => "seller-{$i}",
                'deal_name' => "Lead {$i}",
            ]);
        }

        $this->mock(AmoCrmClient::class)
            ->shouldReceive('createLeadFromSeller')
            ->times(10)
            ->andReturnUsing(fn (Seller $seller) => [
                'lead_id' => 1000 + $seller->id,
                'contact_id' => null,
                'company_id' => null,
                'action' => 'created',
            ]);

        Http::fake([
            'https://api.telegram.org/bottelegram-token/sendMessage' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 10005,
            'message' => [
                'chat' => ['id' => 555],
                'text' => '/upload 999',
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(10, Seller::where('is_exported', true)->count());

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Запрошено: 10')
            && str_contains($request['text'], 'Успешно загружено: 10')
            && str_contains($request['text'], 'Ошибок: 0')
            && str_contains($request['text'], 'Всего в БД: 12')
            && str_contains($request['text'], 'Ждут выгрузки: 2')
            && ! str_contains($request['text'], 'ID сделок amoCRM'));
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

    public function test_pending_processor_resends_saved_reports_even_without_new_updates(): void
    {
        $event = IntegrationEvent::create([
            'provider' => 'telegram',
            'type' => 'report.send_failed',
            'external_id' => '10005',
            'payload' => [
                'reply_chat_id' => '-5452931046',
                'reply' => 'Saved report',
                'source_event_id' => 123,
            ],
            'status' => 'failed',
            'error' => 'Previous timeout',
        ]);

        $this->mock(TelegramBotClient::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->with('-5452931046', 'Saved report')
            ->andReturn(['ok' => true]);

        $this->artisan('telegram:process-pending', ['--limit' => 10])
            ->expectsOutput('No pending Telegram updates.')
            ->expectsOutput("Report event {$event->id} sent.")
            ->assertSuccessful();

        $this->assertDatabaseHas(IntegrationEvent::class, [
            'id' => $event->id,
            'status' => 'processed',
            'error' => null,
        ]);
    }

    public function test_polling_fetches_and_processes_upload_commands(): void
    {
        config([
            'services.telegram.command_chat_id' => '-5452931046',
            'services.amocrm.base_domain' => 'example.amocrm.ru',
            'services.amocrm.max_export_batch' => 100,
        ]);

        $seller = Seller::create([
            'seller_id' => 'seller-poll-1',
            'deal_name' => 'Polling Lead',
            'director_full_name' => 'Polling Client',
        ]);

        $this->mock(TelegramBotClient::class)
            ->shouldReceive('getUpdates')
            ->once()
            ->with(0, 10, 0)
            ->andReturn([
                'ok' => true,
                'result' => [[
                    'update_id' => 20001,
                    'message' => [
                        'chat' => ['id' => -5452931046],
                        'text' => '/upload@flowdyone_bot 1',
                    ],
                ]],
            ])
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (string $chatId, string $text) => $chatId === '-5452931046'
                && str_contains($text, "Отчет по выгрузке\n\nЗапрошено: 1")
                && str_contains($text, 'Успешно загружено: 1')
                && str_contains($text, 'Всего в БД: 1')
                && str_contains($text, 'Ждут выгрузки: 0'))
            ->andReturn(['ok' => true]);

        $this->mock(AmoCrmClient::class)
            ->shouldReceive('createLeadFromSeller')
            ->once()
            ->withArgs(fn (Seller $exportedSeller) => $exportedSeller->is($seller))
            ->andReturn([
                'lead_id' => 401,
                'contact_id' => 501,
                'company_id' => 601,
                'action' => 'created',
            ]);

        $this->artisan('telegram:poll-updates', ['--limit' => 10])
            ->expectsOutput('Polled 1 Telegram updates.')
            ->expectsOutput('Processing Telegram update 20001...')
            ->assertSuccessful();

        $this->assertDatabaseHas(TelegramUpdate::class, [
            'update_id' => '20001',
            'message_chat_id' => '-5452931046',
            'message_text' => '/upload@flowdyone_bot 1',
        ]);
        $this->assertDatabaseHas(Seller::class, [
            'seller_id' => 'seller-poll-1',
            'is_exported' => true,
            'lead_id' => '401',
        ]);
    }
}
