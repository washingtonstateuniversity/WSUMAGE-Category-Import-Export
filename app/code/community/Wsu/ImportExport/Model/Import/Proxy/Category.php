<?php

class Wsu_ImportExport_Model_Import_Proxy_Category extends Mage_Catalog_Model_Category {
    /**
     * DO NOT Initialize resources.
     *
     * @return void
     */
    protected function _construct() {
    }

    /**
     * Retrieve object id
     *
     * @return int
     */
    public function getId() {
        return $this->_getData('id');
    }
}
