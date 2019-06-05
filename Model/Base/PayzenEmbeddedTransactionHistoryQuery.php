<?php

namespace PayzenEmbedded\Model\Base;

use \Exception;
use \PDO;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistory as ChildPayzenEmbeddedTransactionHistory;
use PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery as ChildPayzenEmbeddedTransactionHistoryQuery;
use PayzenEmbedded\Model\Map\PayzenEmbeddedTransactionHistoryTableMap;
use Propel\Runtime\Propel;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveQuery\ModelJoin;
use Propel\Runtime\Collection\Collection;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;
use Thelia\Model\Admin;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\Order;

/**
 * Base class that represents a query for the 'payzen_embedded_transaction_history' table.
 *
 *
 *
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderById($order = Criteria::ASC) Order by the id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByCustomerId($order = Criteria::ASC) Order by the customer_id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByOrderId($order = Criteria::ASC) Order by the order_id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByAdminId($order = Criteria::ASC) Order by the admin_id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByUuid($order = Criteria::ASC) Order by the uuid column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByStatus($order = Criteria::ASC) Order by the status column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByDetailedstatus($order = Criteria::ASC) Order by the detailedStatus column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByAmount($order = Criteria::ASC) Order by the amount column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByCurrencyId($order = Criteria::ASC) Order by the currency_id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByCreationdate($order = Criteria::ASC) Order by the creationDate column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByErrorcode($order = Criteria::ASC) Order by the errorCode column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByErrormessage($order = Criteria::ASC) Order by the errorMessage column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByDetailederrorcode($order = Criteria::ASC) Order by the detailedErrorCode column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByDetailederrormessage($order = Criteria::ASC) Order by the detailedErrorMessage column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByFinished($order = Criteria::ASC) Order by the finished column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByCreatedAt($order = Criteria::ASC) Order by the created_at column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery orderByUpdatedAt($order = Criteria::ASC) Order by the updated_at column
 *
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupById() Group by the id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByCustomerId() Group by the customer_id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByOrderId() Group by the order_id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByAdminId() Group by the admin_id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByUuid() Group by the uuid column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByStatus() Group by the status column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByDetailedstatus() Group by the detailedStatus column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByAmount() Group by the amount column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByCurrencyId() Group by the currency_id column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByCreationdate() Group by the creationDate column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByErrorcode() Group by the errorCode column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByErrormessage() Group by the errorMessage column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByDetailederrorcode() Group by the detailedErrorCode column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByDetailederrormessage() Group by the detailedErrorMessage column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByFinished() Group by the finished column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByCreatedAt() Group by the created_at column
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery groupByUpdatedAt() Group by the updated_at column
 *
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery leftJoinCustomer($relationAlias = null) Adds a LEFT JOIN clause to the query using the Customer relation
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery rightJoinCustomer($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Customer relation
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery innerJoinCustomer($relationAlias = null) Adds a INNER JOIN clause to the query using the Customer relation
 *
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery leftJoinOrder($relationAlias = null) Adds a LEFT JOIN clause to the query using the Order relation
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery rightJoinOrder($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Order relation
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery innerJoinOrder($relationAlias = null) Adds a INNER JOIN clause to the query using the Order relation
 *
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery leftJoinAdmin($relationAlias = null) Adds a LEFT JOIN clause to the query using the Admin relation
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery rightJoinAdmin($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Admin relation
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery innerJoinAdmin($relationAlias = null) Adds a INNER JOIN clause to the query using the Admin relation
 *
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery leftJoinCurrency($relationAlias = null) Adds a LEFT JOIN clause to the query using the Currency relation
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery rightJoinCurrency($relationAlias = null) Adds a RIGHT JOIN clause to the query using the Currency relation
 * @method     ChildPayzenEmbeddedTransactionHistoryQuery innerJoinCurrency($relationAlias = null) Adds a INNER JOIN clause to the query using the Currency relation
 *
 * @method     ChildPayzenEmbeddedTransactionHistory findOne(ConnectionInterface $con = null) Return the first ChildPayzenEmbeddedTransactionHistory matching the query
 * @method     ChildPayzenEmbeddedTransactionHistory findOneOrCreate(ConnectionInterface $con = null) Return the first ChildPayzenEmbeddedTransactionHistory matching the query, or a new ChildPayzenEmbeddedTransactionHistory object populated from the query conditions when no match is found
 *
 * @method     ChildPayzenEmbeddedTransactionHistory findOneById(int $id) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the id column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByCustomerId(int $customer_id) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the customer_id column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByOrderId(int $order_id) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the order_id column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByAdminId(int $admin_id) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the admin_id column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByUuid(string $uuid) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the uuid column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByStatus(string $status) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the status column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByDetailedstatus(string $detailedStatus) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the detailedStatus column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByAmount(int $amount) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the amount column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByCurrencyId(int $currency_id) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the currency_id column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByCreationdate(string $creationDate) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the creationDate column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByErrorcode(string $errorCode) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the errorCode column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByErrormessage(string $errorMessage) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the errorMessage column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByDetailederrorcode(string $detailedErrorCode) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the detailedErrorCode column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByDetailederrormessage(string $detailedErrorMessage) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the detailedErrorMessage column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByFinished(boolean $finished) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the finished column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByCreatedAt(string $created_at) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the created_at column
 * @method     ChildPayzenEmbeddedTransactionHistory findOneByUpdatedAt(string $updated_at) Return the first ChildPayzenEmbeddedTransactionHistory filtered by the updated_at column
 *
 * @method     array findById(int $id) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the id column
 * @method     array findByCustomerId(int $customer_id) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the customer_id column
 * @method     array findByOrderId(int $order_id) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the order_id column
 * @method     array findByAdminId(int $admin_id) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the admin_id column
 * @method     array findByUuid(string $uuid) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the uuid column
 * @method     array findByStatus(string $status) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the status column
 * @method     array findByDetailedstatus(string $detailedStatus) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the detailedStatus column
 * @method     array findByAmount(int $amount) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the amount column
 * @method     array findByCurrencyId(int $currency_id) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the currency_id column
 * @method     array findByCreationdate(string $creationDate) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the creationDate column
 * @method     array findByErrorcode(string $errorCode) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the errorCode column
 * @method     array findByErrormessage(string $errorMessage) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the errorMessage column
 * @method     array findByDetailederrorcode(string $detailedErrorCode) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the detailedErrorCode column
 * @method     array findByDetailederrormessage(string $detailedErrorMessage) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the detailedErrorMessage column
 * @method     array findByFinished(boolean $finished) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the finished column
 * @method     array findByCreatedAt(string $created_at) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the created_at column
 * @method     array findByUpdatedAt(string $updated_at) Return ChildPayzenEmbeddedTransactionHistory objects filtered by the updated_at column
 *
 */
