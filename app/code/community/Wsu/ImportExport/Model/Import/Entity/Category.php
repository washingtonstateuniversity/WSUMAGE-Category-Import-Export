<?php

class Wsu_ImportExport_Model_Import_Entity_Category extends Mage_ImportExport_Model_Import_Entity_Abstract {

    /**
     * Permanent column names.
     *
     * Names that begins with underscore is not an attribute. This name convention is for
     * to avoid interference with same attribute name.
     */
    const COL_STORE    = '_store';

    /**
     * Col Identifier
     */
    const COL_IDENTIFIER      = 'identifier';

    /**
     * Col Attr Set
     */
    const COL_ATTR_SET = '_attribute_set';

    /**
     * Parent column
     */
    const COL_PARENT = '_parent';

    /**
     * Default Scope
     */
    const SCOPE_DEFAULT = 1;

    /**
     * Website Scope
     */
    const SCOPE_WEBSITE = 2;

    /**
     * Store Scope
     */
    const SCOPE_STORE   = 0;

    /**
     * Null Scope
     */
    const SCOPE_NULL    = -1;

    /**
     * Error - duplicate identifier
     */
    const ERROR_DUPLICATE_IDENTIFIER                = 'duplicateIdentifier';

    /**
     * Error - identifier not found for delete
     */
    const ERROR_IDENTIFIER_NOT_FOUND_FOR_DELETE     = 'identifierNotFoundToDelete';

    /**
     * Error - invalid attr set
     */
    const ERROR_INVALID_ATTR_SET             = 'invalidAttrSet';

    /**
     * Error - identifier is empty
     */
    const ERROR_IDENTIFIER_IS_EMPTY          = 'identifierEmpty';

    /**
     * Error - row is orphan
     */
    const ERROR_ROW_IS_ORPHAN                = 'rowIsOrphan';

    /**
     * Error - invalid store
     */
    const ERROR_INVALID_STORE                = 'invalidStore';


    /**
     * Error - value is required
     */
    const ERROR_VALUE_IS_REQUIRED            = 'isRequired';

    /**
     * Error - super categories identifier not found
     */
    const ERROR_PARENT_IDENTIFIER_NOT_FOUND = 'parentIdentifierNotFound';


    /**
     * Pairs of attribute set ID-to-name.
     *
     * @var array
     */
    protected $_attrSetIdToName = array();

    /**
     * Pairs of attribute set name-to-ID.
     *
     * @var array
     */
    protected $_attrSetNameToId = array();


    /**
     * @var
     */
    protected $_defaultAttributeSet;


    /**
     * @var
     */
    protected $_defaultCategoryIdentifier;

    /**
     * Dry-runned categories information from import file.
     *
     * [identifier] => array(
     *     'attr_set_id'    => (int) category attribute set ID
     *     'entity_id'      => (int) category ID (value for new categories will be set after entity save)
     *     'attr_set_code'  => (string) attribute set code
     * )
     *
     * @var array
     */
    protected $_newIdentifiers = array();

    /**
     * Existing categories identifier-related information in form of array:
     *
     * [identifier] => array(
     *     'type_id'        => (string) category type
     *     'attr_set_id'    => (int) category attribute set ID
     *     'entity_id'      => (int) category ID
     * )
     *
     * @var array
     */
    protected $_oldIdentifiers = array();

    /**
     * Column names that holds values with particular meaning.
     *
     * @var array
     */
    protected $_particularAttributes = array(
        self::COL_STORE, self::COL_ATTR_SET, self::COL_PARENT
    );

    /**
     * All stores code-ID pairs.
     *
     * @var array
     */
    protected $_storeCodeToId = array();

    /**
     * Store ID to its website stores IDs.
     *
     * @var array
     */
    protected $_storeIdToWebsiteStoreIds = array();

    /**
     * Website code-to-ID
     *
     * @var array
     */
    protected $_websiteCodeToId = array();

    /**
     * Website code to store code-to-ID pairs which it consists.
     *
     * @var array
     */
    protected $_websiteCodeToStoreIds = array();

