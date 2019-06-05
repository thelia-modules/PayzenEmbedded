<?php

namespace PayzenEmbedded\Model\Map;

use PayzenEmbedded\Model\PayzenEmbeddedCustomerToken;
use PayzenEmbedded\Model\PayzenEmbeddedCustomerTokenQuery;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\InstancePoolTrait;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\DataFetcher\DataFetcherInterface;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Map\RelationMap;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Map\TableMapTrait;


/**
 * This class defines the structure of the 'payzen_embedded_customer_token' table.
 *
 *
 *
 * This map class is used by Propel to do runtime db structure discovery.
 * For example, the createSelectSql() method checks the type of a given column used in an
 * ORDER BY clause to know whether it needs to apply SQL to make the ORDER BY case-insensitive
 * (i.e. if it's a text column type).
 *
 */
class PayzenEmbeddedCustomerTokenTableMap extends TableMap
{
    use InstancePoolTrait;
    use TableMapTrait;
    /**
     * The (dot-path) name of this class
     */
    const CLASS_NAME = 'PayzenEmbedded.Model.Map.PayzenEmbeddedCustomerTokenTableMap';

    /**
     * The default database name for this class
     */
    const DATABASE_NAME = 'thelia';

    /**
     * The table name for this class
     */
    const TABLE_NAME = 'payzen_embedded_customer_token';

    /**
     * The related Propel class for this table
     */
    const OM_CLASS = '\\PayzenEmbedded\\Model\\PayzenEmbeddedCustomerToken';

    /**
     * A class that can be returned by this tableMap
     */
    const CLASS_DEFAULT = 'PayzenEmbedded.Model.PayzenEmbeddedCustomerToken';

    /**
     * The total number of columns
     */
    const NUM_COLUMNS = 3;

    /**
     * The number of lazy-loaded columns
     */
    const NUM_LAZY_LOAD_COLUMNS = 0;

    /**
     * The number of columns to hydrate (NUM_COLUMNS - NUM_LAZY_LOAD_COLUMNS)
     */
    const NUM_HYDRATE_COLUMNS = 3;

    /**
     * the column name for the ID field
     */
    const ID = 'payzen_embedded_customer_token.ID';

    /**
     * the column name for the PAYMENT_TOKEN field
     */
    const PAYMENT_TOKEN = 'payzen_embedded_customer_token.PAYMENT_TOKEN';

    /**
     * the column name for the CUSTOMER_ID field
     */
    const CUSTOMER_ID = 'payzen_embedded_customer_token.CUSTOMER_ID';

    /**
     * The default string format for model objects of the related table
     */
    const DEFAULT_STRING_FORMAT = 'YAML';

    /**
     * holds an array of fieldnames
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldNames[self::TYPE_PHPNAME][0] = 'Id'
     */
    protected static $fieldNames = array (
        self::TYPE_PHPNAME       => array('Id', 'PaymentToken', 'CustomerId', ),
        self::TYPE_STUDLYPHPNAME => array('id', 'paymentToken', 'customerId', ),
        self::TYPE_COLNAME       => array(PayzenEmbeddedCustomerTokenTableMap::ID, PayzenEmbeddedCustomerTokenTableMap::PAYMENT_TOKEN, PayzenEmbeddedCustomerTokenTableMap::CUSTOMER_ID, ),
        self::TYPE_RAW_COLNAME   => array('ID', 'PAYMENT_TOKEN', 'CUSTOMER_ID', ),
        self::TYPE_FIELDNAME     => array('id', 'payment_token', 'customer_id', ),
        self::TYPE_NUM           => array(0, 1, 2, )
    );

    /**
     * holds an array of keys for quick access to the fieldnames array
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldKeys[self::TYPE_PHPNAME]['Id'] = 0
     */
    protected static $fieldKeys = array (
        self::TYPE_PHPNAME       => array('Id' => 0, 'PaymentToken' => 1, 'CustomerId' => 2, ),
        self::TYPE_STUDLYPHPNAME => array('id' => 0, 'paymentToken' => 1, 'customerId' => 2, ),
        self::TYPE_COLNAME       => array(PayzenEmbeddedCustomerTokenTableMap::ID => 0, PayzenEmbeddedCustomerTokenTableMap::PAYMENT_TOKEN => 1, PayzenEmbeddedCustomerTokenTableMap::CUSTOMER_ID => 2, ),
        self::TYPE_RAW_COLNAME   => array('ID' => 0, 'PAYMENT_TOKEN' => 1, 'CUSTOMER_ID' => 2, ),
        self::TYPE_FIELDNAME     => array('id' => 0, 'payment_token' => 1, 'customer_id' => 2, ),
        self::TYPE_NUM           => array(0, 1, 2, )
    );

