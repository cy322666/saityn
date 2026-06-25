<?php

namespace App\Services\AmoCrm;

use App\Models\AmoCrmToken;
use App\Models\AmoExportRecord;
use App\Models\Seller;
use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Client\LongLivedAccessToken;
use AmoCRM\Collections\ContactsCollection;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Collections\Leads\LeadsCollection;
use AmoCRM\Collections\LinksCollection;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Filters\CompaniesFilter;
use AmoCRM\Filters\ContactsFilter;
use AmoCRM\Filters\LeadsFilter;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Exceptions\AmoCRMApiNoContentException;
use AmoCRM\Models\CompanyModel;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\CustomFieldsValues\BaseCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\DateCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\MultitextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\StreetAddressCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextareaCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\UrlCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\BaseCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\MultitextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\BaseCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\DateCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\MultitextCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use AmoCRM\Models\NoteType\CommonNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use RuntimeException;

class AmoCrmClient
{
    private const LEAD_FIELD_ID_CLIENT = 2247635;
    private const LEAD_FIELD_EXPORT_BASE = 2254997;
    private const LEAD_FIELD_SELLER_URL = 2254999;
    private const LEAD_FIELD_PRODUCT_SECTION = 2250302;
    private const LEAD_FIELD_PRODUCT_CATEGORIES = 2250304;
    private const LEAD_FIELD_BRANDS = 2250306;
    private const LEAD_FIELD_LANDLINE_PHONES = 2250308;
    private const LEAD_FIELD_WEBSITE = 2250310;
    private const LEAD_FIELD_FIRST_PRODUCT_URL = 2250312;
    private const LEAD_FIELD_SELLER_RATING = 2250314;
    private const LEAD_FIELD_SOLD_PRODUCTS = 2250316;
    private const LEAD_FIELD_ORDERS_BUYOUT_PERCENT = 2250318;
    private const LEAD_FIELD_PRODUCTS_IN_STOCK = 2250320;
    private const LEAD_FIELD_WB_REGISTRATION = 2250322;
    private const LEAD_FIELD_WHATSAPP = 2251180;
    private const LEAD_FIELD_TELEGRAM = 2251182;
    private const LEAD_FIELD_VIBER = 2251184;
    private const LEAD_FIELD_VK = 2251186;
    private const LEAD_FIELD_INSTAGRAM = 2251188;
    private const LEAD_FIELD_OK = 2251190;
    private const LEAD_FIELD_OKVED = 2251192;
    private const LEAD_FIELD_AVG_SALES_SPEED = 2255001;
    private const CONTACT_FIELD_POSITION = 2206404;
    private const CONTACT_FIELD_TELEGRAM_URL = 2206476;
    private const CONTACT_FIELD_VK_URL = 2206478;
    private const COMPANY_FIELD_SEARCH_NAME = 2206486;
    private const COMPANY_FIELD_INN = 2206488;
    private const COMPANY_FIELD_OGRN = 2206492;
    private const COMPANY_FIELD_LEGAL_ADDRESS = 2206494;
    private const COMPANY_FIELD_STATUS = 2206496;
    private const COMPANY_FIELD_REGISTERED_AT = 2206498;
    private const COMPANY_FIELD_LIQUIDATED_AT = 2206502;
    private const COMPANY_FIELD_DIRECTOR_NAME = 2206504;
    private const COMPANY_FIELD_DIRECTOR_POSITION = 2206506;
    private const COMPANY_FIELD_ACTIVITY = 2242317;
    private const COMPANY_FIELD_REGION = 2242319;

    public function __construct(
        private readonly AmoCrmTokenStore $tokens,
    ) {
    }

    public function authorizationUrl(?string $state = null): string
    {
        return $this->newApiClient()->getOAuthClient()->getAuthorizeUrl([
            'state' => $state ?? Str::random(32),
            'mode' => 'post_message',
        ]);
    }

    public function exchangeAuthorizationCode(string $code, ?string $baseDomain = null): array
    {
        $client = $this->newApiClient();

        if ($baseDomain) {
            $client->getOAuthClient()->setBaseDomain($this->tokens->normalizeDomain($baseDomain));
        }

        return $this->accessTokenToArray($client->getOAuthClient()->getAccessTokenByCode($code), $baseDomain);
    }

