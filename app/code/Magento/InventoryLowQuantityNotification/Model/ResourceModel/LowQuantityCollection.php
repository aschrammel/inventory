<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryLowQuantityNotification\Model\ResourceModel;

use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Inventory\Model\ResourceModel\SourceItem as SourceItemResourceModel;
use Magento\Inventory\Model\SourceItem as SourceItemModel;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryLowQuantityNotification\Setup\Operation\CreateSourceConfigurationTable;
use Magento\InventoryLowQuantityNotificationApi\Api\Data\SourceItemConfigurationInterface;
use Psr\Log\LoggerInterface;

class LowQuantityCollection extends AbstractCollection
{
    /**
     * @var StockConfigurationInterface
     */
    private $stockConfiguration;

    /**
     * @var AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @param EntityFactoryInterface $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param AttributeRepositoryInterface $attributeRepository
     * @param StockConfigurationInterface $stockConfiguration
     * @param AdapterInterface|null $connection
     * @param AbstractDb|null $resource
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        AttributeRepositoryInterface $attributeRepository,
        StockConfigurationInterface $stockConfiguration,
        AdapterInterface $connection = null,
        AbstractDb $resource = null
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->stockConfiguration = $stockConfiguration;

        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $connection,
            $resource
        );
    }

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(SourceItemModel::class, SourceItemResourceModel::class);
    }

    /**
     * @inheritdoc
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $this->addFilterToMap('source_code', 'main_table.source_code');
        $this->addFilterToMap('sku', 'main_table.sku');
        $this->addFilterToMap('product_name', 'product_entity_varchar.value');

        $this->addFieldToSelect('*');

        $this->joinCatalogProduct();
        $this->joinInventoryConfiguration();

        $this->addProductTypeFilter();
        $this->addNotifyStockQtyFilter();

        $this->setOrder(
            SourceItemInterface::QUANTITY,
            self::SORT_ORDER_ASC
        );
        return $this;
    }

    /**
     * @return void
     */
    private function joinCatalogProduct()
    {
        $productEntityTable = $this->getTable('catalog_product_entity');
        $productEavVarcharTable = $this->getTable('catalog_product_entity_varchar');
        $nameAttribute = $this->attributeRepository->get('catalog_product', 'name');

        $this->getSelect()->joinInner(
            ['product_entity' => $productEntityTable],
            'main_table.' . SourceItemInterface::SKU . ' = product_entity.sku',
            []
        );

        $this->getSelect()->joinInner(
            ['product_entity_varchar' => $productEavVarcharTable],
            'product_entity_varchar.entity_id = product_entity.entity_id AND product_entity_varchar.attribute_id = '
                . $nameAttribute->getAttributeId(),
            ['product_name' => 'value']
        );
    }

    /**
     * @return void
     */
    private function joinInventoryConfiguration()
    {
        $sourceItemConfigurationTable = $this->getTable(
            CreateSourceConfigurationTable::TABLE_NAME_SOURCE_ITEM_CONFIGURATION
        );

        $this->getSelect()->joinInner(
            ['notification_configuration' => $sourceItemConfigurationTable],
            sprintf(
                'main_table.%s = notification_configuration.%s AND main_table.%s = notification_configuration.%s',
                SourceItemInterface::SKU,
                SourceItemConfigurationInterface::SKU,
                SourceItemInterface::SOURCE_CODE,
                SourceItemConfigurationInterface::SOURCE_CODE
            ),
            []
        );
    }

    /**
     * @return void
     */
    private function addProductTypeFilter()
    {
        $types = array_keys(array_filter($this->stockConfiguration->getIsQtyTypeIds()));
        $this->addFieldToFilter('type_id', $types);
    }

    /**
     * @return void
     */
    private function addNotifyStockQtyFilter()
    {
        $notifyStockExpression = $this->getConnection()->getIfNullSql(
            'notification_configuration.' . SourceItemConfigurationInterface::INVENTORY_NOTIFY_QTY,
            (float)$this->stockConfiguration->getNotifyStockQty()
        );

        $this->getSelect()->where(
            SourceItemInterface::QUANTITY . ' < ?',
            $notifyStockExpression
        );
    }
}
