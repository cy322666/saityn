<?php

namespace App\Services\Export;

use App\Models\IntegrationEvent;
use App\Models\Seller;
use App\Services\AmoCrm\AmoCrmClient;
use App\Services\Support\CommandLock;
use AmoCRM\Exceptions\AmoCRMApiErrorResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class AmoLeadExporter
{
    public function __construct(
        private readonly AmoCrmClient $amoCrm,
        private readonly CommandLock $lock,
    ) {
    }

    public function exportPending(int $count): AmoLeadExportResult
    {
        $count = max(1, min($count, (int) config('services.amocrm.max_export_batch', 100)));

        if (! $this->lock->acquire('amocrm-export')) {
            [$totalSellers, $pendingSellers] = $this->sellerCounts();

            return new AmoLeadExportResult(
                requested: $count,
                selected: 0,
                exported: 0,
                failed: 0,
                error: 'Выгрузка уже выполняется, повторный запуск пропущен.',
                totalSellers: $totalSellers,
                pendingSellers: $pendingSellers,
            );
        }

        try {
            return $this->runExportPending($count);
        } finally {
            $this->lock->release();
        }
    }

    private function runExportPending(int $count): AmoLeadExportResult
    {
        $sellers = $this->reservePendingSellers($count);

        if ($sellers->isEmpty()) {
            [$totalSellers, $pendingSellers] = $this->sellerCounts();

            return new AmoLeadExportResult(
                requested: $count,
                selected: 0,
                exported: 0,
                failed: 0,
                totalSellers: $totalSellers,
                pendingSellers: $pendingSellers,
            );
        }

        $leadIds = [];
        $failed = 0;
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($sellers as $seller) {
            try {
                $result = $this->amoCrm->createLeadFromSeller($seller);

                $seller->forceFill([
                    'lead_id' => $result['lead_id'],
                    'contact_id' => $result['contact_id'],
                    'company_id' => $result['company_id'],
                    'is_exported' => true,
                ])->save();

                $leadIds[$seller->id] = $result['lead_id'];

                if ($result['action'] === 'updated') {
                    $updated++;
                } else {
                    $created++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $seller->forceFill(['is_exported' => false])->save();
                $errors[] = 'seller '.($seller->seller_id ?: $seller->id).': '.$this->exceptionMessage($exception);
            }
        }

        $exported = $sellers->count() - $failed;

        $this->logEvent($failed > 0 ? 'export.finished_with_errors' : 'export.success', $sellers, [
            'exported' => $exported,
            'failed' => $failed,
            'created' => $created,
            'updated' => $updated,
            'lead_ids' => $leadIds,
            'errors' => $errors,
        ]);

        [$totalSellers, $pendingSellers] = $this->sellerCounts();

        return new AmoLeadExportResult(
            requested: $count,
            selected: $sellers->count(),
            exported: $exported,
            failed: $failed,
            leadIds: $leadIds,
            error: $errors === [] ? null : implode(PHP_EOL, array_slice($errors, 0, 3)),
            created: $created,
            updated: $updated,
            totalSellers: $totalSellers,
            pendingSellers: $pendingSellers,
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function sellerCounts(): array
    {
        return [
            Seller::query()->count(),
            Seller::query()->where('is_exported', false)->count(),
        ];
    }

    /**
     * @return Collection<int, Seller>
     */
    private function reservePendingSellers(int $count): Collection
    {
        return DB::transaction(function () use ($count): Collection {
            $sellers = Seller::query()
                ->where('is_exported', false)
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($count)
                ->get();

            Seller::query()
                ->whereIn('id', $sellers->pluck('id'))
                ->update([
                    'is_exported' => true,
                    'updated_at' => now(),
                ]);

            return $sellers->each->setAttribute('is_exported', true);
        });
    }

    private function logEvent(string $type, Collection $records, array $payload): void
    {
        IntegrationEvent::create([
            'provider' => 'amocrm',
            'type' => $type,
            'external_id' => $records->pluck('id')->implode(','),
            'payload' => $payload,
            'status' => str_contains($type, 'failed') || str_contains($type, 'errors') ? 'failed' : 'processed',
        ]);
    }

    private function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof AmoCRMApiErrorResponseException) {
            $details = $this->flattenValidationErrors($exception->getValidationErrors());

            if ($details !== []) {
                return $exception->getMessage().': '.implode('; ', array_slice($details, 0, 5));
            }
        }

        return $exception->getMessage();
    }

    /**
     * @return string[]
     */
    private function flattenValidationErrors(array $errors, string $prefix = ''): array
    {
        $result = [];

        foreach ($errors as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $message = $value['detail'] ?? $value['title'] ?? $value['message'] ?? null;

                if (is_string($message) && $message !== '') {
                    $result[] = "{$path}: {$message}";
                }

                $result = array_merge($result, $this->flattenValidationErrors($value, $path));

                continue;
            }

            if (is_scalar($value) && $value !== '') {
                $result[] = "{$path}: {$value}";
            }
        }

        return array_values(array_unique($result));
    }
}
