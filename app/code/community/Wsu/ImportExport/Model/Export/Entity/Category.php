<?php

class Wsu_ImportExport_Model_Export_Entity_Category extends Mage_ImportExport_Model_Export_Entity_Abstract {

	/**
	 * Permanent column names.
	 *
	 * Names that begins with underscore is not an attribute. This name convention is for
	 * to avoid interference with same attribute name.
	 */
	const COL_WEBSITE   = '_website';
	const COL_STORE     = '_store';
	const COL_ATTR_SET  = '_attribute_set';

	/**
	 * Permanent entity columns.
	 *
	 * @var array
	 */
	protected $_permanentAttributes = array(self::COL_WEBSITE, self::COL_STORE);

	/**
	 * Array of pairs store ID to its code.
	 *
	 * @var array
	 */
	protected $_storeIdToCode = array();

	/**
	 * Website ID-to-code.
	 *
	 * @var array
	 */
	protected $_websiteIdToCode = array();

	/**
	 * Pairs of attribute set ID-to-name.
	 *
	 * @var array
	 */
	protected $_attrSetIdToName = array();

	/**
	 * Attribute types
	 * @var array
	 */
	protected $_attributeTypes = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		$this
			->_initAttributes()
			->_initStores()
			->_initAttributeSets()
			->_initWebsites();
	}

	/**
	 * Initialize attribute sets code-to-id pairs.
	 *
	 * @return $this
	 */
	protected function _initAttributeSets() {
		$entityTypeId = Mage::getModel('catalog/category')->getResource()->getTypeId();
		foreach (Mage::getResourceModel('eav/entity_attribute_set_collection')
					 ->setEntityTypeFilter($entityTypeId) as $attributeSet) {
			$this->_attrSetIdToName[$attributeSet->getId()] = $attributeSet->getAttributeSetName();
		}
		return $this;
	}

	/**
	 * Initialize website values.
	 *
	 * @return $this
	 */
	protected function _initWebsites() {
		/** @var $website Mage_Core_Model_Website */
		foreach (Mage::app()->getWebsites(true) as $website) {
			$this->_websiteIdToCode[$website->getId()] = $website->getCode();
		}
		return $this;
	}

	/**
	 * Initialize attribute option values and types.
	 *
	 * @return $this
	 */
	protected function _initAttributes() {
		foreach ($this->getAttributeCollection() as $attribute) {
			$this->_attributeValues[$attribute->getAttributeCode()] = $this->getAttributeOptions($attribute);
			$this->_attributeTypes[$attribute->getAttributeCode()] =
				Mage_ImportExport_Model_Import::getAttributeType($attribute);
		}
		return $this;
	}


	/**
	 * Export process.
	 *
	 * @return string
	 */
	public function export() {
		
		//Execution time may be very long
		set_time_limit(0);

		$validAttrCodes  = $this->_getExportAttrCodes();
		$writer          = $this->getWriter();
		$defaultStoreId  = Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID;

		$memoryLimit = trim(ini_get('memory_limit'));
		$lastMemoryLimitLetter = strtolower($memoryLimit[strlen($memoryLimit)-1]);
		switch($lastMemoryLimitLetter) {
			case 'g':
				$memoryLimit *= 1024;
				break;
			case 'm':
				$memoryLimit *= 1024;
				break;
			case 'k':
				$memoryLimit *= 1024;
				break;
			default:
				// minimum memory required by Magento
				$memoryLimit = 250000000;
		}

		// Tested one entity to have up to such size
		$memoryPerEntity = 100000;
		// Decrease memory limit to have supply
		$memoryUsagePercent = 0.8;
		// Minimum Entity limit
		$minEntitiesLimit = 500;

		$limitEntities = intval(($memoryLimit  * $memoryUsagePercent - memory_get_usage(true)) / $memoryPerEntity);
		if ($limitEntities < $minEntitiesLimit) {
			$limitEntities = $minEntitiesLimit;
		}
		$offsetEntities = 0;
		
		while (true) {
			++$offsetEntities;

			$dataRows = array();
			$rowWebsites = array();
			$rowMultiselects = array();

			// prepare multi-store values and system columns values
			foreach ($this->_storeIdToCode as $storeId => &$storeCode) { // go through all stores
				$collection = $this->_prepareEntityCollection(Mage::getResourceModel('catalog/category_collection'));
				$collection
					->setStoreId($storeId)
					->setPage($offsetEntities, $limitEntities);

				if ($collection->getCurPage() < $offsetEntities) {
					break;
				}
				$collection->load();

				if ($collection->count() == 0) {
					break;
				}

				/**
				 * @var int $itemId
				 * @var  Mage_Catalog_Model_Category $item
				 */
				foreach ($collection as $itemId => $item) { // go through all categories
					$rowIsEmpty = true; // row is empty by default
					
					foreach ($validAttrCodes as &$attrCode) { // go through all valid attribute codes
						$attrValue = $item->getData($attrCode);

						if (!empty($this->_attributeValues[$attrCode])) {
							if ($this->_attributeTypes[$attrCode] == 'multiselect') {
								$attrValue = explode(',', $attrValue);
								$attrValue = array_intersect_key(
									$this->_attributeValues[$attrCode],
									array_flip($attrValue)
								);
								$rowMultiselects[$itemId][$attrCode] = $attrValue;
							} else if (isset($this->_attributeValues[$attrCode][$attrValue])) {
								$attrValue = $this->_attributeValues[$attrCode][$attrValue];
							} else {
								$attrValue = null;
							}
						}
						// do not save value same as default or not existent
						if ($storeId != $defaultStoreId
							&& isset($dataRows[$itemId][$defaultStoreId][$attrCode])
							&& $dataRows[$itemId][$defaultStoreId][$attrCode] == $attrValue
						) {
							$attrValue = null;
						}
						if (is_scalar($attrValue)) {
							$dataRows[$itemId][$storeId][$attrCode] = $attrValue;
							$rowIsEmpty = false; // mark row as not empty
						}
					}
					if ($rowIsEmpty) { // remove empty rows
						unset($dataRows[$itemId][$storeId]);
					} else {
						$attrSetId = $item->getEntityTypeId();//$item->getAttributeSetId();
						if(isset($this->_attrSetIdToName[$attrSetId])){
							$dataRows[$itemId][$storeId][self::COL_STORE] = $storeCode;
							$dataRows[$itemId][$storeId][self::COL_ATTR_SET] = $this->_attrSetIdToName[$attrSetId];
						}else{
							var_dump($item);
							var_dump($attrSetId);
							var_dump($this->_attrSetIdToName);die("here");
						}
						if ($defaultStoreId == $storeId) {
							$rowWebsites[$itemId] = $item->getWebsites();
						}
					}
					$item = null;
				}
				$collection->clear();
			}

			if ($collection->getCurPage() < $offsetEntities) {
				break;
			}

			if ($offsetEntities == 1) {
				// create export file
				$headerCols = array_merge(
					array(
						self::COL_STORE, self::COL_ATTR_SET
					),
					$validAttrCodes
				);

				$writer->setHeaderCols($headerCols);
			}

			foreach ($dataRows as $entityId => &$entityData) {

				foreach ($entityData as $storeId => &$dataRow) {
					if ($defaultStoreId != $storeId) {
						$dataRow[self::COL_ATTR_SET] = null;
					} else {
						$dataRow[self::COL_STORE] = null;
					}

					if ($rowWebsites[$entityId]) {
						$dataRow['_entity_websites'] = $this->_websiteIdToCode[array_shift($rowWebsites[$entityId])];
					}

					if (!empty($rowMultiselects[$entityId])) {
						foreach ($rowMultiselects[$entityId] as $attrKey => $attrVal) {
							if (!empty($rowMultiselects[$entityId][$attrKey])) {
								$dataRow[$attrKey] = array_shift($rowMultiselects[$entityId][$attrKey]);
							}
						}
					}

					$writer->writeRow($dataRow);
				}


				/*$additionalRowsCount = max(
					count($rowWebsites[$entityId])
				);*/
				$additionalRowsCount = count($rowWebsites[$entityId]);
				if (!empty($rowMultiselects[$entityId])) {
					foreach ($rowMultiselects[$entityId] as $attributes) {
						$additionalRowsCount = max($additionalRowsCount, count($attributes));
					}
				}

				if ($additionalRowsCount) {
					for ($i = 0; $i < $additionalRowsCount; $i++) {
						$dataRow = array();

						if ($rowWebsites[$entityId]) {
							$dataRow['_entity_websites'] = $this
								->_websiteIdToCode[array_shift($rowWebsites[$entityId])];
						}
						if (!empty($rowMultiselects[$entityId])) {
							foreach ($rowMultiselects[$entityId] as $attrKey => $attrVal) {
								if (!empty($rowMultiselects[$entityId][$attrKey])) {
									$dataRow[$attrKey] = array_shift($rowMultiselects[$entityId][$attrKey]);
								}
							}
						}
						$writer->writeRow($dataRow);
					}
				}
			}
		}
		
		return $writer->getContents();
	}

	/**
	 * Entity attributes collection getter.
	 *
	 * @return Mage_Eav_Model_Resource_Entity_Attribute_Collection
	 */
	public function getAttributeCollection() {
		return Mage::getResourceModel('catalog/category_attribute_collection');
	}

	/**
	 * EAV entity type code getter.
	 *
	 * @return string
	 */
	public function getEntityTypeCode() {
		return 'catalog_category';
	}
}