abstract class PayzenEmbeddedTransactionHistoryQuery extends ModelCriteria
{

    /**
     * Initializes internal state of \PayzenEmbedded\Model\Base\PayzenEmbeddedTransactionHistoryQuery object.
     *
     * @param     string $dbName The database name
     * @param     string $modelName The phpName of a model, e.g. 'Book'
     * @param     string $modelAlias The alias for the model in this query, e.g. 'b'
     */
    public function __construct($dbName = 'thelia', $modelName = '\\PayzenEmbedded\\Model\\PayzenEmbeddedTransactionHistory', $modelAlias = null)
    {
        parent::__construct($dbName, $modelName, $modelAlias);
    }

    /**
     * Returns a new ChildPayzenEmbeddedTransactionHistoryQuery object.
     *
     * @param     string $modelAlias The alias of a model in the query
     * @param     Criteria $criteria Optional Criteria to build the query from
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery
     */
    public static function create($modelAlias = null, $criteria = null)
    {
        if ($criteria instanceof \PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery) {
            return $criteria;
        }
        $query = new \PayzenEmbedded\Model\PayzenEmbeddedTransactionHistoryQuery();
        if (null !== $modelAlias) {
            $query->setModelAlias($modelAlias);
        }
        if ($criteria instanceof Criteria) {
            $query->mergeWith($criteria);
        }

        return $query;
    }

    /**
     * Find object by primary key.
     * Propel uses the instance pool to skip the database if the object exists.
     * Go fast if the query is untouched.
     *
     * <code>
     * $obj  = $c->findPk(12, $con);
     * </code>
     *
     * @param mixed $key Primary key to use for the query
     * @param ConnectionInterface $con an optional connection object
     *
     * @return ChildPayzenEmbeddedTransactionHistory|array|mixed the result, formatted by the current formatter
     */
    public function findPk($key, $con = null)
    {
        if ($key === null) {
            return null;
        }
        if ((null !== ($obj = PayzenEmbeddedTransactionHistoryTableMap::getInstanceFromPool((string) $key))) && !$this->formatter) {
            // the object is already in the instance pool
            return $obj;
        }
        if ($con === null) {
            $con = Propel::getServiceContainer()->getReadConnection(PayzenEmbeddedTransactionHistoryTableMap::DATABASE_NAME);
        }
        $this->basePreSelect($con);
        if ($this->formatter || $this->modelAlias || $this->with || $this->select
         || $this->selectColumns || $this->asColumns || $this->selectModifiers
         || $this->map || $this->having || $this->joins) {
            return $this->findPkComplex($key, $con);
        } else {
            return $this->findPkSimple($key, $con);
        }
    }