    /**
     * Category type attribute sets and attributes parameters.
     *
     * [attr_set_name_1] => array(
     *     [attr_code_1] => array(
     *         'options' => array(),
     *         'type' => 'text', 'price', 'textarea', 'select', etc.
     *         'id' => ..
     *     ),
     *     ...
     * ),
     * ...
     *
     * @var array
     */
    protected $_attributes = array();

    /**
     * Validation failure message template definitions
     *
     * @var array
     */
    protected $_messageTemplates = array(
        self::ERROR_INVALID_STORE                => 'Invalid value in Store column (store does not exists?)',
        self::ERROR_INVALID_ATTR_SET             => 'Invalid value for Attribute Set column (set does not exists?)',
        self::ERROR_VALUE_IS_REQUIRED            => "Required attribute '%s' has an empty value",
        self::ERROR_IDENTIFIER_IS_EMPTY          => 'Identifier is empty',
        self::ERROR_DUPLICATE_IDENTIFIER         => 'Duplicate identifier',
        self::ERROR_ROW_IS_ORPHAN                => 'Orphan rows that will be skipped due default row errors',
        self::ERROR_IDENTIFIER_NOT_FOUND_FOR_DELETE     => 'Category with specified identifier not found'
    );

    /**
     * Constructor.
     *
     */
    public function __construct() {
        parent::__construct();

        $defaultAttributeSetId = Mage::getSingleton('catalog/category')->getDefaultAttributeSetId();
        $this->_defaultAttributeSet = Mage::getSingleton('eav/entity_attribute_set')
            ->load($defaultAttributeSetId)
            ->getAttributeSetName();


        $defaultCategoryId = Mage::app()->getDefaultStoreView()->getRootCategoryId();
        $this->_defaultCategoryIdentifier = Mage::getModel('catalog/category')
            ->load($defaultCategoryId)
            ->getIdentifier();

        $this->_initWebsites()
            ->_initStores()
            ->_initAttributes()
            ->_initAttributeSets()
            ->_initIdentifiers();
    }


