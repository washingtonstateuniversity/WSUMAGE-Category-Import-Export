<?php

class Wsu_ImportExport_Model_Category_Attribute_Backend_Identifier extends Mage_Eav_Model_Entity_Attribute_Backend_Abstract {
    /**
     * Maximum Identifier string length
     *
     * @var string
     */
    const IDENTIFIER_MAX_LENGTH = 64;

    /**
     * Validate Identifier
     *
     * @param Mage_Catalog_Model_Category $object
     * @throws Mage_Core_Exception
     * @return bool
     */
    public function validate($object) {
        $helper = Mage::helper('core/string');

        if ($helper->strlen($object->getIdentifier()) > self::IDENTIFIER_MAX_LENGTH) {
            Mage::throwException(
                Mage::helper('catalog')->__('Identifier length should be %s characters maximum.', self::IDENTIFIER_MAX_LENGTH)
            );
        }
        return parent::validate($object);
    }
}