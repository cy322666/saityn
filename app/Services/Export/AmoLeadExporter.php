<?php

namespace App\Services\Export;

use App\Models\IntegrationEvent;
use App\Models\Seller;
use App\Services\AmoCrm\AmoCrmClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class AmoLeadExporter
{
    public function __construct(
        private readonly AmoCrmClient $amoCrm,
    ) {
    }

    public function exportPending(int $count): AmoLeadExportResult
    {
        $count = max(1, min($count, (int) config('services.amocrm.max_export_batch', 100)));
        $sellers = $this->reservePendingSellers($count);

        if ($sellers->isEmpty()) {
            return new AmoLeadExportResult($count, 0, 0, 0);
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
                $errors[] = 'seller '.($seller->seller_id ?: $seller->id).': '.$exception->getMessage();
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

        return new AmoLeadExportResult(
            requested: $count,
            selected: $sellers->count(),
            exported: $exported,
            failed: $failed,
            leadIds: $leadIds,
            error: $errors === [] ? null : implode(PHP_EOL, array_slice($errors, 0, 3)),
            created: $created,
            updated: $updated,
        );
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
}
