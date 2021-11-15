<?php

namespace Walther\ImageCopyright\Resource;

use PDO;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use function file_exists;
use function in_array;

/**
 * Class FileRepository
 *
 * @package Walther\ImageCopyright\Resource
 */
class FileRepository extends \TYPO3\CMS\Core\Resource\FileRepository
{
    /**
     * @var array
     */
    protected array $extensions = [];

    /**
     * @var bool
     */
    protected bool $showEmpty = false;

    /**
     * @var bool
     */
    protected bool $includeFileCollections = false;

    /**
     * @var array
     */
    protected array $tableFieldConfiguration = [];

    /**
     * findAllByRelation
     *
     * @param array    $tableFieldConfiguration
     * @param array    $tableFieldConfigurationForCollections
     * @param array    $extensions
     * @param bool     $showEmpty
     * @param int|null $pid
     *
     * @return array
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findAllByRelation(array $tableFieldConfiguration, array $tableFieldConfigurationForCollections, array $extensions, bool $showEmpty, ?int $pid = null) : array
    {
        $this->extensions = $extensions;
        $this->showEmpty = $showEmpty;
        $this->includeFileCollections = !empty($tableFieldConfigurationForCollections);
        $referenceUids = [];

        $this->tableFieldConfiguration = $tableFieldConfiguration;

        // Get the table names for regular sys_file_references from the configuration
        $tableNames = [];
        foreach ($this->tableFieldConfiguration as $configuration) {
            $tableNames[] = $configuration['tableName'];
        }

        // Get all PIDs in a page tree if no pid is given explicitly
        $pageTreePidArray = $pid !== null ? [$pid] : $this->getPageTreePidArray();

        if (!empty($GLOBALS['TSFE']->sys_page) && $this->getEnvironmentMode() === 'FE') {

            /** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');

            $queryBuilder
                ->getRestrictions()
                ->removeAll();

            $queryBuilder
                ->select('sys_file_reference.uid', 'sys_file_reference.uid_local')
                ->from('sys_file_reference');

            foreach ($tableNames as $tableName) {
                $queryBuilder->leftJoin(
                    'sys_file_reference',
                    $tableName,
                    $tableName,
                    $queryBuilder->expr()->eq('sys_file_reference.uid_foreign', $queryBuilder->quoteIdentifier($tableName . '.uid')) . ' AND ' . $queryBuilder->expr()->eq('sys_file_reference.tablenames', $queryBuilder->createNamedParameter($tableName, PDO::PARAM_STR))
                );

                $queryBuilder->orWhere(
                    $queryBuilder->expr()->andX(
                        $queryBuilder->expr()->eq($tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['delete'], 0),
                        $queryBuilder->expr()->eq($tableName . '.' . $GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns']['disabled'], 0)
                    )
                );
            }

            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('sys_file_reference.pid', $pageTreePidArray)
            );

            $queryBuilder->andWhere(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq('sys_file_reference.deleted', 0),
                    $queryBuilder->expr()->eq('sys_file_reference.hidden', 0)
                )
            );

            $res = $queryBuilder->orderBy('sys_file_reference.sorting_foreign')->groupBy('sys_file_reference.uid_local')->execute();

            while ($row = $res->fetchAssociative()) {
                $referenceUids[] = [
                    'uid' => $row['uid'],
                    'uid_local' => $row['uid_local']
                ];
            }

            return $this->prepareList(array_merge($referenceUids, $this->getImagesFromFileCollections($tableFieldConfigurationForCollections, $pageTreePidArray)));
        }

        return [];
    }

    /**
     * getPageTreePidArray
     *
     * Get all pids in current page tree
     *
     * @return array
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function getPageTreePidArray() : array
    {
        /** @var int $currentPageUid */
        $currentPageUid = $GLOBALS['TSFE']->id;

