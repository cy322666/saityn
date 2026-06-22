<?php

namespace App\Console\Commands;

use App\Models\Seller;
use App\Services\AmoCrm\AmoCrmClient;
use Illuminate\Console\Command;

class ExportOneSellerToAmoCrm extends Command
{
    protected $signature = 'sellers:export-one {seller_id? : Local seller_id or local sellers.id}';

    protected $description = 'Export one seller as an amoCRM lead with all seller fields in a note.';

    public function handle(AmoCrmClient $amoCrm): int
    {
        $seller = $this->seller();

        if (! $seller) {
            $this->error('No seller found. Import sellers first with sellers:import.');

            return self::FAILURE;
        }

        $result = $amoCrm->createLeadFromSeller($seller);

        $seller->forceFill([
            'lead_id' => $result['lead_id'],
            'contact_id' => $result['contact_id'],
            'company_id' => $result['company_id'],
            'is_exported' => true,
        ])->save();

        $this->info('Seller exported to amoCRM.');
        $this->line('Action: '.$result['action']);
        $this->line('Local seller id: '.$seller->id);
        $this->line('seller_id: '.($seller->seller_id ?: '-'));
        $this->line('lead_id: '.($seller->lead_id ?: '-'));
        $this->line('contact_id: '.($seller->contact_id ?: '-'));
        $this->line('company_id: '.($seller->company_id ?: '-'));

        return self::SUCCESS;
    }

    private function seller(): ?Seller
    {
        $sellerId = $this->argument('seller_id');

        if ($sellerId) {
            return Seller::query()
                ->where('seller_id', $sellerId)
                ->orWhere('id', $sellerId)
                ->first();
        }

        return Seller::query()
            ->where('is_exported', false)
            ->orderBy('id')
            ->first();
    }
}