    /**
     * Initialize the table attributes and columns
     * Relations are not initialized by this method since they are lazy loaded
     *
     * @return void
     * @throws PropelException
     */
    public function initialize()
    {
        // attributes
        $this->setName('payzen_embedded_customer_token');
        $this->setPhpName('PayzenEmbeddedCustomerToken');
        $this->setClassName('\\PayzenEmbedded\\Model\\PayzenEmbeddedCustomerToken');
        $this->setPackage('PayzenEmbedded.Model');
        $this->setUseIdGenerator(true);
        // columns
        $this->addPrimaryKey('ID', 'Id', 'INTEGER', true, null, null);
        $this->addColumn('PAYMENT_TOKEN', 'PaymentToken', 'LONGVARCHAR', false, null, null);
        $this->addForeignKey('CUSTOMER_ID', 'CustomerId', 'INTEGER', 'customer', 'ID', true, null, null);
    } // initialize()

    /**
     * Build the RelationMap objects for this table relationships
     */
    public function buildRelations()
    {
        $this->addRelation('Customer', '\\Thelia\\Model\\Customer', RelationMap::MANY_TO_ONE, array('customer_id' => 'id', ), 'CASCADE', 'RESTRICT');
    } // buildRelations()

    /**
     * Retrieves a string version of the primary key from the DB resultset row that can be used to uniquely identify a row in this table.
     *
     * For tables with a single-column primary key, that simple pkey value will be returned.  For tables with
     * a multi-column primary key, a serialize()d version of the primary key will be returned.
     *
     * @param array  $row       resultset row.
     * @param int    $offset    The 0-based offset for reading from the resultset row.
     * @param string $indexType One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_STUDLYPHPNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM
     */
    public static function getPrimaryKeyHashFromRow($row, $offset = 0, $indexType = TableMap::TYPE_NUM)
    {
        // If the PK cannot be derived from the row, return NULL.
        if ($row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('Id', TableMap::TYPE_PHPNAME, $indexType)] === null) {
            return null;
        }