        /** @var \TYPO3\CMS\Core\Utility\RootlineUtility $rootlineUtility */
        $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $currentPageUid, '', false);

        $rootPageUid = 0;
        foreach ($rootlineUtility->get() as $rootlineItem) {
            if ($rootlineItem['is_siteroot'] === 1) {
                $rootPageUid = $rootlineItem['uid'];
                break;
            }
        }

        return explode(',', $this->getTreeList($rootPageUid, 256));
    }

    /**
     * prepareList
     *
     * @param array $references
     *
     * @return array
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function prepareList(array $references) : array
    {
        $itemList = [];

        if (!empty($references)) {
            $referencesUnique = [];

            foreach ($references as $reference) {
                $referencesUnique[$reference['uid_local']] = $reference['uid'];
            }

            $references = $referencesUnique;
            $references = array_flip($references);
            $referenceUids = array_keys($references);
        }

        if (!empty($referenceUids)) {
            foreach ($referenceUids as $referenceUid) {

                try {
                    $fileReferenceObject = $this->factory->getFileReferenceObject($referenceUid);
                    $fileExtension = $fileReferenceObject->getExtension();
                    if ($fileReferenceObject->isMissing() === false && in_array($fileExtension, $this->extensions, true) && file_exists(Environment::getProjectPath() . $fileReferenceObject->getPublicUrl()) === true) {
                        if ($this->showEmpty === true || ($this->showEmpty === false && !empty($fileReferenceObject->getProperty('copyright')))) {
                            $itemList[] = [
                                'file' => $fileReferenceObject->getOriginalFile(),
                                'pages' => $this->generateFileReferencePages($fileReferenceObject)
                            ];
                        }
                    }
                } catch (ResourceDoesNotExistException $exception) {
                    // nothing to do here
                }
            }
        }

        return $itemList;
    }

    /**
     * generateFileReferenceUris
     *
     * @param \TYPO3\CMS\Core\Resource\FileReference $fileReferenceObject
     *
     * @return array
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function generateFileReferencePages(\TYPO3\CMS\Core\Resource\FileReference $fileReferenceObject) : array
    {
        $pages = [];

        // Get the table names for regular sys_file_references from the configuration
        $tableNames = [];
        foreach ($this->tableFieldConfiguration as $configuration) {
            $tableNames[] = $configuration['tableName'];
        }

        /** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $results = $queryBuilder
            ->select('sys_file_reference.*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('sys_file_reference.uid_local', $fileReferenceObject->getProperties()['uid_local'])
            )
            ->execute()
            ->fetchAllAssociative();

        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);

        foreach ($results as $result) {
            if (in_array($result['tablenames'], $tableNames)) {
                $pages[] = $pageRepository->getPage($result['pid']);
            }
        }

        return $pages;
    }

    /**
     * getImagesFromFileCollections
     *
     * @param array $tableFieldConfigurationForCollections
     * @param array $pid
     *
     * @return array
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function getImagesFromFileCollections(array $tableFieldConfigurationForCollections, array $pid) : array
    {
        if ($this->includeFileCollections === true) {
            $referenceUids = [];

            /** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder */
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');

            $queryBuilder
                ->getRestrictions()
                ->removeAll();

            $queryBuilder
                ->select('sys_file_reference.uid', 'sys_file_reference.uid_local')
                ->from('sys_file_reference');

            $queryBuilder->leftJoin(
                'sys_file_reference',
                'sys_file_collection',
                'sys_file_collection',
                $queryBuilder->expr()->eq('sys_file_reference.uid_foreign', $queryBuilder->quoteIdentifier('sys_file_collection.uid')) . ' AND ' . $queryBuilder->expr()->eq('sys_file_reference.tablenames', $queryBuilder->createNamedParameter('sys_file_collection', PDO::PARAM_STR))
            );

            foreach ($tableFieldConfigurationForCollections as $configuration) {
                $queryBuilder->leftJoin(
                    'sys_file_collection',
                    $configuration['tableName'],
                    $configuration['tableName'],
                    $queryBuilder->expr()->inSet($configuration['tableName'] . '.' . $configuration['fieldName'], $queryBuilder->quoteIdentifier('sys_file_collection.uid'))
                );

                $queryBuilder->orWhere(
                    $queryBuilder->expr()->andX(
                        $queryBuilder->expr()->eq($configuration['tableName'] . '.' . $GLOBALS['TCA'][$configuration['tableName']]['ctrl']['delete'], 0),
                        $queryBuilder->expr()->eq($configuration['tableName'] . '.' . $GLOBALS['TCA'][$configuration['tableName']]['ctrl']['enablecolumns']['disabled'], 0),
                        $queryBuilder->expr()->in($configuration['tableName'] . '.' . 'pid', $pid)
                    )
                );
            }

            $queryBuilder->andWhere(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq('sys_file_reference.deleted', 0),
                    $queryBuilder->expr()->eq('sys_file_reference.hidden', 0),
                    $queryBuilder->expr()->eq('sys_file_collection.deleted', 0),
                    $queryBuilder->expr()->eq('sys_file_collection.hidden', 0)
                )
            );

            $res = $queryBuilder->orderBy('sys_file_reference.sorting_foreign')->groupBy('sys_file_reference.uid_local')->execute();

            while ($row = $res->fetchAssociative()) {
                $referenceUids[] = [
                    'uid' => $row['uid'],
                    'uid_local' => $row['uid_local']
                ];
            }

            return $referenceUids;
        }

        return [];
    }

    /**
     * getFileCollectionPid
     *
     * @param int $uid
     *
     * @return int
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    private function getFileCollectionPid(int $uid) : int
    {
        /** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        /** @var \TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer $frontendRestrictionContainer */
        $frontendRestrictionContainer = GeneralUtility::makeInstance(FrontendRestrictionContainer::class);

        $queryBuilder->setRestrictions($frontendRestrictionContainer);

        $result = $queryBuilder
            ->select('pid')
            ->from('sys_file_collection')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, PDO::PARAM_INT))
            )
            ->execute()->fetchAssociative();

        if ($result['pid']) {
            $returnPid = $result['pid'];
        } else {
            $returnPid = 0;
        }

        return $returnPid;
    }

    /**
     * Recursively fetch all descendants of a given page
     *
     * @param int    $id uid of the page
     * @param int    $depth
     * @param int    $begin
     * @param string $permClause
     *
     * @return string comma separated list of descendant pages
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function getTreeList(int $id, int $depth, int $begin = 0, string $permClause = '')
    {
        if ($id < 0) {
            $id = abs($id);
        }

        if ($begin === 0) {
            $theList = $id;
        } else {
            $theList = '';
        }

        if ($id && $depth > 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($id, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', 0)
                )
                ->orderBy('uid');

            if ($permClause !== '') {
                $queryBuilder->andWhere(QueryHelper::stripLogicalOperatorPrefix($permClause));
            }

            $statement = $queryBuilder->execute();

            while ($row = $statement->fetchAssociative()) {
                if ($begin <= 0) {
                    $theList .= ',' . $row['uid'];
                }

                if ($depth > 1) {
                    $theSubList = $this->getTreeList($row['uid'], $depth - 1, $begin - 1, $permClause);
                    if (!empty($theList) && !empty($theSubList) && ($theSubList[0] !== ',')) {
                        $theList .= ',';
                    }
                    $theList .= $theSubList;
                }
            }
        }

        return $theList;
    }
}
