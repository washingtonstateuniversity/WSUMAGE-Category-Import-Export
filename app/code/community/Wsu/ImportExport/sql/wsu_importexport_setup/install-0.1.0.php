<?php
/** @var Mage_Catalog_Model_Resource_Setup $installer */
$installer = $this;

$installer->startSetup();

$table = $installer->getTable('catalog/category');

//Add unique identifier for categories
$installer->getConnection()->addColumn(
    $table,
    'identifier',
    array(
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'    => 64,
        'comment' => 'Unique identifier for categories',
        'after' => 'parent_id'
    )
);
$installer->getConnection()->addIndex($table,
    $installer->getIdxName('catalog/category', array('identifier')),
    array('identifier')
);

$installer->addAttribute(Mage_Catalog_Model_Category::ENTITY, 'identifier', array(
    'type'                       => 'static',
    'label'                      => 'Identifier',
    'input'                      => 'text',
    'backend'                    => 'wsu_importexport/category_attribute_backend_identifier',
    'required'                   => true,
    'unique'                     => true,
    'sort_order'                 => 4,
    'group'                      => 'General Information'
));


/*
 * Set is_required to false for two core category attributes so import can be completed without specifiying values for them
 * It looks like these were marked as required by accident or for some legacy reasons.
 * No negative side effects of this change have come up so far
 */
$installer->updateAttribute(
    Mage_Catalog_Model_Category::ENTITY,
    $installer->getAttributeId(Mage_Catalog_Model_Category::ENTITY, 'available_sort_by'),
    'is_required',
    false
);
$installer->updateAttribute(
    Mage_Catalog_Model_Category::ENTITY,
    $installer->getAttributeId(Mage_Catalog_Model_Category::ENTITY, 'default_sort_by'),
    'is_required',
    false
);


$installer->endSetup();