        return (string) $row[TableMap::TYPE_NUM == $indexType ? 0 + $offset : static::translateFieldName('Id', TableMap::TYPE_PHPNAME, $indexType)];
    }

    /**
     * Retrieves the primary key from the DB resultset row
     * For tables with a single-column primary key, that simple pkey value will be returned.  For tables with
     * a multi-column primary key, an array of the primary key columns will be returned.
     *
     * @param array  $row       resultset row.
     * @param int    $offset    The 0-based offset for reading from the resultset row.
     * @param string $indexType One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_STUDLYPHPNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM
     *
     * @return mixed The primary key of the row
     */
    public static function getPrimaryKeyFromRow($row, $offset = 0, $indexType = TableMap::TYPE_NUM)
    {

            return (int) $row[
                            $indexType == TableMap::TYPE_NUM
                            ? 0 + $offset
                            : self::translateFieldName('Id', TableMap::TYPE_PHPNAME, $indexType)
                        ];
    }

    /**
     * The class that the tableMap will make instances of.
     *
     * If $withPrefix is true, the returned path
     * uses a dot-path notation which is translated into a path
     * relative to a location on the PHP include_path.
     * (e.g. path.to.MyClass -> 'path/to/MyClass.php')
     *
     * @param boolean $withPrefix Whether or not to return the path with the class name
     * @return string path.to.ClassName
     */
    public static function getOMClass($withPrefix = true)
    {
        return $withPrefix ? PayzenEmbeddedCustomerTokenTableMap::CLASS_DEFAULT : PayzenEmbeddedCustomerTokenTableMap::OM_CLASS;
    }

    /**
     * Populates an object of the default type or an object that inherit from the default.
     *
     * @param array  $row       row returned by DataFetcher->fetch().
     * @param int    $offset    The 0-based offset for reading from the resultset row.
     * @param string $indexType The index type of $row. Mostly DataFetcher->getIndexType().
                                 One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_STUDLYPHPNAME
     *                           TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM.
     *
     * @throws PropelException Any exceptions caught during processing will be
     *         rethrown wrapped into a PropelException.
     * @return array (PayzenEmbeddedCustomerToken object, last column rank)
     */
    public static function populateObject($row, $offset = 0, $indexType = TableMap::TYPE_NUM)
    {
        $key = PayzenEmbeddedCustomerTokenTableMap::getPrimaryKeyHashFromRow($row, $offset, $indexType);
        if (null !== ($obj = PayzenEmbeddedCustomerTokenTableMap::getInstanceFromPool($key))) {
            // We no longer rehydrate the object, since this can cause data loss.
            // See http://www.propelorm.org/ticket/509
            // $obj->hydrate($row, $offset, true); // rehydrate
            $col = $offset + PayzenEmbeddedCustomerTokenTableMap::NUM_HYDRATE_COLUMNS;
        } else {
            $cls = PayzenEmbeddedCustomerTokenTableMap::OM_CLASS;
            $obj = new $cls();
            $col = $obj->hydrate($row, $offset, false, $indexType);
            PayzenEmbeddedCustomerTokenTableMap::addInstanceToPool($obj, $key);
        }

        return array($obj, $col);
    }

    /**
     * The returned array will contain objects of the default type or
     * objects that inherit from the default.
     *
     * @param DataFetcherInterface $dataFetcher
     * @return array
     * @throws PropelException Any exceptions caught during processing will be
     *         rethrown wrapped into a PropelException.
     */
    public static function populateObjects(DataFetcherInterface $dataFetcher)
    {
        $results = array();

        // set the class once to avoid overhead in the loop
        $cls = static::getOMClass(false);
        // populate the object(s)
        while ($row = $dataFetcher->fetch()) {
            $key = PayzenEmbeddedCustomerTokenTableMap::getPrimaryKeyHashFromRow($row, 0, $dataFetcher->getIndexType());
            if (null !== ($obj = PayzenEmbeddedCustomerTokenTableMap::getInstanceFromPool($key))) {
                // We no longer rehydrate the object, since this can cause data loss.
                // See http://www.propelorm.org/ticket/509
                // $obj->hydrate($row, 0, true); // rehydrate
                $results[] = $obj;
            } else {
                $obj = new $cls();
                $obj->hydrate($row);
                $results[] = $obj;
                PayzenEmbeddedCustomerTokenTableMap::addInstanceToPool($obj, $key);
            } // if key exists
        }

        return $results;
    }
    /**
     * Add all the columns needed to create a new object.
     *
     * Note: any columns that were marked with lazyLoad="true" in the
     * XML schema will not be added to the select list and only loaded
     * on demand.
     *
     * @param Criteria $criteria object containing the columns to add.
     * @param string   $alias    optional table alias
     * @throws PropelException Any exceptions caught during processing will be
     *         rethrown wrapped into a PropelException.
     */
    public static function addSelectColumns(Criteria $criteria, $alias = null)
    {
        if (null === $alias) {
            $criteria->addSelectColumn(PayzenEmbeddedCustomerTokenTableMap::ID);
            $criteria->addSelectColumn(PayzenEmbeddedCustomerTokenTableMap::PAYMENT_TOKEN);
            $criteria->addSelectColumn(PayzenEmbeddedCustomerTokenTableMap::CUSTOMER_ID);
        } else {
            $criteria->addSelectColumn($alias . '.ID');
            $criteria->addSelectColumn($alias . '.PAYMENT_TOKEN');
            $criteria->addSelectColumn($alias . '.CUSTOMER_ID');
        }
    }

    /**
     * Returns the TableMap related to this object.
     * This method is not needed for general use but a specific application could have a need.
     * @return TableMap
     * @throws PropelException Any exceptions caught during processing will be
     *         rethrown wrapped into a PropelException.
     */
    public static function getTableMap()
    {
        return Propel::getServiceContainer()->getDatabaseMap(PayzenEmbeddedCustomerTokenTableMap::DATABASE_NAME)->getTable(PayzenEmbeddedCustomerTokenTableMap::TABLE_NAME);
    }

    /**
     * Add a TableMap instance to the database for this tableMap class.
     */
    public static function buildTableMap()
    {
      $dbMap = Propel::getServiceContainer()->getDatabaseMap(PayzenEmbeddedCustomerTokenTableMap::DATABASE_NAME);
      if (!$dbMap->hasTable(PayzenEmbeddedCustomerTokenTableMap::TABLE_NAME)) {
        $dbMap->addTableObject(new PayzenEmbeddedCustomerTokenTableMap());
      }
    }

    /**
     * Performs a DELETE on the database, given a PayzenEmbeddedCustomerToken or Criteria object OR a primary key value.
     *
     * @param mixed               $values Criteria or PayzenEmbeddedCustomerToken object or primary key or array of primary keys
     *              which is used to create the DELETE statement
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
     *                if supported by native driver or if emulated using Propel.
     * @throws PropelException Any exceptions caught during processing will be
     *         rethrown wrapped into a PropelException.
     */
     public static function doDelete($values, ConnectionInterface $con = null)
     {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(PayzenEmbeddedCustomerTokenTableMap::DATABASE_NAME);
        }

        if ($values instanceof Criteria) {
            // rename for clarity
            $criteria = $values;
        } elseif ($values instanceof \PayzenEmbedded\Model\PayzenEmbeddedCustomerToken) { // it's a model object
            // create criteria based on pk values
            $criteria = $values->buildPkeyCriteria();
        } else { // it's a primary key, or an array of pks
            $criteria = new Criteria(PayzenEmbeddedCustomerTokenTableMap::DATABASE_NAME);
            $criteria->add(PayzenEmbeddedCustomerTokenTableMap::ID, (array) $values, Criteria::IN);
        }

        $query = PayzenEmbeddedCustomerTokenQuery::create()->mergeWith($criteria);

        if ($values instanceof Criteria) { PayzenEmbeddedCustomerTokenTableMap::clearInstancePool();
        } elseif (!is_object($values)) { // it's a primary key, or an array of pks
            foreach ((array) $values as $singleval) { PayzenEmbeddedCustomerTokenTableMap::removeInstanceFromPool($singleval);
            }
        }

        return $query->delete($con);
    }

    /**
     * Deletes all rows from the payzen_embedded_customer_token table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public static function doDeleteAll(ConnectionInterface $con = null)
    {
        return PayzenEmbeddedCustomerTokenQuery::create()->doDeleteAll($con);
    }

    /**
     * Performs an INSERT on the database, given a PayzenEmbeddedCustomerToken or Criteria object.
     *
     * @param mixed               $criteria Criteria or PayzenEmbeddedCustomerToken object containing data that is used to create the INSERT statement.
     * @param ConnectionInterface $con the ConnectionInterface connection to use
     * @return mixed           The new primary key.
     * @throws PropelException Any exceptions caught during processing will be
     *         rethrown wrapped into a PropelException.
     */
    public static function doInsert($criteria, ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(PayzenEmbeddedCustomerTokenTableMap::DATABASE_NAME);
        }

        if ($criteria instanceof Criteria) {
            $criteria = clone $criteria; // rename for clarity
        } else {
            $criteria = $criteria->buildCriteria(); // build Criteria from PayzenEmbeddedCustomerToken object
        }

        if ($criteria->containsKey(PayzenEmbeddedCustomerTokenTableMap::ID) && $criteria->keyContainsValue(PayzenEmbeddedCustomerTokenTableMap::ID) ) {
            throw new PropelException('Cannot insert a value for auto-increment primary key ('.PayzenEmbeddedCustomerTokenTableMap::ID.')');
        }


        // Set the correct dbName
        $query = PayzenEmbeddedCustomerTokenQuery::create()->mergeWith($criteria);

        try {
            // use transaction because $criteria could contain info
            // for more than one table (I guess, conceivably)
            $con->beginTransaction();
            $pk = $query->doInsert($con);
            $con->commit();
        } catch (PropelException $e) {
            $con->rollBack();
            throw $e;
        }

        return $pk;
    }

} // PayzenEmbeddedCustomerTokenTableMap
// This is the static code needed to register the TableMap for this table with the main Propel class.
//
PayzenEmbeddedCustomerTokenTableMap::buildTableMap();
