<?php

namespace CoStack\FalGallery\Hooks;

/*
 * (c) 2015 Michiel Roos <michiel@maxserv.com>
 *
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Slots that pick up signals when a folder is created, changed or removed.
 */
class FileMutationSlot
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * FileMutationSlot constructor.
     */
    public function __construct()
    {
        $this->setDatabaseConnection();
    }

    /**
     * Post file add
     *
     * @param FileInterface $file The file
     * @param Folder $folder The folder
     *
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postFileAdd(FileInterface $file, Folder $folder)
    {
        $this->flushCacheForAffectedPages($folder);
    }

    /**
     * Post file copy
     *
     * @param FileInterface $file The file
     * @param Folder $folder The folder
     *
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postFileCopy(FileInterface $file, Folder $folder)
    {
        $this->flushCacheForAffectedPages($folder);
    }

    /**
     * Post file create
     *
     * @see \TYPO3\CMS\Core\Resource\ResourceStorage::createFile
     *
     * @param string $newFileIdentifier The created file name
     * @param Folder $targetFolder The folder the file was placed into
     *
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postFileCreate($newFileIdentifier, Folder $targetFolder)
    {
        $this->flushCacheForAffectedPages($targetFolder);
    }

    /**
     * Post file move
     *
     * @param FileInterface $file The file
     * @param Folder $targetFolder The folder
     * @param Folder $originalFolder The folder
     *
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postFileMove(FileInterface $file, Folder $targetFolder, Folder $originalFolder)
    {
        $this->flushCacheForAffectedPages($originalFolder);
        $this->flushCacheForAffectedPages($targetFolder);
    }

    /**
     * Post file rename
     *
     * @param FileInterface $file The file
     * @param string $targetFolder
     *
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postFileRename(FileInterface $file, $targetFolder)
    {
        $this->flushCacheForAffectedPages($file->getParentFolder());
    }

    /**
     * Post file replace
     *
     * @param FileInterface $file The file
     */
    public function postFileReplace(FileInterface $file)
    {
        $this->flushCacheForAffectedPages($file->getParentFolder());
    }

    /**
     * Pre file delete
     *
     * @param FileInterface $file The file
     */
    public function preFileDelete(FileInterface $file)
    {
        $this->flushCacheForAffectedPages($file->getParentFolder());
    }

    /**
     * Flush cache of pages containing gallery plugins with matching folders
     *
     * This is done two levels deep to take care of folders created inside a
     * category.
     *
     * @param FolderInterface $folder The folder
     */
    protected function flushCacheForAffectedPages(FolderInterface $folder)
    {
        $evaluate = $folder->getStorage()->getEvaluatePermissions();
        $folder->getStorage()->setEvaluatePermissions(false);
        $this->flushCacheForPages(
            $this->getAffectedPageIds(
                $folder->getParentFolder()->getParentFolder()
            )
        );
        $folder->getStorage()->setEvaluatePermissions($evaluate);
    }

    /**
     * Flush cache for given page ids
     *
     * @param array $pids An array of page ids
     */
    protected function flushCacheForPages(array $pids)
    {
        if (count($pids)) {
            /** @var \TYPO3\CMS\Core\Cache\CacheManager $cacheManager */
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            foreach ($pids as $pid) {
                $cacheManager->flushCachesByTag('pageId_' . $pid);
            }
        }
    }

    /**
     * Find the affected page ids by going through all the flexforms of all
     * active fal gallery content elements and checking if the current folder
     * is contained in the settings folder.
     *
     * @param FolderInterface $folder The folder to check
     *
     * @return array
     */
    protected function getAffectedPageIds(FolderInterface $folder)
    {
        $pids = [];

        if (!($folder instanceof Folder)) {
            return $pids;
        }

        if ($folder->getStorage()->getDriverType() === 'Local') {
            $identifier = GeneralUtility::makeInstance(LinkService::class)
                                        ->asString(['type' => LinkService::TYPE_FOLDER, 'folder' => $folder]);
            $identifier = htmlspecialchars($identifier);
            $query = <<<SQL
SELECT pid,
  ExtractValue(
      pi_flexform,
      '/T3FlexForms/data/sheet[@index=''list'']/language/field[@index=''settings.default.folder'']/value'
    ) as folder
FROM tt_content
WHERE list_type = 'falgallery_pi1' AND deleted = 0 AND hidden = 0 AND ExtractValue(
    pi_flexform,
    '/T3FlexForms/data/sheet[@index=''list'']/language/field[@index=''settings.default.folder'']/value'
  ) LIKE '$identifier%'
SQL;
            try {
                $statement = $this->connection->query($query);
            } catch (DBALException $e) {
                return [];
            }
            $statement->execute();
            while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                $pids[] = $row['pid'];
            }
        }

        return $pids;
    }

    /**
     * Set the database connection
     *
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private function setDatabaseConnection()
    {
        $this->connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
    }
}