    /**
     * Find object by primary key using raw SQL to go fast.
     * Bypass doSelect() and the object formatter by using generated code.
     *
     * @param     mixed $key Primary key to use for the query
     * @param     ConnectionInterface $con A connection object
     *
     * @return   ChildPayzenEmbeddedTransactionHistory A model object, or null if the key is not found
     */
    protected function findPkSimple($key, $con)
    {
        $sql = 'SELECT ID, CUSTOMER_ID, ORDER_ID, ADMIN_ID, UUID, STATUS, DETAILEDSTATUS, AMOUNT, CURRENCY_ID, CREATIONDATE, ERRORCODE, ERRORMESSAGE, DETAILEDERRORCODE, DETAILEDERRORMESSAGE, FINISHED, CREATED_AT, UPDATED_AT FROM payzen_embedded_transaction_history WHERE ID = :p0';
        try {
            $stmt = $con->prepare($sql);
            $stmt->bindValue(':p0', $key, PDO::PARAM_INT);
            $stmt->execute();
        } catch (Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new PropelException(sprintf('Unable to execute SELECT statement [%s]', $sql), 0, $e);
        }
        $obj = null;
        if ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            $obj = new ChildPayzenEmbeddedTransactionHistory();
            $obj->hydrate($row);
            PayzenEmbeddedTransactionHistoryTableMap::addInstanceToPool($obj, (string) $key);
        }
        $stmt->closeCursor();