    public function refresh(AmoCrmToken $token): AmoCrmToken
    {
        $client = $this->newApiClient();
        $client->getOAuthClient()->setBaseDomain($token->account_base_domain);

        $accessToken = $client->getOAuthClient()->getAccessTokenByRefreshToken($this->toAccessToken($token));

        return $this->tokens->storeAccessToken($token->account_base_domain, $accessToken);
    }

    /**
     * @param Collection<int, AmoExportRecord> $records
     * @return array<int, int|null>
     */
    public function createComplexLeads(Collection $records, ?string $baseDomain = null): array
    {
        $collection = new LeadsCollection();

        foreach ($records as $record) {
            $collection->add($this->toLeadModel($record));
        }

        $createdLeads = $this->authorizedClient($baseDomain)->leads()->addComplex($collection);
        $result = [];

        foreach ($createdLeads as $createdLead) {
            $requestIds = $createdLead->getComplexRequestIds() ?: [$createdLead->getRequestId()];

            foreach ($requestIds as $requestId) {
                $result[(int) $requestId] = $createdLead->getId();
            }
        }

        return $result;
    }

    /**
     * @return array{lead_id: int|null, contact_id: int|null, company_id: int|null, action: string}
     */
    public function createLeadFromSeller(Seller $seller, ?string $baseDomain = null): array
    {
        $client = $this->authorizedClient($baseDomain);
        $existingLead = $this->findSellerLead($client, $seller);
        $contact = $this->findOrCreateSellerContact($client, $seller);
        $company = $this->findOrCreateSellerCompany($client, $seller);

        if ($contact && $company) {
            $this->linkContactToCompany($client, $contact->getId(), $company->getId());
        }

        if ($existingLead) {
            $lead = $this->toSellerLeadModel($seller)
                ->setId($existingLead->getId());

            $client->leads()->updateOne($lead);
            $leadId = $existingLead->getId();
            $action = 'updated';
        } else {
            $lead = $this->toSellerLeadModel($seller);

            if ($contact) {
                $lead->setContacts((new ContactsCollection())->add(
                    (new ContactModel())->setId($contact->getId())->setIsMain(true),
                ));
            }

            if ($company) {
                $lead->setCompany((new CompanyModel())->setId($company->getId()));
            }

            $createdLead = $client->leads()->addOne($lead);
            $leadId = $createdLead->getId();
            $action = 'created';
        }

        if ($leadId) {
            $this->addSellerNote($leadId, $this->sellerNoteText($seller), $baseDomain);
            $this->linkSellerEntitiesToLead($client, $leadId, $contact?->getId(), $company?->getId());
        }

        return [
            'lead_id' => $leadId,
            'contact_id' => $contact?->getId(),
            'company_id' => $company?->getId(),
            'action' => $action,
        ];
    }

    public function moveLeadToConfiguredPipeline(int $leadId, ?string $baseDomain = null): void
    {
        $lead = (new LeadModel())->setId($leadId);
        $this->applyConfiguredPipeline($lead);

        $this->authorizedClient($baseDomain)->leads()->updateOne($lead);
    }

    private function authorizedClient(?string $baseDomain = null): AmoCRMApiClient
    {
        $token = $this->tokens->forDomain($baseDomain);

        if (! $token) {
            throw new RuntimeException('amoCRM token is not available for this domain.');
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            $token = $this->refresh($token);
        }

        return $this->newApiClient()
            ->setAccessToken($this->toAccessToken($token))
            ->setAccountBaseDomain($token->account_base_domain)
            ->onAccessTokenRefresh(
                fn (AccessTokenInterface $accessToken, string $baseDomain) => $this->tokens->storeAccessToken($baseDomain, $accessToken),
            );
    }

    private function newApiClient(): AmoCRMApiClient
    {
        $clientId = config('services.amocrm.client_id');
        $clientSecret = config('services.amocrm.client_secret');
        $redirectUri = config('services.amocrm.redirect_uri');

        if (! $clientId || ! $clientSecret || ! $redirectUri) {
            throw new RuntimeException('amoCRM OAuth settings are not configured.');
        }

        return new AmoCRMApiClient($clientId, $clientSecret, $redirectUri);
    }

    private function toAccessToken(AmoCrmToken $token): AccessToken
    {
        if (! $token->refresh_token) {
            return new LongLivedAccessToken($token->access_token);
        }

        return new AccessToken([
            'access_token' => $token->access_token,
            'refresh_token' => $token->refresh_token,
            'expires' => $token->expires_at?->timestamp,
            'baseDomain' => $token->account_base_domain,
        ]);
    }

