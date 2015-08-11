<?php
/** @var Wsu_ImportExport_Model_Resource_Setup $installer */
$installer = $this;

/** @var Mage_Catalog_Model_Resource_Category_Collection $collection */
$collection = Mage::getModel('catalog/category')->getCollection();

// Add a default identifier for existing categories
foreach($collection as $category) {
    $identifier = $category->getIdentifier();
    if(!$identifier || $identifier == '')  {
        $category
            ->setIdentifier(sprintf('CAT%s', $category->getId()))
            ->save();
    }
}