        return $obj;
    }

    /**
     * Find object by primary key.
     *
     * @param     mixed $key Primary key to use for the query
     * @param     ConnectionInterface $con A connection object
     *
     * @return ChildPayzenEmbeddedTransactionHistory|array|mixed the result, formatted by the current formatter
     */
    protected function findPkComplex($key, $con)
    {
        // As the query uses a PK condition, no limit(1) is necessary.
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $dataFetcher = $criteria
            ->filterByPrimaryKey($key)
            ->doSelect($con);

        return $criteria->getFormatter()->init($criteria)->formatOne($dataFetcher);
    }

    /**
     * Find objects by primary key
     * <code>
     * $objs = $c->findPks(array(12, 56, 832), $con);
     * </code>
     * @param     array $keys Primary keys to use for the query
     * @param     ConnectionInterface $con an optional connection object
     *
     * @return ObjectCollection|array|mixed the list of results, formatted by the current formatter
     */
    public function findPks($keys, $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getReadConnection($this->getDbName());
        }
        $this->basePreSelect($con);
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $dataFetcher = $criteria
            ->filterByPrimaryKeys($keys)
            ->doSelect($con);

        return $criteria->getFormatter()->init($criteria)->format($dataFetcher);
    }

    /**
     * Filter the query by primary key
     *
     * @param     mixed $key Primary key to use for the query
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByPrimaryKey($key)
    {

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ID, $key, Criteria::EQUAL);
    }

    /**
     * Filter the query by a list of primary keys
     *
     * @param     array $keys The list of primary key to use for the query
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByPrimaryKeys($keys)
    {

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ID, $keys, Criteria::IN);
    }

    /**
     * Filter the query on the id column
     *
     * Example usage:
     * <code>
     * $query->filterById(1234); // WHERE id = 1234
     * $query->filterById(array(12, 34)); // WHERE id IN (12, 34)
     * $query->filterById(array('min' => 12)); // WHERE id > 12
     * </code>
     *
     * @param     mixed $id The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterById($id = null, $comparison = null)
    {
        if (is_array($id)) {
            $useMinMax = false;
            if (isset($id['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ID, $id['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($id['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ID, $id['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ID, $id, $comparison);
    }

    /**
     * Filter the query on the customer_id column
     *
     * Example usage:
     * <code>
     * $query->filterByCustomerId(1234); // WHERE customer_id = 1234
     * $query->filterByCustomerId(array(12, 34)); // WHERE customer_id IN (12, 34)
     * $query->filterByCustomerId(array('min' => 12)); // WHERE customer_id > 12
     * </code>
     *
     * @see       filterByCustomer()
     *
     * @param     mixed $customerId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByCustomerId($customerId = null, $comparison = null)
    {
        if (is_array($customerId)) {
            $useMinMax = false;
            if (isset($customerId['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CUSTOMER_ID, $customerId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($customerId['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CUSTOMER_ID, $customerId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CUSTOMER_ID, $customerId, $comparison);
    }

    /**
     * Filter the query on the order_id column
     *
     * Example usage:
     * <code>
     * $query->filterByOrderId(1234); // WHERE order_id = 1234
     * $query->filterByOrderId(array(12, 34)); // WHERE order_id IN (12, 34)
     * $query->filterByOrderId(array('min' => 12)); // WHERE order_id > 12
     * </code>
     *
     * @see       filterByOrder()
     *
     * @param     mixed $orderId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByOrderId($orderId = null, $comparison = null)
    {
        if (is_array($orderId)) {
            $useMinMax = false;
            if (isset($orderId['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ORDER_ID, $orderId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($orderId['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ORDER_ID, $orderId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ORDER_ID, $orderId, $comparison);
    }

    /**
     * Filter the query on the admin_id column
     *
     * Example usage:
     * <code>
     * $query->filterByAdminId(1234); // WHERE admin_id = 1234
     * $query->filterByAdminId(array(12, 34)); // WHERE admin_id IN (12, 34)
     * $query->filterByAdminId(array('min' => 12)); // WHERE admin_id > 12
     * </code>
     *
     * @see       filterByAdmin()
     *
     * @param     mixed $adminId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByAdminId($adminId = null, $comparison = null)
    {
        if (is_array($adminId)) {
            $useMinMax = false;
            if (isset($adminId['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ADMIN_ID, $adminId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($adminId['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ADMIN_ID, $adminId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ADMIN_ID, $adminId, $comparison);
    }

    /**
     * Filter the query on the uuid column
     *
     * Example usage:
     * <code>
     * $query->filterByUuid('fooValue');   // WHERE uuid = 'fooValue'
     * $query->filterByUuid('%fooValue%'); // WHERE uuid LIKE '%fooValue%'
     * </code>
     *
     * @param     string $uuid The value to use as filter.
     *              Accepts wildcards (* and % trigger a LIKE)
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByUuid($uuid = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($uuid)) {
                $comparison = Criteria::IN;
            } elseif (preg_match('/[\%\*]/', $uuid)) {
                $uuid = str_replace('*', '%', $uuid);
                $comparison = Criteria::LIKE;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::UUID, $uuid, $comparison);
    }

    /**
     * Filter the query on the status column
     *
     * Example usage:
     * <code>
     * $query->filterByStatus('fooValue');   // WHERE status = 'fooValue'
     * $query->filterByStatus('%fooValue%'); // WHERE status LIKE '%fooValue%'
     * </code>
     *
     * @param     string $status The value to use as filter.
     *              Accepts wildcards (* and % trigger a LIKE)
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByStatus($status = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($status)) {
                $comparison = Criteria::IN;
            } elseif (preg_match('/[\%\*]/', $status)) {
                $status = str_replace('*', '%', $status);
                $comparison = Criteria::LIKE;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::STATUS, $status, $comparison);
    }

    /**
     * Filter the query on the detailedStatus column
     *
     * Example usage:
     * <code>
     * $query->filterByDetailedstatus('fooValue');   // WHERE detailedStatus = 'fooValue'
     * $query->filterByDetailedstatus('%fooValue%'); // WHERE detailedStatus LIKE '%fooValue%'
     * </code>
     *
     * @param     string $detailedstatus The value to use as filter.
     *              Accepts wildcards (* and % trigger a LIKE)
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByDetailedstatus($detailedstatus = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($detailedstatus)) {
                $comparison = Criteria::IN;
            } elseif (preg_match('/[\%\*]/', $detailedstatus)) {
                $detailedstatus = str_replace('*', '%', $detailedstatus);
                $comparison = Criteria::LIKE;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::DETAILEDSTATUS, $detailedstatus, $comparison);
    }

    /**
     * Filter the query on the amount column
     *
     * Example usage:
     * <code>
     * $query->filterByAmount(1234); // WHERE amount = 1234
     * $query->filterByAmount(array(12, 34)); // WHERE amount IN (12, 34)
     * $query->filterByAmount(array('min' => 12)); // WHERE amount > 12
     * </code>
     *
     * @param     mixed $amount The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByAmount($amount = null, $comparison = null)
    {
        if (is_array($amount)) {
            $useMinMax = false;
            if (isset($amount['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::AMOUNT, $amount['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($amount['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::AMOUNT, $amount['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::AMOUNT, $amount, $comparison);
    }

    /**
     * Filter the query on the currency_id column
     *
     * Example usage:
     * <code>
     * $query->filterByCurrencyId(1234); // WHERE currency_id = 1234
     * $query->filterByCurrencyId(array(12, 34)); // WHERE currency_id IN (12, 34)
     * $query->filterByCurrencyId(array('min' => 12)); // WHERE currency_id > 12
     * </code>
     *
     * @see       filterByCurrency()
     *
     * @param     mixed $currencyId The value to use as filter.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByCurrencyId($currencyId = null, $comparison = null)
    {
        if (is_array($currencyId)) {
            $useMinMax = false;
            if (isset($currencyId['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CURRENCY_ID, $currencyId['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($currencyId['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CURRENCY_ID, $currencyId['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CURRENCY_ID, $currencyId, $comparison);
    }

    /**
     * Filter the query on the creationDate column
     *
     * Example usage:
     * <code>
     * $query->filterByCreationdate('2011-03-14'); // WHERE creationDate = '2011-03-14'
     * $query->filterByCreationdate('now'); // WHERE creationDate = '2011-03-14'
     * $query->filterByCreationdate(array('max' => 'yesterday')); // WHERE creationDate > '2011-03-13'
     * </code>
     *
     * @param     mixed $creationdate The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByCreationdate($creationdate = null, $comparison = null)
    {
        if (is_array($creationdate)) {
            $useMinMax = false;
            if (isset($creationdate['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CREATIONDATE, $creationdate['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($creationdate['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CREATIONDATE, $creationdate['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CREATIONDATE, $creationdate, $comparison);
    }

    /**
     * Filter the query on the errorCode column
     *
     * Example usage:
     * <code>
     * $query->filterByErrorcode('fooValue');   // WHERE errorCode = 'fooValue'
     * $query->filterByErrorcode('%fooValue%'); // WHERE errorCode LIKE '%fooValue%'
     * </code>
     *
     * @param     string $errorcode The value to use as filter.
     *              Accepts wildcards (* and % trigger a LIKE)
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByErrorcode($errorcode = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($errorcode)) {
                $comparison = Criteria::IN;
            } elseif (preg_match('/[\%\*]/', $errorcode)) {
                $errorcode = str_replace('*', '%', $errorcode);
                $comparison = Criteria::LIKE;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ERRORCODE, $errorcode, $comparison);
    }

    /**
     * Filter the query on the errorMessage column
     *
     * Example usage:
     * <code>
     * $query->filterByErrormessage('fooValue');   // WHERE errorMessage = 'fooValue'
     * $query->filterByErrormessage('%fooValue%'); // WHERE errorMessage LIKE '%fooValue%'
     * </code>
     *
     * @param     string $errormessage The value to use as filter.
     *              Accepts wildcards (* and % trigger a LIKE)
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByErrormessage($errormessage = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($errormessage)) {
                $comparison = Criteria::IN;
            } elseif (preg_match('/[\%\*]/', $errormessage)) {
                $errormessage = str_replace('*', '%', $errormessage);
                $comparison = Criteria::LIKE;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ERRORMESSAGE, $errormessage, $comparison);
    }

    /**
     * Filter the query on the detailedErrorCode column
     *
     * Example usage:
     * <code>
     * $query->filterByDetailederrorcode('fooValue');   // WHERE detailedErrorCode = 'fooValue'
     * $query->filterByDetailederrorcode('%fooValue%'); // WHERE detailedErrorCode LIKE '%fooValue%'
     * </code>
     *
     * @param     string $detailederrorcode The value to use as filter.
     *              Accepts wildcards (* and % trigger a LIKE)
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByDetailederrorcode($detailederrorcode = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($detailederrorcode)) {
                $comparison = Criteria::IN;
            } elseif (preg_match('/[\%\*]/', $detailederrorcode)) {
                $detailederrorcode = str_replace('*', '%', $detailederrorcode);
                $comparison = Criteria::LIKE;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::DETAILEDERRORCODE, $detailederrorcode, $comparison);
    }

    /**
     * Filter the query on the detailedErrorMessage column
     *
     * Example usage:
     * <code>
     * $query->filterByDetailederrormessage('fooValue');   // WHERE detailedErrorMessage = 'fooValue'
     * $query->filterByDetailederrormessage('%fooValue%'); // WHERE detailedErrorMessage LIKE '%fooValue%'
     * </code>
     *
     * @param     string $detailederrormessage The value to use as filter.
     *              Accepts wildcards (* and % trigger a LIKE)
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByDetailederrormessage($detailederrormessage = null, $comparison = null)
    {
        if (null === $comparison) {
            if (is_array($detailederrormessage)) {
                $comparison = Criteria::IN;
            } elseif (preg_match('/[\%\*]/', $detailederrormessage)) {
                $detailederrormessage = str_replace('*', '%', $detailederrormessage);
                $comparison = Criteria::LIKE;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::DETAILEDERRORMESSAGE, $detailederrormessage, $comparison);
    }

    /**
     * Filter the query on the finished column
     *
     * Example usage:
     * <code>
     * $query->filterByFinished(true); // WHERE finished = true
     * $query->filterByFinished('yes'); // WHERE finished = true
     * </code>
     *
     * @param     boolean|string $finished The value to use as filter.
     *              Non-boolean arguments are converted using the following rules:
     *                * 1, '1', 'true',  'on',  and 'yes' are converted to boolean true
     *                * 0, '0', 'false', 'off', and 'no'  are converted to boolean false
     *              Check on string values is case insensitive (so 'FaLsE' is seen as 'false').
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByFinished($finished = null, $comparison = null)
    {
        if (is_string($finished)) {
            $finished = in_array(strtolower($finished), array('false', 'off', '-', 'no', 'n', '0', '')) ? false : true;
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::FINISHED, $finished, $comparison);
    }

    /**
     * Filter the query on the created_at column
     *
     * Example usage:
     * <code>
     * $query->filterByCreatedAt('2011-03-14'); // WHERE created_at = '2011-03-14'
     * $query->filterByCreatedAt('now'); // WHERE created_at = '2011-03-14'
     * $query->filterByCreatedAt(array('max' => 'yesterday')); // WHERE created_at > '2011-03-13'
     * </code>
     *
     * @param     mixed $createdAt The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByCreatedAt($createdAt = null, $comparison = null)
    {
        if (is_array($createdAt)) {
            $useMinMax = false;
            if (isset($createdAt['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CREATED_AT, $createdAt['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($createdAt['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CREATED_AT, $createdAt['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CREATED_AT, $createdAt, $comparison);
    }

    /**
     * Filter the query on the updated_at column
     *
     * Example usage:
     * <code>
     * $query->filterByUpdatedAt('2011-03-14'); // WHERE updated_at = '2011-03-14'
     * $query->filterByUpdatedAt('now'); // WHERE updated_at = '2011-03-14'
     * $query->filterByUpdatedAt(array('max' => 'yesterday')); // WHERE updated_at > '2011-03-13'
     * </code>
     *
     * @param     mixed $updatedAt The value to use as filter.
     *              Values can be integers (unix timestamps), DateTime objects, or strings.
     *              Empty strings are treated as NULL.
     *              Use scalar values for equality.
     *              Use array values for in_array() equivalent.
     *              Use associative array('min' => $minValue, 'max' => $maxValue) for intervals.
     * @param     string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByUpdatedAt($updatedAt = null, $comparison = null)
    {
        if (is_array($updatedAt)) {
            $useMinMax = false;
            if (isset($updatedAt['min'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::UPDATED_AT, $updatedAt['min'], Criteria::GREATER_EQUAL);
                $useMinMax = true;
            }
            if (isset($updatedAt['max'])) {
                $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::UPDATED_AT, $updatedAt['max'], Criteria::LESS_EQUAL);
                $useMinMax = true;
            }
            if ($useMinMax) {
                return $this;
            }
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }
        }

        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::UPDATED_AT, $updatedAt, $comparison);
    }

    /**
     * Filter the query by a related \Thelia\Model\Customer object
     *
     * @param \Thelia\Model\Customer|ObjectCollection $customer The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByCustomer($customer, $comparison = null)
    {
        if ($customer instanceof \Thelia\Model\Customer) {
            return $this
                ->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CUSTOMER_ID, $customer->getId(), $comparison);
        } elseif ($customer instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CUSTOMER_ID, $customer->toKeyValue('PrimaryKey', 'Id'), $comparison);
        } else {
            throw new PropelException('filterByCustomer() only accepts arguments of type \Thelia\Model\Customer or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Customer relation
     *
     * @param     string $relationAlias optional alias for the relation
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function joinCustomer($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Customer');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Customer');
        }

        return $this;
    }

    /**
     * Use the Customer relation Customer object
     *
     * @see useQuery()
     *
     * @param     string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return   \Thelia\Model\CustomerQuery A secondary query class using the current class as primary query
     */
    public function useCustomerQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinCustomer($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Customer', '\Thelia\Model\CustomerQuery');
    }

    /**
     * Filter the query by a related \Thelia\Model\Order object
     *
     * @param \Thelia\Model\Order|ObjectCollection $order The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByOrder($order, $comparison = null)
    {
        if ($order instanceof \Thelia\Model\Order) {
            return $this
                ->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ORDER_ID, $order->getId(), $comparison);
        } elseif ($order instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ORDER_ID, $order->toKeyValue('PrimaryKey', 'Id'), $comparison);
        } else {
            throw new PropelException('filterByOrder() only accepts arguments of type \Thelia\Model\Order or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Order relation
     *
     * @param     string $relationAlias optional alias for the relation
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function joinOrder($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Order');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Order');
        }

        return $this;
    }

    /**
     * Use the Order relation Order object
     *
     * @see useQuery()
     *
     * @param     string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return   \Thelia\Model\OrderQuery A secondary query class using the current class as primary query
     */
    public function useOrderQuery($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        return $this
            ->joinOrder($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Order', '\Thelia\Model\OrderQuery');
    }

    /**
     * Filter the query by a related \Thelia\Model\Admin object
     *
     * @param \Thelia\Model\Admin|ObjectCollection $admin The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByAdmin($admin, $comparison = null)
    {
        if ($admin instanceof \Thelia\Model\Admin) {
            return $this
                ->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ADMIN_ID, $admin->getId(), $comparison);
        } elseif ($admin instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ADMIN_ID, $admin->toKeyValue('PrimaryKey', 'Id'), $comparison);
        } else {
            throw new PropelException('filterByAdmin() only accepts arguments of type \Thelia\Model\Admin or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Admin relation
     *
     * @param     string $relationAlias optional alias for the relation
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function joinAdmin($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Admin');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Admin');
        }

        return $this;
    }

    /**
     * Use the Admin relation Admin object
     *
     * @see useQuery()
     *
     * @param     string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return   \Thelia\Model\AdminQuery A secondary query class using the current class as primary query
     */
    public function useAdminQuery($relationAlias = null, $joinType = Criteria::LEFT_JOIN)
    {
        return $this
            ->joinAdmin($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Admin', '\Thelia\Model\AdminQuery');
    }

    /**
     * Filter the query by a related \Thelia\Model\Currency object
     *
     * @param \Thelia\Model\Currency|ObjectCollection $currency The related object(s) to use as filter
     * @param string $comparison Operator to use for the column comparison, defaults to Criteria::EQUAL
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function filterByCurrency($currency, $comparison = null)
    {
        if ($currency instanceof \Thelia\Model\Currency) {
            return $this
                ->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CURRENCY_ID, $currency->getId(), $comparison);
        } elseif ($currency instanceof ObjectCollection) {
            if (null === $comparison) {
                $comparison = Criteria::IN;
            }

            return $this
                ->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CURRENCY_ID, $currency->toKeyValue('PrimaryKey', 'Id'), $comparison);
        } else {
            throw new PropelException('filterByCurrency() only accepts arguments of type \Thelia\Model\Currency or Collection');
        }
    }

    /**
     * Adds a JOIN clause to the query using the Currency relation
     *
     * @param     string $relationAlias optional alias for the relation
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function joinCurrency($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        $tableMap = $this->getTableMap();
        $relationMap = $tableMap->getRelation('Currency');

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        $join->setRelationMap($relationMap, $this->useAliasInSQL ? $this->getModelAlias() : null, $relationAlias);
        if ($previousJoin = $this->getPreviousJoin()) {
            $join->setPreviousJoin($previousJoin);
        }

        // add the ModelJoin to the current object
        if ($relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightTable()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, 'Currency');
        }

        return $this;
    }

    /**
     * Use the Currency relation Currency object
     *
     * @see useQuery()
     *
     * @param     string $relationAlias optional alias for the relation,
     *                                   to be used as main alias in the secondary query
     * @param     string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return   \Thelia\Model\CurrencyQuery A secondary query class using the current class as primary query
     */
    public function useCurrencyQuery($relationAlias = null, $joinType = Criteria::INNER_JOIN)
    {
        return $this
            ->joinCurrency($relationAlias, $joinType)
            ->useQuery($relationAlias ? $relationAlias : 'Currency', '\Thelia\Model\CurrencyQuery');
    }

    /**
     * Exclude object from result
     *
     * @param   ChildPayzenEmbeddedTransactionHistory $payzenEmbeddedTransactionHistory Object to remove from the list of results
     *
     * @return ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function prune($payzenEmbeddedTransactionHistory = null)
    {
        if ($payzenEmbeddedTransactionHistory) {
            $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::ID, $payzenEmbeddedTransactionHistory->getId(), Criteria::NOT_EQUAL);
        }

        return $this;
    }

    /**
     * Deletes all rows from the payzen_embedded_transaction_history table.
     *
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).
     */
    public function doDeleteAll(ConnectionInterface $con = null)
    {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(PayzenEmbeddedTransactionHistoryTableMap::DATABASE_NAME);
        }
        $affectedRows = 0; // initialize var to track total num of affected rows
        try {
            // use transaction because $criteria could contain info
            // for more than one table or we could emulating ON DELETE CASCADE, etc.
            $con->beginTransaction();
            $affectedRows += parent::doDeleteAll($con);
            // Because this db requires some delete cascade/set null emulation, we have to
            // clear the cached instance *after* the emulation has happened (since
            // instances get re-added by the select statement contained therein).
            PayzenEmbeddedTransactionHistoryTableMap::clearInstancePool();
            PayzenEmbeddedTransactionHistoryTableMap::clearRelatedInstancePool();

            $con->commit();
        } catch (PropelException $e) {
            $con->rollBack();
            throw $e;
        }

        return $affectedRows;
    }

    /**
     * Performs a DELETE on the database, given a ChildPayzenEmbeddedTransactionHistory or Criteria object OR a primary key value.
     *
     * @param mixed               $values Criteria or ChildPayzenEmbeddedTransactionHistory object or primary key or array of primary keys
     *              which is used to create the DELETE statement
     * @param ConnectionInterface $con the connection to use
     * @return int The number of affected rows (if supported by underlying database driver).  This includes CASCADE-related rows
     *                if supported by native driver or if emulated using Propel.
     * @throws PropelException Any exceptions caught during processing will be
     *         rethrown wrapped into a PropelException.
     */
     public function delete(ConnectionInterface $con = null)
     {
        if (null === $con) {
            $con = Propel::getServiceContainer()->getWriteConnection(PayzenEmbeddedTransactionHistoryTableMap::DATABASE_NAME);
        }

        $criteria = $this;

        // Set the correct dbName
        $criteria->setDbName(PayzenEmbeddedTransactionHistoryTableMap::DATABASE_NAME);

        $affectedRows = 0; // initialize var to track total num of affected rows

        try {
            // use transaction because $criteria could contain info
            // for more than one table or we could emulating ON DELETE CASCADE, etc.
            $con->beginTransaction();


        PayzenEmbeddedTransactionHistoryTableMap::removeInstanceFromPool($criteria);

            $affectedRows += ModelCriteria::delete($con);
            PayzenEmbeddedTransactionHistoryTableMap::clearRelatedInstancePool();
            $con->commit();

            return $affectedRows;
        } catch (PropelException $e) {
            $con->rollBack();
            throw $e;
        }
    }

    // timestampable behavior

    /**
     * Filter by the latest updated
     *
     * @param      int $nbDays Maximum age of the latest update in days
     *
     * @return     ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function recentlyUpdated($nbDays = 7)
    {
        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::UPDATED_AT, time() - $nbDays * 24 * 60 * 60, Criteria::GREATER_EQUAL);
    }

    /**
     * Filter by the latest created
     *
     * @param      int $nbDays Maximum age of in days
     *
     * @return     ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function recentlyCreated($nbDays = 7)
    {
        return $this->addUsingAlias(PayzenEmbeddedTransactionHistoryTableMap::CREATED_AT, time() - $nbDays * 24 * 60 * 60, Criteria::GREATER_EQUAL);
    }

    /**
     * Order by update date desc
     *
     * @return     ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function lastUpdatedFirst()
    {
        return $this->addDescendingOrderByColumn(PayzenEmbeddedTransactionHistoryTableMap::UPDATED_AT);
    }

    /**
     * Order by update date asc
     *
     * @return     ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function firstUpdatedFirst()
    {
        return $this->addAscendingOrderByColumn(PayzenEmbeddedTransactionHistoryTableMap::UPDATED_AT);
    }

    /**
     * Order by create date desc
     *
     * @return     ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function lastCreatedFirst()
    {
        return $this->addDescendingOrderByColumn(PayzenEmbeddedTransactionHistoryTableMap::CREATED_AT);
    }

    /**
     * Order by create date asc
     *
     * @return     ChildPayzenEmbeddedTransactionHistoryQuery The current query, for fluid interface
     */
    public function firstCreatedFirst()
    {
        return $this->addAscendingOrderByColumn(PayzenEmbeddedTransactionHistoryTableMap::CREATED_AT);
    }

} // PayzenEmbeddedTransactionHistoryQuery
