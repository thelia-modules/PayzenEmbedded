<?php

namespace PayzenEmbedded\Api\Resource;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use PayzenEmbedded\Model\Map\PayzenEmbeddedTransactionHistoryTableMap;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Map\TableMap;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\Ignore;
use Thelia\Api\Bridge\Propel\Filter\SearchFilter;
use Thelia\Api\Bridge\Propel\State\PropelCollectionProvider;
use Thelia\Api\Bridge\Propel\State\PropelItemProvider;
use Thelia\Api\Resource\Customer;
use Thelia\Api\Resource\PropelResourceInterface;
use Thelia\Api\Resource\PropelResourceTrait;
use Thelia\Api\Resource\ResourceAddonInterface;
use Thelia\Api\Resource\ResourceAddonTrait;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: 'admin/payzen-embedded/transaction-history',
            openapiContext: [
                'parameters' => [
                    [
                        'name' => 'orderId',
                        'in' => 'query',
                        'description' => 'Filter by order ID',
                        'schema' => ['type' => 'integer'],
                    ],
                    [
                        'name' => 'customerId',
                        'in' => 'query',
                        'description' => 'Filter by customer ID',
                        'schema' => ['type' => 'integer'],
                    ],
                ]
            ],
            paginationEnabled: true,
            provider: PropelCollectionProvider::class
        ),
        new Get(
            uriTemplate: 'admin/payzen-embedded/transaction-history/{id}',
            requirements: ['id' => '\d+'],
            openapiContext: [
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'description' => 'ID of the transaction history',
                        'schema' => ['type' => 'integer'],
                    ],
                    [
                        'name' => 'customerId',
                        'in' => 'query',
                        'description' => 'Filter by customer ID',
                        'schema' => ['type' => 'integer'],
                    ],
                ]
            ],
            provider: PropelItemProvider::class,
        )
    ],
    normalizationContext: ['groups' => [self::GROUP_ADMIN_READ]]
)]

#[ApiFilter(
    filterClass: SearchFilter::class,
    properties: [
        'id',
        'orderId',
        'customerId',
    ]
)]
class PayzenEmbeddedTransactionHistory implements PropelResourceInterface, ResourceAddonInterface
{
    use ResourceAddonTrait;
    use PropelResourceTrait;

    public const GROUP_ADMIN_READ = 'transaction_history:admin:read';

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?int $id = null;

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?int $orderId = null;

    #[Groups([Customer::GROUP_ADMIN_READ, Customer::GROUP_ADMIN_WRITE, Customer::GROUP_FRONT_READ_SINGLE])]
    public ?int $customerId = null;

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?int $adminId = null;

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?string $transactionRef = null;

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?string $status = null;

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?string $detailedStatus = null;

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?int $amount = null;

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?int $currencyId = null;

    #[Groups([self::GROUP_ADMIN_READ])]
    public ?string $creationDate = null;

    public function getId(): ?int
    {
        return $this->id;
    }
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }
    public function setOrderId(?int $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }
    public function setCustomerId(?int $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getAdminId(): ?int
    {
        return $this->adminId;
    }
    public function setAdminId(?int $adminId): void
    {
        $this->adminId = $adminId;
    }

    public function getTransactionRef(): ?string
    {
        return $this->transactionRef;
    }
    public function setTransactionRef(?string $transactionRef): void
    {
        $this->transactionRef = $transactionRef;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }
    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    public function getDetailedStatus(): ?string
    {
        return $this->detailedStatus;
    }
    public function setDetailedStatus(?string $detailedStatus): void
    {
        $this->detailedStatus = $detailedStatus;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }
    public function setAmount(?int $amount): void
    {
        $this->amount = $amount;
    }

    public function getCurrencyId(): ?int
    {
        return $this->currencyId;
    }
    public function setCurrencyId(?int $currencyId): void
    {
        $this->currencyId = $currencyId;
    }

    public function getCreationDate(): ?string
    {
        return $this->creationDate;
    }
    public function setCreationDate(?string $creationDate): void
    {
        $this->creationDate = $creationDate;
    }

    #[Ignore]
    public static function getPropelRelatedTableMap(): ?TableMap
    {
        return PayzenEmbeddedTransactionHistoryTableMap::getTableMap();
    }

    public static function getResourceParent(): string
    {
        return Customer::class;
    }
}