    private function accessTokenToArray(AccessTokenInterface $token, ?string $baseDomain = null): array
    {
        return [
            'access_token' => $token->getToken(),
            'refresh_token' => $token->getRefreshToken(),
            'token_type' => 'Bearer',
            'expires' => $token->getExpires(),
            'base_domain' => $baseDomain,
        ];
    }

    private function toLeadModel(AmoExportRecord $record): LeadModel
    {
        $lead = (new LeadModel())
            ->setName($record->title)
            ->setRequestId((string) $record->id);

        $this->applyConfiguredPipeline($lead);

        if ($record->price !== null) {
            $lead->setPrice($record->price);
        }

        $contact = $this->toContactModel($record);

        if ($contact) {
            $lead->setContacts((new ContactsCollection())->add($contact));
        }

        return $lead;
    }

    private function toSellerLeadModel(Seller $seller): LeadModel
    {
        $lead = (new LeadModel())
            ->setName($seller->deal_name ?: $seller->trade_name ?: $seller->wb_seller_name ?: 'Seller '.$seller->id)
            ->setRequestId('seller-'.$seller->id);

        $this->applyConfiguredPipeline($lead);
        $this->applySellerLeadCustomFields($lead, $seller);

        return $lead;
    }

    private function findSellerLead(AmoCRMApiClient $client, Seller $seller): ?LeadModel
    {
        $storedLead = $this->storedLead($client, $seller->lead_id);

        if ($storedLead) {
            return $storedLead;
        }

        $sellerId = $this->formatFieldValue($seller->seller_id);

        if (! $sellerId) {
            return null;
        }

        try {
            $leads = $client->leads()->get(
                (new LeadsFilter())
                    ->setQuery($sellerId)
                    ->setLimit(10),
            );
        } catch (AmoCRMApiNoContentException) {
            $leads = null;
        } catch (\Throwable) {
            $leads = null;
        }

        foreach ($leads?->all() ?? [] as $candidate) {
            if ($candidate instanceof LeadModel && $this->leadHasSellerId($candidate, $sellerId)) {
                return $candidate;
            }
        }

        return null;
    }

