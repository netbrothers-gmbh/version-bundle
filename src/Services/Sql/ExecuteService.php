<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 19.03.21
 *
 */

namespace NetBrothers\VersionBundle\Services\Sql;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\ORM\EntityManagerInterface;

/** executing sql on connection
 *
 * Class ExecuteService
 * @package NetBrothers\VersionBundle\Services\Sql
 */
class ExecuteService
{

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var Connection */
    private $connection;

    /** @var string */
    private $errMsg;

    /**
     * ExecuteService constructor.
     * @param EntityManagerInterface $entityManager
     * @throws \Exception
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        if ($entityManager->getConnection()->getDatabasePlatform()->getName() !== 'mysql') {
            throw new \Exception(__CLASS__  . ' can only be executed safely on \'mysql\'.');
        }
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    /**
     * @param array $sql
     * @return bool
     * @throws DriverException
     * @throws \Exception
     */
    public function execute(array $sql = []): bool
    {
        if (!is_array($sql)) {
            throw new \Exception(__CLASS__ . ': only sql-statements packed in array can be executed.');
        }
        if (0 < count($sql)) {
            $this->connection->beginTransaction();
            try {
                foreach ($sql as $query) {
                    if (true !== $this->_execute($query)) {
                        return false;
                    }
                }
                $this->connection->commit();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                $this->connection->setAutoCommit(true);
                throw new \Exception("Cannot commit", 500, $e);
            }
        }
        return true;
    }

    /**
     * @param array $sql
     * @return bool
     * @throws ConnectionException
     * @throws DriverException
     */
    public function dryRun(array $sql = []): bool
    {
        if (!is_array($sql)) {
            throw new \Exception(__CLASS__ . ': only sql-statements packed in array can be executed.');
        }
        if (0 < count($sql)) {
            $this->connection->beginTransaction();
            try {
                foreach ($sql as $query) {
                    if (true !== $this->_execute($query)) {
                        return false;
                    }
                }
                $this->connection->rollBack();
            } catch (\Exception $e) {
                $this->connection->rollBack();
                $this->connection->setAutoCommit(true);
                throw new \Exception("Cannot commit", 500, $e);
            }
        }
        return true;
    }

    /**
     * @param string $query
     * @return bool
     * @throws ConnectionException
     * @throws DriverException
     * @throws \Doctrine\DBAL\Exception
     */
    private function _execute(string $query): bool
    {
        $statement = $this->connection->prepare($query);
        if (true !== $statement->execute()) {
            $this->connection->rollBack();
            $this->connection->setAutoCommit(true);
            $this->errMsg = "Cannot execute SQL: $query";
            return false;
        }
        return true;
    }

}