    /**
     * Initialize existent category identifiers.
     *
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _initIdentifiers() {
        $columns = array('entity_id', 'attribute_set_id', 'identifier');
        $resource = Mage::getModel('catalog/category')->getResource();
        $entitiesInfo = $resource
            ->getReadConnection()->select()
            ->from($resource->getTable('catalog/category'), $columns);
        foreach ($resource->getReadConnection()->fetchAll($entitiesInfo) as $info) {
            $identifier = $info['identifier'];
            $this->_oldIdentifiers[$identifier] = array(
                'attr_set_id'    => $info['attribute_set_id'],
                'entity_id'      => $info['entity_id']
            );
        }
        return $this;
    }

    /**
     * Initialize attributes parameters for all attributes' sets.
     *
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _initAttributes() {
        // temporary storage for attributes' parameters to avoid double querying inside the loop
        $attributesCache = array();

        foreach (Mage::getResourceModel('eav/entity_attribute_set_collection')
                     ->setEntityTypeFilter($this->getEntityTypeId()) as $attributeSet) {
            /** @var Mage_Eav_Model_Attribute $attribute */
            foreach (Mage::getResourceModel('catalog/category_attribute_collection')
                         ->setAttributeSetFilter($attributeSet->getId()) as $attribute) {

                $attributeCode = $attribute->getAttributeCode();
                $attributeId   = $attribute->getId();

                if ($attribute->getIsVisible()) {
                    if (!isset($attributesCache[$attributeId])) {
                        $attributesCache[$attributeId] = array(
                            'id'               => $attributeId,
                            'code'             => $attributeCode,
                            'is_global'        => $attribute->getIsGlobal(),
                            'is_required'      => $attribute->getIsRequired(),
                            'is_unique'        => $attribute->getIsUnique(),
                            'frontend_label'   => $attribute->getFrontendLabel(),
                            'is_static'        => $attribute->isStatic(),
                            'type'             => Mage_ImportExport_Model_Import::getAttributeType($attribute),
                            'default_value'    => strlen($attribute->getDefaultValue())
                                ? $attribute->getDefaultValue() : null,
                            'options'          => $this->getAttributeOptions($attribute, $this->_indexValueAttributes)
                        );
                    }
                    $this->_addAttributeParams($attributeSet->getAttributeSetName(), $attributesCache[$attributeId]);
                }
            }
        }
        return $this;
    }

    /**
     * Add attribute parameters to appropriate attribute set.
     *
     * @param string $attrSetName Name of attribute set.
     * @param array $attrParams Refined attribute parameters.
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _addAttributeParams($attrSetName, array $attrParams) {
        $this->_attributes[$attrSetName][$attrParams['code']] = $attrParams;
        return $this;
    }

    /**
     * Initialize attribute sets code-to-id pairs.
     *
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _initAttributeSets()
    {
        foreach (Mage::getResourceModel('eav/entity_attribute_set_collection')
                     ->setEntityTypeFilter($this->_entityTypeId) as $attributeSet) {
            $this->_attrSetNameToId[$attributeSet->getAttributeSetName()] = $attributeSet->getId();
            $this->_attrSetIdToName[$attributeSet->getId()] = $attributeSet->getAttributeSetName();
        }
        return $this;
    }

    /**
     * Initialize stores hash.
     *
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _initStores() {
        foreach (Mage::app()->getStores() as $store) {
            $this->_storeCodeToId[$store->getCode()] = $store->getId();
            $this->_storeIdToWebsiteStoreIds[$store->getId()] = $store->getWebsite()->getStoreIds();
        }
        return $this;
    }


    /**
     * Initialize website values.
     *
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _initWebsites() {
        /** @var $website Mage_Core_Model_Website */
        foreach (Mage::app()->getWebsites() as $website) {
            $this->_websiteCodeToId[$website->getCode()] = $website->getId();
            $this->_websiteCodeToStoreIds[$website->getCode()] = array_flip($website->getStoreCodes());
        }
        return $this;
    }


    /**
     * Import data rows.
     *
     * @return boolean
     */
    protected function _importData() {
        if (Mage_ImportExport_Model_Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            $this->_deleteCategories();
        } else {
            $this->_saveCategories();
            $this->_savePaths();
        }
        Mage::dispatchEvent('catalog_category_import_finish_before', array('adapter' => $this));
        return true;
    }

    /**
     * Gather and save information about category entities.
     *
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _saveCategories() {
        $categoryLimit = null;
        $categoryQty = null;

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entityRowsIn = array();
            $entityRowsUp = array();
            $attributes = array();
            $websites = array();
            $previousType = null;
            $previousAttributeSet = null;

            foreach ($bunch as $rowNum => $rowData) {
                $this->_filterRowData($rowData);
                if (!$this->validateRow($rowData, $rowNum)) {
                    continue;
                }
                $rowScope = $this->getRowScope($rowData);

                if (self::SCOPE_DEFAULT == $rowScope) {
                    $rowIdentifier = $rowData[self::COL_IDENTIFIER];

                    // 1. Entity phase
                    if (isset($this->_oldIdentifiers[$rowIdentifier])) { // existing row
                        $entityRowsUp[] = array(
                            'updated_at' => now(),
                            'entity_id' => $this->_oldIdentifiers[$rowIdentifier]['entity_id']
                        );
                    } else { // new row
                        if (!$categoryLimit || $categoryQty < $categoryLimit) {
                            $entityRowsIn[$rowIdentifier] = array(
                                'entity_type_id'   => $this->_entityTypeId,
                                'attribute_set_id' => $this->_newIdentifiers[$rowIdentifier]['attr_set_id'],
                                'identifier' => $rowIdentifier,
                                'created_at' => now(),
                                'updated_at' => now()
                            );
                            $categoryQty++;
                        } else {
                            $rowIdentifier = null; // sign for child rows to be skipped
                            $this->_rowsToSkip[$rowNum] = true;
                            continue;
                        }
                    }
                } elseif (null === $rowIdentifier) {
                    $this->_rowsToSkip[$rowNum] = true;
                    continue; // skip rows when identifier is NULL
                } elseif (self::SCOPE_STORE == $rowScope) { // set necessary data from SCOPE_DEFAULT row
                    $rowData['attribute_set_id'] = $this->_newIdentifiers[$rowIdentifier]['attr_set_id'];
                    $rowData[self::COL_ATTR_SET] = $this->_newIdentifiers[$rowIdentifier]['attr_set_code'];
                }
                // 6. Attributes phase
                $rowStore = self::SCOPE_STORE == $rowScope ? $this->_storeCodeToId[$rowData[self::COL_STORE]] : 0;

                if (!isset($rowData[self::COL_ATTR_SET]) && !is_null($rowData[self::COL_ATTR_SET])) {
                    $previousAttributeSet = $rowData[Wsu_ImportExport_Model_Import_Entity_Category::COL_ATTR_SET];
                }
                if (self::SCOPE_NULL == $rowScope) {
                    // for multiselect attributes only
                    if (!is_null($previousAttributeSet)) {
                        $rowData[Wsu_ImportExport_Model_Import_Entity_Category::COL_ATTR_SET] = $previousAttributeSet;
                    }
                }
                $rowData = $this->prepareAttributesForSave(
                    $rowData,
                    !isset($this->_oldIdentifiers[$rowIdentifier])
                );
                try {
                    $attributes = $this->_prepareAttributes($rowData, $rowScope, $attributes, $rowIdentifier, $rowStore);
                } catch (Exception $e) {
                    Mage::logException($e);
                    continue;
                }
            }

            $this->_saveCategoryEntity($entityRowsIn, $entityRowsUp)
                ->_saveCategoryAttributes($attributes);
        }
        return $this;

    }

    /**
     * Update and insert data in entity table.
     *
     * @param array $entityRowsIn Row for insert
     * @param array $entityRowsUp Row for update
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _saveCategoryEntity(array $entityRowsIn, array $entityRowsUp) {
        static $entityTable = null;

        if (!$entityTable) {
            $entityTable = Mage::getResourceModel('catalog/category')->getEntityTable();
        }
        if ($entityRowsUp) {
            $this->_connection->insertOnDuplicate(
                $entityTable,
                $entityRowsUp,
                array('updated_at')
            );
        }
        if ($entityRowsIn) {
            $this->_connection->insertMultiple($entityTable, $entityRowsIn);

            $newCategories = $this->_connection->fetchPairs($this->_connection->select()
                    ->from($entityTable, array('identifier', 'entity_id'))
                    ->where('identifier IN (?)', array_keys($entityRowsIn))
            );
            foreach ($newCategories as $identifier => $newId) { // fill up entity_id for new categories
                $this->_newIdentifiers[$identifier]['entity_id'] = $newId;
            }
        }
        return $this;
    }

    /**
     * Save categories attributes.
     *
     * @param array $attributesData
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _saveCategoryAttributes(array $attributesData) {
        foreach ($attributesData as $tableName => $identifierData) {
            $tableData = array();

            foreach ($identifierData as $identifier => $attributes) {
                $categoryId = $this->_newIdentifiers[$identifier]['entity_id'];

                foreach ($attributes as $attributeId => $storeValues) {
                    foreach ($storeValues as $storeId => $storeValue) {
                        $tableData[] = array(
                            'entity_id' => $categoryId,
                            'entity_type_id' => $this->_entityTypeId,
                            'attribute_id' => $attributeId,
                            'store_id' => $storeId,
                            'value' => $storeValue
                        );
                    }

                    /*
                    If the store based values are not provided for a particular store,
                    we default to the default scope values.
                    In this case, remove all the existing store based values stored in the table.
                    */
                    $where = $this->_connection->quoteInto('store_id NOT IN (?)', array_keys($storeValues)) .
                        $this->_connection->quoteInto(' AND attribute_id = ?', $attributeId) .
                        $this->_connection->quoteInto(' AND entity_id = ?', $categoryId) .
                        $this->_connection->quoteInto(' AND entity_type_id = ?', $this->_entityTypeId);

                    $this->_connection->delete(
                        $tableName, $where
                    );
                }
            }
            $this->_connection->insertOnDuplicate($tableName, $tableData, array('value'));
        }
        return $this;
    }

    /**
     * Retrieve attribute by specified code
     *
     * @param string $code
     * @return Mage_Eav_Model_Entity_Attribute_Abstract
     */
    protected function _getAttribute($code) {
        $attribute = Mage::getResourceSingleton('catalog/category')->getAttribute($code);
        $backendModelName = (string)Mage::getConfig()->getNode(
            'global/importexport/import/catalog_category/attributes/' . $attribute->getAttributeCode() . '/backend_model'
        );
        if (!empty($backendModelName)) {
            $attribute->setBackendModel($backendModelName);
        }
        return $attribute;
    }

    /**
     * Prepare attributes values for save: remove non-existent, remove empty values, remove static.
     *
     * @param array $rowData
     * @param bool $withDefaultValue
     * @return array
     */
    public function prepareAttributesForSave(array $rowData, $withDefaultValue = true)
    {
        $resultAttrs = array();

        $attributes = $this->_getCategoryAttributes($rowData);

        foreach ($attributes as $attrCode => $attrParams) {
            if (!$attrParams['is_static']) {
                if (isset($rowData[$attrCode]) && strlen($rowData[$attrCode])) {
                    $resultAttrs[$attrCode] =
                        ('select' == $attrParams['type'] || 'multiselect' == $attrParams['type'])
                            ? $attrParams['options'][strtolower($rowData[$attrCode])]
                            : $rowData[$attrCode];
                } elseif ($withDefaultValue && null !== $attrParams['default_value']) {
                    $resultAttrs[$attrCode] = $attrParams['default_value'];
                }
            }
        }
        return $resultAttrs;
    }

    /**
     * Prepare attributes data
     *
     * @param array $rowData
     * @param int $rowScope
     * @param array $attributes
     * @param string|null $rowIdentifier
     * @param int $rowStore
     * @return array
     */
    protected function _prepareAttributes($rowData, $rowScope, $attributes, $rowIdentifier, $rowStore) {

        /** @var Wsu_ImportExport_Model_Import_Proxy_Category $category */
        $category = Mage::getModel('wsu_importexport/import_proxy_category', $rowData);

        foreach ($rowData as $attrCode => $attrValue) {
            $attribute = $this->_getAttribute($attrCode);
            if ('multiselect' != $attribute->getFrontendInput()
                && self::SCOPE_NULL == $rowScope
            ) {
                continue; // skip attribute processing for SCOPE_NULL rows
            }
            $attrId = $attribute->getId();
            $backModel = $attribute->getBackendModel();
            $attrTable = $attribute->getBackend()->getTable();
            $storeIds = array(0);

            if ('datetime' == $attribute->getBackendType() && strtotime($attrValue)) {
                $attrValue = gmstrftime($this->_getStrftimeFormat(), strtotime($attrValue));
            } elseif ('url_key' == $attribute->getAttributeCode()) {
                if (empty($attrValue)) {
                    $attrValue = $category->formatUrlKey($category->getName());
                }
            } elseif ($backModel) {
                $attribute->getBackend()->beforeSave($category);
                $attrValue = $category->getData($attribute->getAttributeCode());
            }
            if (self::SCOPE_STORE == $rowScope) {
                if (self::SCOPE_WEBSITE == $attribute->getIsGlobal()) {
                    // check website defaults already set
                    if (!isset($attributes[$attrTable][$rowIdentifier][$attrId][$rowStore])) {
                        $storeIds = $this->_storeIdToWebsiteStoreIds[$rowStore];
                    }
                } elseif (self::SCOPE_STORE == $attribute->getIsGlobal()) {
                    $storeIds = array($rowStore);
                }
            }
            foreach ($storeIds as $storeId) {
                if ('multiselect' == $attribute->getFrontendInput()) {
                    if (!isset($attributes[$attrTable][$rowIdentifier][$attrId][$storeId])) {
                        $attributes[$attrTable][$rowIdentifier][$attrId][$storeId] = '';
                    } else {
                        $attributes[$attrTable][$rowIdentifier][$attrId][$storeId] .= ',';
                    }
                    $attributes[$attrTable][$rowIdentifier][$attrId][$storeId] .= $attrValue;
                } else {
                    $attributes[$attrTable][$rowIdentifier][$attrId][$storeId] = $attrValue;
                }
            }
            $attribute->setBackendModel($backModel); // restore 'backend_model' to avoid 'default' setting
        }
        return $attributes;
    }


    /**
     * Removes empty keys in case value is null or empty string
     *
     * @param array $rowData
     */
    protected function _filterRowData(&$rowData) {
        $rowData = array_filter($rowData, 'strlen');
        // Exceptions - for identifier - put them back in
        if (!isset($rowData[self::COL_IDENTIFIER])) {
            $rowData[self::COL_IDENTIFIER] = null;
        }
    }

    /**
     * Obtain scope of the row from row data.
     *
     * @param array $rowData
     * @return int
     */
    public function getRowScope(array $rowData) {
        if (isset($rowData[self::COL_IDENTIFIER]) && strlen(trim($rowData[self::COL_IDENTIFIER]))) {
            return self::SCOPE_DEFAULT;
        } elseif (empty($rowData[self::COL_STORE])) {
            return self::SCOPE_NULL;
        } else {
            return self::SCOPE_STORE;
        }
    }

    /**
     * EAV entity type code getter.
     *
     * @return string
     */
    public function getEntityTypeCode() {
        return 'catalog_category';
    }

    /**
     * Common validation
     *
     * @param array $rowData
     * @param int $rowNum
     */
    protected function _validate($rowData, $rowNum) {
        $this->_isParentIdentifierValid($rowData, $rowNum);
    }



    /**
     * Validate data row.
     *
     * @param array $rowData
     * @param int $rowNum
     * @return boolean
     */
    public function validateRow(array $rowData, $rowNum) {
        static $identifier = null; // Identifier is remembered through all category rows

        if (isset($this->_validatedRows[$rowNum])) { // check that row is already validated
            return !isset($this->_invalidRows[$rowNum]);
        }
        $this->_validatedRows[$rowNum] = true;

        if (isset($this->_newIdentifiers[$rowData[self::COL_IDENTIFIER]])) {
            $this->addRowError(self::ERROR_DUPLICATE_IDENTIFIER, $rowNum);
            return false;
        }
        $rowScope = $this->getRowScope($rowData);

        // BEHAVIOR_DELETE use specific validation logic
        if (Mage_ImportExport_Model_Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            if (self::SCOPE_DEFAULT == $rowScope && !isset($this->_oldIdentifiers[$rowData[self::COL_IDENTIFIER]])) {
                $this->addRowError(self::ERROR_IDENTIFIER_NOT_FOUND_FOR_DELETE, $rowNum);
                return false;
            }
            return true;
        }

        $this->_validate($rowData, $rowNum, $identifier);

        if (self::SCOPE_DEFAULT == $rowScope) { // Identifier is specified, row is SCOPE_DEFAULT, new category block begins
            $this->_processedEntitiesCount ++;

            $identifier = $rowData[self::COL_IDENTIFIER];

            if (isset($this->_oldIdentifiers[$identifier])) { // can we get all necessary data from existant DB category?
                $this->_newIdentifiers[$identifier] = array(
                    'entity_id'     => $this->_oldIdentifiers[$identifier]['entity_id'],
                    'attr_set_id'   => $this->_oldIdentifiers[$identifier]['attr_set_id'],
                    'attr_set_code' => $this->_attrSetIdToName[$this->_oldIdentifiers[$identifier]['attr_set_id']]
                );
            } else { // validate new category type and attribute set
                if (!isset($rowData[self::COL_ATTR_SET]) ||
                    !isset($this->_attrSetNameToId[$rowData[self::COL_ATTR_SET]])
                )
                {
                    $this->addRowError(self::ERROR_INVALID_ATTR_SET, $rowNum);
                } elseif (!isset($this->_newIdentifiers[$identifier])) {
                    $this->_newIdentifiers[$identifier] = array(
                        'entity_id'     => null,
                        'attr_set_id'   => $this->_attrSetNameToId[$rowData[self::COL_ATTR_SET]],
                        'attr_set_code' => $rowData[self::COL_ATTR_SET]
                    );
                }
                if (isset($this->_invalidRows[$rowNum])) {
                    // mark SCOPE_DEFAULT row as invalid for future child rows if category not in DB already
                    $identifier = false;
                }
            }
        } else {
            if (null === $identifier) {
                $this->addRowError(self::ERROR_IDENTIFIER_IS_EMPTY, $rowNum);
            } elseif (false === $identifier) {
                $this->addRowError(self::ERROR_ROW_IS_ORPHAN, $rowNum);
            } elseif (self::SCOPE_STORE == $rowScope && !isset($this->_storeCodeToId[$rowData[self::COL_STORE]])) {
                $this->addRowError(self::ERROR_INVALID_STORE, $rowNum);
            }
        }
        if (!isset($this->_invalidRows[$rowNum])) {
            // set attribute set code into row data for followed attribute validation in type model
            $rowData[self::COL_ATTR_SET] = $this->_newIdentifiers[$identifier]['attr_set_code'];

            $rowAttributesValid = $this->isRowValid(
                $rowData, $rowNum, !isset($this->_oldIdentifiers[$identifier])
            );
            if (!$rowAttributesValid && self::SCOPE_DEFAULT == $rowScope) {
                $identifier = false; // mark SCOPE_DEFAULT row as invalid for future child rows
            }
        }
        return !isset($this->_invalidRows[$rowNum]);
    }

    /**
     * Check super categories identifier
     *
     * @param array $rowData
     * @param int $rowNum
     * @return bool
     */
    protected function _isParentIdentifierValid($rowData, $rowNum) {
        if (!empty($rowData[self::COL_PARENT])
            && (!isset($this->_oldIdentifiers[$rowData[self::COL_PARENT]])
                && !isset($this->_newIdentifiers[$rowData[self::COL_PARENT]])
            )
        ) {
            $this->addRowError(self::ERROR_PARENT_IDENTIFIER_NOT_FOUND, $rowNum);
            return false;
        }
        return true;
    }

    /**
     * Return category attributes for its attribute set specified in row data.
     *
     * @param array|string $attrSetData category row data or simply attribute set name.
     * @return array
     */
    protected function _getCategoryAttributes($attrSetData) {
        if (is_array($attrSetData)) {
            return $this->_attributes[$attrSetData[Wsu_ImportExport_Model_Import_Entity_Category::COL_ATTR_SET]];
        } else {
            return $this->_attributes[$attrSetData];
        }
    }

    /**
     * Validate row attributes. Pass VALID row data ONLY as argument.
     *
     * @param array $rowData
     * @param int $rowNum
     * @param boolean $isNewCategory OPTIONAL.
     * @return boolean
     */
    public function isRowValid(array $rowData, $rowNum, $isNewCategory = true) {
        $error    = false;
        $rowScope = $this->getRowScope($rowData);

        if (self::SCOPE_NULL != $rowScope) {
            foreach ($this->_getCategoryAttributes($rowData) as $attrCode => $attrParams) {
                // check value for non-empty in the case of required attribute?
                if (isset($rowData[$attrCode]) && strlen($rowData[$attrCode])) {
                    $error |= !$this->isAttributeValid($attrCode, $attrParams, $rowData, $rowNum);
                } elseif (
                    $this->_isAttributeRequiredCheckNeeded($attrCode)
                    && $attrParams['is_required']) {
                    // For the default scope - if this is a new category or
                    // for an old category, if the imported doc has the column present for the attrCode
                    if (Wsu_ImportExport_Model_Import_Entity_Category::SCOPE_DEFAULT == $rowScope &&
                        ($isNewCategory || array_key_exists($attrCode, $rowData))) {
                        $this->addRowError(
                            Wsu_ImportExport_Model_Import_Entity_Category::ERROR_VALUE_IS_REQUIRED,
                            $rowNum, $attrCode
                        );
                        $error = true;
                    }
                }
            }
        }
        $error |= !$this->_isParticularAttributesValid($rowData, $rowNum);

        return !$error;
    }

    /**
     * Validate particular attributes columns.
     *
     * @param array $rowData
     * @param int $rowNum
     * @return bool
     */
    protected function _isParticularAttributesValid(array $rowData, $rowNum) {
        return true;
    }

    /**
     * Have we check attribute for is_required? Used as last chance to disable this type of check.
     *
     * @param string $attrCode
     * @return bool
     */
    protected function _isAttributeRequiredCheckNeeded($attrCode) {
        return true;
    }

    /**
     * Get array of affected categories
     *
     * @return array
     */
    public function getAffectedEntityIds() {
        $categoryIds = array();
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            foreach ($bunch as $rowNum => $rowData) {
                if (!$this->isRowAllowedToImport($rowData, $rowNum)) {
                    continue;
                }
                if (!isset($this->_newIdentifiers[$rowData[self::COL_IDENTIFIER]]['entity_id'])) {
                    continue;
                }
                $categoryIds[] = $this->_newIdentifiers[$rowData[self::COL_IDENTIFIER]]['entity_id'];
            }
        }
        return $categoryIds;
    }

    /**
     * Gather and save information about category links.
     * Must be called after ALL categories saving done.
     *
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _savePaths() {

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $categoryIds   = array();

            foreach ($bunch as $rowNum => $rowData) {
                $this->_filterRowData($rowData);
                if (!$this->isRowAllowedToImport($rowData, $rowNum)) {
                    continue;
                }
                if (self::SCOPE_DEFAULT == $this->getRowScope($rowData)) {
                    $identifier = $rowData[self::COL_IDENTIFIER];
                }
                $categoryId    = $this->_newIdentifiers[$identifier]['entity_id'];
                $categoryIds[] = $categoryId;

                if(!isset($rowData[self::COL_PARENT]))
                {
                    $rowData[self::COL_PARENT] = $this->_defaultCategoryIdentifier;
                }
                $parentIdentifier = $rowData[self::COL_PARENT];

                if ((isset($this->_newIdentifiers[$parentIdentifier]) || isset($this->_oldIdentifiers[$parentIdentifier]))
                    && $parentIdentifier != $identifier) {
                    if (isset($this->_newIdentifiers[$parentIdentifier])) {
                        $linkedId = $this->_newIdentifiers[$parentIdentifier]['entity_id'];
                    } else {
                        $linkedId = $this->_oldIdentifiers[$parentIdentifier]['entity_id'];
                    }

                    $category = Mage::getModel('catalog/category')->load($categoryId);
                    $parent = Mage::getModel('catalog/category')->load($linkedId);

                    //TODO: pass in afterCategoryId?
                    //TODO: Support positioning of children
                    Mage::getResourceModel('catalog/category')->changeParent($category, $parent, null);
                }
            }
        }

        return $this;
    }

    /**
     * Set valid attribute set and category type to rows with all scopes
     * to ensure that existing categories doesn't changed.
     *
     * @param array $rowData
     * @return array
     */
    protected function _prepareRowForDb(array $rowData) {
        $rowData = parent::_prepareRowForDb($rowData);

        static $lastIdentifier  = null;

        if (Mage_ImportExport_Model_Import::BEHAVIOR_DELETE == $this->getBehavior()) {
            return $rowData;
        }
        if (self::SCOPE_DEFAULT == $this->getRowScope($rowData)) {
            $lastIdentifier = $rowData[self::COL_IDENTIFIER];
        }
        if (isset($this->_oldIdentifiers[$lastIdentifier])) {
            $rowData[self::COL_ATTR_SET] = $this->_newIdentifiers[$lastIdentifier]['attr_set_code'];
        }

        return $rowData;
    }

    /**
     * Delete categories.
     *
     * @return Wsu_ImportExport_Model_Import_Entity_Category
     */
    protected function _deleteCategories() {
        $categoryEntityTable = Mage::getResourceModel('catalog/category')->getEntityTable();

        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $idToDelete = array();

            foreach ($bunch as $rowNum => $rowData) {
                if ($this->validateRow($rowData, $rowNum) && self::SCOPE_DEFAULT == $this->getRowScope($rowData)) {
                    $idToDelete[] = $this->_oldIdentifiers[$rowData[self::COL_IDENTIFIER]]['entity_id'];
                }
            }
            if ($idToDelete) {
                $this->_connection->query(
                    $this->_connection->quoteInto(
                        "DELETE FROM `{$categoryEntityTable}` WHERE `entity_id` IN (?)", $idToDelete
                    )
                );
            }
        }
        return $this;
    }

}