    private function storedLead(AmoCRMApiClient $client, ?int $leadId): ?LeadModel
    {
        if (! $leadId) {
            return null;
        }

        try {
            $lead = $client->leads()->getOne($leadId);

            return $lead instanceof LeadModel ? $lead : null;
        } catch (AmoCRMApiNoContentException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function leadHasSellerId(LeadModel $lead, string $sellerId): bool
    {
        foreach ($lead->getCustomFieldsValues()?->all() ?? [] as $field) {
            if (! $field instanceof BaseCustomFieldValuesModel || $field->getFieldId() !== self::LEAD_FIELD_ID_CLIENT) {
                continue;
            }

            foreach ($field->getValues()?->all() ?? [] as $value) {
                if ((string) $value->getValue() === $sellerId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function findOrCreateSellerContact(AmoCRMApiClient $client, Seller $seller): ?ContactModel
    {
        if ($seller->contact_id) {
            try {
                $contact = $client->contacts()->getOne($seller->contact_id);

                if ($contact instanceof ContactModel) {
                    return $this->updateSellerContact($client, $contact->getId(), $seller);
                }
            } catch (AmoCRMApiNoContentException) {
            } catch (\Throwable) {
            }
        }

        foreach ($this->sellerContactSearchValues($seller) as $query) {
            try {
                $contact = $client->contacts()->get(
                    (new ContactsFilter())
                        ->setQuery($query)
                        ->setLimit(1),
                )?->first();
            } catch (AmoCRMApiNoContentException) {
                $contact = null;
            } catch (\Throwable) {
                $contact = null;
            }

            if ($contact instanceof ContactModel) {
                return $this->updateSellerContact($client, $contact->getId(), $seller);
            }
        }

        $contact = $this->toSellerContactModel($seller);

        return $contact ? $client->contacts()->addOne($contact) : null;
    }

    private function findOrCreateSellerCompany(AmoCRMApiClient $client, Seller $seller): ?CompanyModel
    {
        if ($seller->company_id) {
            try {
                $company = $client->companies()->getOne($seller->company_id);

                if ($company instanceof CompanyModel) {
                    return $this->updateSellerCompany($client, $company->getId(), $seller);
                }
            } catch (AmoCRMApiNoContentException) {
            } catch (\Throwable) {
            }
        }

        foreach ($this->sellerCompanyCustomSearchValues($seller) as $fieldId => $value) {
            $company = $this->findCompanyByCustomField($client, $fieldId, $value);

            if ($company instanceof CompanyModel) {
                return $this->updateSellerCompany($client, $company->getId(), $seller);
            }
        }

        foreach ($this->sellerCompanyNameSearchValues($seller) as $query) {
            try {
                $company = $client->companies()->get(
                    (new CompaniesFilter())
                        ->setQuery($query)
                        ->setLimit(1),
                )?->first();
            } catch (AmoCRMApiNoContentException) {
                $company = null;
            } catch (\Throwable) {
                $company = null;
            }

            if ($company instanceof CompanyModel) {
                return $this->updateSellerCompany($client, $company->getId(), $seller);
            }
        }

        $company = $this->toSellerCompanyModel($seller);

        return $company ? $client->companies()->addOne($company) : null;
    }

    private function findCompanyByCustomField(AmoCRMApiClient $client, int $fieldId, string $value): ?CompanyModel
    {
        try {
            $company = $client->companies()->get(
                (new CompaniesFilter())
                    ->setCustomFieldsValues([$fieldId => $value])
                    ->setLimit(1),
            )?->first();

            return $company instanceof CompanyModel ? $company : null;
        } catch (AmoCRMApiNoContentException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function updateSellerContact(AmoCRMApiClient $client, int $contactId, Seller $seller): ContactModel
    {
        $contact = $this->toSellerContactModel($seller);

        if (! $contact) {
            return (new ContactModel())->setId($contactId);
        }

        return $client->contacts()->updateOne($contact->setId($contactId));
    }

    private function updateSellerCompany(AmoCRMApiClient $client, int $companyId, Seller $seller): CompanyModel
    {
        $company = $this->toSellerCompanyModel($seller);

        if (! $company) {
            return (new CompanyModel())->setId($companyId);
        }

        return $client->companies()->updateOne($company->setId($companyId));
    }

    private function toSellerCompanyModel(Seller $seller): ?CompanyModel
    {
        $name = $this->sellerCompanyName($seller);

        if (! $name && ! $seller->inn && ! $seller->ogrn) {
            return null;
        }

        $company = (new CompanyModel())->setName($name ?: 'Seller '.($seller->seller_id ?: $seller->id));
        $this->applySellerCompanyCustomFields($company, $seller);

        return $company;
    }

    private function linkSellerEntitiesToLead(AmoCRMApiClient $client, int $leadId, ?int $contactId, ?int $companyId): void
    {
        $links = new LinksCollection();

        if ($contactId) {
            $links->add((new ContactModel())->setId($contactId)->setIsMain(true));
        }

        if ($companyId) {
            $links->add((new CompanyModel())->setId($companyId));
        }

        if ($links->count() === 0) {
            return;
        }

        try {
            $client->leads()->link((new LeadModel())->setId($leadId), $links);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function linkContactToCompany(AmoCRMApiClient $client, int $contactId, int $companyId): void
    {
        try {
            $client->contacts()->link(
                (new ContactModel())->setId($contactId),
                (new LinksCollection())->add((new CompanyModel())->setId($companyId)),
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return string[]
     */
    private function sellerContactSearchValues(Seller $seller): array
    {
        $values = [];

        foreach ($this->sellerPhoneValues($seller) as $phone) {
            $values[] = $phone;
        }

        foreach ($this->sellerEmailValues($seller) as $email) {
            $values[] = $email;
        }

        return array_values(array_unique(array_filter($values)));
    }

    /**
     * @return string[]
     */
    private function sellerCompanyCustomSearchValues(Seller $seller): array
    {
        return array_filter([
            self::COMPANY_FIELD_INN => $this->digitsOnly($seller->inn),
            self::COMPANY_FIELD_OGRN => $this->digitsOnly($seller->ogrn),
        ]);
    }

    /**
     * @return string[]
     */
    private function sellerCompanyNameSearchValues(Seller $seller): array
    {
        return array_values(array_unique(array_filter([
            $this->sellerCompanyName($seller),
            $seller->wb_organization_name,
            $seller->wb_seller_name,
        ])));
    }

    private function sellerCompanyName(Seller $seller): ?string
    {
        return $this->firstFilled([
            $seller->short_name,
            $seller->wb_organization_name,
            $seller->wb_seller_name,
            $seller->trade_name,
            $seller->wb_store_name,
        ]);
    }

    private function applySellerCompanyCustomFields(CompanyModel $company, Seller $seller): void
    {
        $fields = new CustomFieldsValuesCollection();

        $this->addMultitextField($fields, 'PHONE', $this->sellerCompanyPhoneValues($seller));
        $this->addMultitextField($fields, 'EMAIL', $this->sellerCompanyEmailValues($seller));
        $this->addUrlFieldByCode($fields, 'WEB', $this->firstFilled([
            $seller->company_website,
            $seller->website,
            $seller->website_extra,
        ]));
        $this->addTextareaField($fields, self::COMPANY_FIELD_SEARCH_NAME, $this->firstFilled([
            $seller->wb_organization_name,
            $seller->wb_seller_name,
            $seller->wb_store_name,
        ]));
        $this->addTextareaField($fields, self::COMPANY_FIELD_INN, $this->digitsOnly($seller->inn));
        $this->addTextareaField($fields, self::COMPANY_FIELD_OGRN, $this->digitsOnly($seller->ogrn));
        $this->addTextareaFieldByCode($fields, 'ADDRESS', $this->firstFilled([
            $seller->address,
            $seller->seller_address,
            $seller->wb_organization_address,
        ]));
        $this->addStreetAddressField($fields, self::COMPANY_FIELD_LEGAL_ADDRESS, $this->firstFilled([
            $seller->legal_address,
            $seller->address,
        ]));
        $this->addTextareaField($fields, self::COMPANY_FIELD_STATUS, $this->firstFilled([
            $seller->organization_status,
            $seller->company_status,
        ]));
        $this->addDateField($fields, self::COMPANY_FIELD_REGISTERED_AT, $this->firstFilled([
            $seller->getRawOriginal('fns_registered_at'),
            $seller->getRawOriginal('registered_at'),
        ]));
        $this->addDateField($fields, self::COMPANY_FIELD_LIQUIDATED_AT, $seller->getRawOriginal('liquidated_at'));
        $this->addTextareaField($fields, self::COMPANY_FIELD_DIRECTOR_NAME, $seller->director_full_name);
        $this->addTextareaField($fields, self::COMPANY_FIELD_DIRECTOR_POSITION, $seller->director_position);
        $this->addTextField($fields, self::COMPANY_FIELD_ACTIVITY, $this->firstFilled([
            trim(($seller->main_okved_code ?: '').' '.($seller->main_okved_name ?: '')),
            $seller->activity_type,
        ]));
        $this->addTextField($fields, self::COMPANY_FIELD_REGION, $seller->region);

        if (! $fields->isEmpty()) {
            $company->setCustomFieldsValues($fields);
        }
    }

    private function applySellerLeadCustomFields(LeadModel $lead, Seller $seller): void
    {
        $fields = new CustomFieldsValuesCollection();

        $this->addTextField($fields, self::LEAD_FIELD_ID_CLIENT, $seller->seller_id);
        $this->addTextField($fields, self::LEAD_FIELD_EXPORT_BASE, $seller->source_bases);
        $this->addUrlField($fields, self::LEAD_FIELD_SELLER_URL, $seller->seller_url);
        $this->addTextField($fields, self::LEAD_FIELD_PRODUCT_SECTION, $seller->product_section);
        $this->addTextField($fields, self::LEAD_FIELD_PRODUCT_CATEGORIES, $seller->product_categories);
        $this->addTextField($fields, self::LEAD_FIELD_BRANDS, $seller->brands);
        $this->addTextField($fields, self::LEAD_FIELD_LANDLINE_PHONES, $this->firstFilled([
            $seller->working_landline_phones,
            $seller->work_landline_phones,
            $seller->company_landline_phones,
            $seller->landline_phones,
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_WEBSITE, $this->firstFilled([
            $seller->website,
            $seller->company_website,
            $seller->website_extra,
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_FIRST_PRODUCT_URL, $seller->first_product_url);
        $this->addTextField($fields, self::LEAD_FIELD_SELLER_RATING, $seller->seller_rating ?: $seller->rating);
        $this->addTextField($fields, self::LEAD_FIELD_SOLD_PRODUCTS, $seller->sold_products ?: $seller->sold_products_count);
        $this->addTextField($fields, self::LEAD_FIELD_ORDERS_BUYOUT_PERCENT, $seller->orders_buyout_percent ?: $seller->buyout_percent);
        $this->addTextField($fields, self::LEAD_FIELD_PRODUCTS_IN_STOCK, $seller->products_in_stock_count);
        $this->addTextField($fields, self::LEAD_FIELD_WB_REGISTRATION, $this->firstFilled([
            $this->formatFieldValue($seller->getRawOriginal('wb_registration_at')),
            $this->formatFieldValue($seller->getRawOriginal('wildberries_registered_at')),
            $this->formatFieldValue($seller->getRawOriginal('platform_registered_at')),
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_WHATSAPP, $this->firstFilled([
            $seller->work_whatsapp,
            $seller->whatsapp,
            $seller->work_whatsapp_legal_extra,
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_TELEGRAM, $this->firstFilled([
            $seller->work_telegram,
            $seller->telegram,
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_VIBER, $seller->viber);
        $this->addTextField($fields, self::LEAD_FIELD_VK, $this->firstFilled([
            $seller->work_vk,
            $seller->vk,
            $seller->work_vk_extra_source,
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_INSTAGRAM, $this->firstFilled([
            $seller->work_instagram,
            $seller->instagram,
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_OK, $this->firstFilled([
            $seller->work_ok,
            $seller->ok,
            $seller->work_ok_extra_source,
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_OKVED, $this->firstFilled([
            trim(($seller->main_okved_code ?: '').' '.($seller->main_okved_name ?: '')),
            $seller->activity_type,
        ]));
        $this->addTextField($fields, self::LEAD_FIELD_AVG_SALES_SPEED, $seller->avg_sold_products_per_day ?: $seller->sales_speed_per_day);

        if (! $fields->isEmpty()) {
            $lead->setCustomFieldsValues($fields);
        }
    }

    private function applyConfiguredPipeline(LeadModel $lead): void
    {
        $pipelineId = config('services.amocrm.pipeline_id');
        $statusId = config('services.amocrm.status_id');

        if ($pipelineId) {
            $lead->setPipelineId((int) $pipelineId);
        }

        if ($statusId) {
            $lead->setStatusId((int) $statusId);
        }
    }

    private function toSellerContactModel(Seller $seller): ?ContactModel
    {
        $phone = $this->sellerPhoneValues($seller)[0] ?? null;
        $email = $this->sellerEmailValues($seller)[0] ?? null;
        $name = $this->firstFilled([
            $seller->director_full_name,
            $seller->wb_seller_name,
            $seller->trade_name,
            $seller->short_name,
        ]);

        if (! $name && ! $phone && ! $email) {
            return null;
        }

        $contact = new ContactModel();

        if ($name) {
            $contact->setName($name);
        }

        $fields = new CustomFieldsValuesCollection();

        if ($phone) {
            $fields->add($this->multitextField('PHONE', $phone));
        }

        if ($email) {
            $fields->add($this->multitextField('EMAIL', $email));
        }

        $this->addTextField($fields, self::CONTACT_FIELD_POSITION, $seller->director_position);
        $this->addUrlField($fields, self::CONTACT_FIELD_TELEGRAM_URL, $this->normalizeTelegramUrl($this->firstFilled([
            $seller->work_telegram,
            $seller->telegram,
        ])));
        $this->addUrlField($fields, self::CONTACT_FIELD_VK_URL, $this->normalizeVkUrl($this->firstFilled([
            $seller->work_vk,
            $seller->vk,
            $seller->work_vk_extra_source,
        ])));

        if (! $fields->isEmpty()) {
            $contact->setCustomFieldsValues($fields);
        }

        return $contact;
    }

    private function addSellerNote(int $leadId, string $text, ?string $baseDomain = null): void
    {
        $note = (new CommonNote())
            ->setEntityId($leadId)
            ->setText($text);

        $this->authorizedClient($baseDomain)
            ->notes(EntityTypesInterface::LEADS)
            ->add((new NotesCollection())->add($note));
    }

    private function sellerNoteText(Seller $seller): string
    {
        $labels = array_flip(Seller::COLUMN_MAP);
        $lines = ['Данные продавца из локальной БД'];

        foreach ($seller->getAttributes() as $column => $value) {
            if (in_array($column, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $label = $labels[$column] ?? $column;
            $displayValue = $value === null || $value === '' ? '-' : (string) $value;

            if (mb_strlen($displayValue) > 1000) {
                $displayValue = mb_substr($displayValue, 0, 1000).'...';
            }

            $lines[] = "{$label}: {$displayValue}";
        }

        return implode(PHP_EOL, $lines);
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim(preg_split('/[,;\\n]+/u', $value)[0]);
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function sellerPhoneValues(Seller $seller): array
    {
        $phones = [];

        foreach ($this->splitFieldValues([
            $seller->working_mobile_phones,
            $seller->work_mobile_phones,
            $seller->work_mobile_phones_legal_extra,
            $seller->company_mobile_phones,
            $seller->mobile_phones,
            $seller->working_landline_phones,
            $seller->work_landline_phones,
            $seller->work_landline_phones_legal_extra,
            $seller->company_landline_phones,
            $seller->landline_phones,
        ]) as $value) {
            $normalized = $this->normalizePhone($value);

            if ($normalized) {
                $phones[] = '+'.$normalized;
                $phones[] = $normalized;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @return string[]
     */
    private function sellerEmailValues(Seller $seller): array
    {
        $emails = [];

        foreach ($this->splitFieldValues([
            $seller->working_email,
            $seller->work_emails,
            $seller->company_email,
            $seller->email,
            $seller->work_emails_extra_source,
        ]) as $value) {
            $email = mb_strtolower(trim($value));

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @return string[]
     */
    private function sellerCompanyPhoneValues(Seller $seller): array
    {
        return $this->normalizedPhoneValues([
            $seller->company_mobile_phones,
            $seller->company_landline_phones,
            $seller->mobile_phones,
            $seller->landline_phones,
            $seller->work_mobile_phones,
            $seller->work_landline_phones,
            $seller->working_mobile_phones,
            $seller->working_landline_phones,
        ]);
    }

    /**
     * @return string[]
     */
    private function sellerCompanyEmailValues(Seller $seller): array
    {
        return $this->normalizedEmailValues([
            $seller->company_email,
            $seller->email,
            $seller->work_emails,
            $seller->working_email,
            $seller->work_emails_extra_source,
        ]);
    }

    /**
     * @return string[]
     */
    private function normalizedPhoneValues(array $values): array
    {
        $phones = [];

        foreach ($this->splitFieldValues($values) as $value) {
            $normalized = $this->normalizePhone($value);

            if ($normalized) {
                $phones[] = '+'.$normalized;
                $phones[] = $normalized;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @return string[]
     */
    private function normalizedEmailValues(array $values): array
    {
        $emails = [];

        foreach ($this->splitFieldValues($values) as $value) {
            $email = mb_strtolower(trim($value));

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @return string[]
     */
    private function splitFieldValues(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            foreach (preg_split('/[,;\\n]+/u', $value) ?: [] as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $result[] = $part;
                }
            }
        }

        return $result;
    }

    private function normalizePhone(string $value): ?string
    {
        $digits = $this->digitsOnly($value);

        if (! $digits) {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    private function digitsOnly(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\\D+/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $value = $this->formatFieldValue($value);

        if ($value === null) {
            return null;
        }

        if (! preg_match('~^https?://~i', $value)) {
            $value = 'https://'.$value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private function normalizeTelegramUrl(mixed $value): ?string
    {
        $value = $this->formatFieldValue($value);

        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, '@')) {
            return 'https://t.me/'.ltrim($value, '@');
        }

        if (! str_contains($value, '/') && ! str_contains($value, '.')) {
            return 'https://t.me/'.$value;
        }

        return $this->normalizeUrl($value);
    }

    private function normalizeVkUrl(mixed $value): ?string
    {
        $value = $this->formatFieldValue($value);

        if ($value === null) {
            return null;
        }

        if (! str_contains($value, '/') && ! str_contains($value, '.')) {
            return 'https://vk.com/'.$value;
        }

        return $this->normalizeUrl($value);
    }

    private function toContactModel(AmoExportRecord $record): ?ContactModel
    {
        if (! $record->contact_name && ! $record->phone && ! $record->email) {
            return null;
        }

        $contact = new ContactModel();

        if ($record->contact_name) {
            $contact->setName($record->contact_name);
        }

        $fields = new CustomFieldsValuesCollection();

        if ($record->phone) {
            $fields->add($this->multitextField('PHONE', $record->phone));
        }

        if ($record->email) {
            $fields->add($this->multitextField('EMAIL', $record->email));
        }

        if (! $fields->isEmpty()) {
            $contact->setCustomFieldsValues($fields);
        }

        return $contact;
    }

    private function multitextField(string $fieldCode, string $value): MultitextCustomFieldValuesModel
    {
        return (new MultitextCustomFieldValuesModel())
            ->setFieldCode($fieldCode)
            ->setValues(
                (new MultitextCustomFieldValueCollection())
                    ->add((new MultitextCustomFieldValueModel())->setValue($value)),
            );
    }

    /**
     * @param string[] $values
     */
    private function addMultitextField(CustomFieldsValuesCollection $fields, string $fieldCode, array $values): void
    {
        $values = array_values(array_unique(array_filter($values)));

        if ($values === []) {
            return;
        }

        $collection = new MultitextCustomFieldValueCollection();

        foreach (array_slice($values, 0, 5) as $value) {
            $collection->add((new MultitextCustomFieldValueModel())->setValue($value));
        }

        $fields->add(
            (new MultitextCustomFieldValuesModel())
                ->setFieldCode($fieldCode)
                ->setValues($collection),
        );
    }

    private function addTextField(CustomFieldsValuesCollection $fields, int $fieldId, mixed $value): void
    {
        $this->addCustomField($fields, (new TextCustomFieldValuesModel())->setFieldId($fieldId), $value);
    }

    private function addTextareaField(CustomFieldsValuesCollection $fields, int $fieldId, mixed $value): void
    {
        $this->addCustomField($fields, (new TextareaCustomFieldValuesModel())->setFieldId($fieldId), $value);
    }

    private function addTextareaFieldByCode(CustomFieldsValuesCollection $fields, string $fieldCode, mixed $value): void
    {
        $this->addCustomField($fields, (new TextareaCustomFieldValuesModel())->setFieldCode($fieldCode), $value);
    }

    private function addUrlField(CustomFieldsValuesCollection $fields, int $fieldId, mixed $value): void
    {
        $this->addCustomField($fields, (new UrlCustomFieldValuesModel())->setFieldId($fieldId), $value);
    }

    private function addUrlFieldByCode(CustomFieldsValuesCollection $fields, string $fieldCode, mixed $value): void
    {
        $this->addCustomField($fields, (new UrlCustomFieldValuesModel())->setFieldCode($fieldCode), $this->normalizeUrl($value));
    }

    private function addStreetAddressField(CustomFieldsValuesCollection $fields, int $fieldId, mixed $value): void
    {
        $this->addCustomField($fields, (new StreetAddressCustomFieldValuesModel())->setFieldId($fieldId), $value);
    }

    private function addDateField(CustomFieldsValuesCollection $fields, int $fieldId, mixed $value): void
    {
        $value = $this->normalizeDateForAmo($value);

        if ($value === null) {
            return;
        }

        $field = (new DateCustomFieldValuesModel())->setFieldId($fieldId);
        $field->setValues(
            (new BaseCustomFieldValueCollection())
                ->add((new DateCustomFieldValueModel())->setValue($value)),
        );

        $fields->add($field);
    }

    private function addCustomField(CustomFieldsValuesCollection $fields, BaseCustomFieldValuesModel $field, mixed $value): void
    {
        $value = $this->formatFieldValue($value);

        if ($value === null) {
            return;
        }

        $field->setValues(
            (new BaseCustomFieldValueCollection())
                ->add((new BaseCustomFieldValueModel())->setValue($value)),
        );

        $fields->add($field);
    }

    private function formatFieldValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeDateForAmo(mixed $value): ?string
    {
        $value = $this->formatFieldValue($value);

        if ($value === null) {
            return null;
        }

        $value = trim(preg_split('/[|;,\\n]+/u', $value)[0] ?? $value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (int) $value;

            if ($serial > 20000 && $serial < 60000) {
                return now()->setDate(1899, 12, 30)->startOfDay()->addDays($serial)->toDateString();
            }
        }

        $timestamp = strtotime($value);

        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }
}
