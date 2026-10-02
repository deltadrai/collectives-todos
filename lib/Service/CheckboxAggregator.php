<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCA\Collectives\Mount\CollectiveStorage;

class CheckboxAggregator
{
    private const CACHE_FILE = '.collective/todos.json';
    private const CACHE_VERSION = 1;

    private IRootFolder $rootFolder;
    private IConfig $config;
    private SettingsService $settings;

    public function __construct(IRootFolder $rootFolder, IConfig $config, SettingsService $settings)
    {
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->settings = $settings;
    }

    /**
     * Get all cached page entries with checkboxes of a collectives.
     *
     * @param Folder $collectiveFolder The root folder of the collectives
     * @return array<string, array{title: string, page_id: string, last_modified: string, checkboxes: array}>
     */
    public function getAllCheckboxes(Folder $collectiveFolder): array
    {
        $cache = $this->readCache($collectiveFolder);
        return $cache['pages'] ?? [];
    }

    /**
     * Update the cached checkbox list of a single page.
     *
     * @param Folder $collectiveFolder The root folder of the collectives
     * @param string $pageId
     * @param string $pageTitle
     * @param array<array{text: string, checked: bool, line: int, raw: string}> $checkboxes
     */
    public function updatePageCheckboxes(
        Folder $collectiveFolder,
        string $pageId,
        string $pageTitle,
        array $checkboxes
    ): void {
        $cache = $this->readCache($collectiveFolder);
        $cache['pages'][$pageId] = [
            'title' => $pageTitle,
            'page_id' => $pageId,
            'last_modified' => (new \DateTime())->format('c'),
            'checkboxes' => $checkboxes,
        ];

        $stored = $this->applyCheckboxCap($cache, $collectiveFolder, $pageId, $checkboxes);
        $cache['pages'][$pageId]['checkboxes'] = $stored['checkboxes'];
        if ($stored['truncated']) {
            $cache['pages'][$pageId]['truncated'] = true;
        }

        $cache['updated'] = (new \DateTime())->format('c');
        $this->writeCache($collectiveFolder, $cache);
    }

    /**
     * Cap the total number of cached checkboxes of a collective at the
     * configured limit (0 = unlimited). The page being updated gets the
     * remaining budget; entries of other pages are never re-trimmed.
     *
     * @param array{version: int, updated: string, collectives_id: string, pages: array} $cache
     * @param array<array{text: string, checked: bool, line: int, raw: string}> $checkboxes
     * @return array{checkboxes: array, truncated: bool}
     */
    private function applyCheckboxCap(array $cache, Folder $collectiveFolder, string $pageId, array $checkboxes): array
    {
        $limit = $this->settings->resolveInt(
            $this->getCollectiveId($collectiveFolder),
            SettingsService::KEY_MAX_CHECKBOXES
        );
        if ($limit <= 0) {
            return ['checkboxes' => $checkboxes, 'truncated' => false];
        }

        $used = 0;
        foreach ($cache['pages'] as $id => $entry) {
            if ((string)$id === $pageId) {
                continue;
            }
            $used += count($entry['checkboxes'] ?? []);
        }

        $remaining = max(0, $limit - $used);
        $stored = array_slice($checkboxes, 0, $remaining);

        return ['checkboxes' => $stored, 'truncated' => count($checkboxes) > count($stored)];
    }

    /**
     * Remove a page from the cache.
     *
     * @param Folder $collectiveFolder The root folder of the collectives
     * @param string $pageId
     */
    public function removePage(Folder $collectiveFolder, string $pageId): void
    {
        $cache = $this->readCache($collectiveFolder);
        unset($cache['pages'][$pageId]);
        $cache['updated'] = (new \DateTime())->format('c');
        $this->writeCache($collectiveFolder, $cache);
    }

    /**
     * Remove all pages from the cache of a collectives.
     *
     * @param Folder $collectiveFolder The root folder of the collectives
     */
    public function clear(Folder $collectiveFolder): void
    {
        $this->writeCache($collectiveFolder, $this->getEmptyCache());
    }

    /**
     * Derive the display title of a page node.
     * Collectives stores a page as a folder with a Readme.md inside.
     */
    public function derivePageTitle(File $node): string
    {
        if ($node->getName() === 'Readme.md' || $node->getName() === 'README.md') {
            return $node->getParent()->getName();
        }
        return pathinfo($node->getName(), PATHINFO_FILENAME);
    }

    /**
     * Resolve the collectives root folder for a node inside a collectives.
     *
     * CollectiveStorage::getFolderId() returns the collectives id (as used by
     * the Collectives app); the root folder is resolved via the appdata path.
     */
    public function getCollectiveFolderFromNode(\OCP\Files\Node $node): Folder
    {
        $storage = $node->getStorage();

        if (!$storage->instanceOfStorage(CollectiveStorage::class)) {
            throw new \InvalidArgumentException('Node is not from Collective storage');
        }

        /** @var CollectiveStorage $storage */
        return $this->getFolder((string)$storage->getFolderId());
    }

    /**
     * Resolve the collective id (Collectives app) from a collective root folder.
     *
     * The folder either lives on a CollectiveStorage mount (user mount, the
     * storage carries the collective id) or in the Collectives appdata path
     * (`appdata_<instanceid>/collectives/<collectiveId>`).
     */
    public function getCollectiveId(Folder $collectiveFolder): ?int
    {
        $storage = $collectiveFolder->getStorage();

        if ($storage->instanceOfStorage(CollectiveStorage::class)) {
            /** @var CollectiveStorage $storage */
            return (int)$storage->getFolderId();
        }

        if (preg_match('#/collectives/(\d+)$#', $collectiveFolder->getPath(), $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Get a collectives folder by numeric collectives id (Collectives app),
     * by numeric folder file id, or by path (CLI context).
     */
    public function getFolder(string $collectiveId): Folder
    {
        if (ctype_digit($collectiveId)) {
            // Try the Collectives appdata folder first (collective id)
            $instanceId = $this->config->getSystemValueString('instanceid');
            if ($instanceId !== '') {
                try {
                    return $this->getFolderByPath('appdata_' . $instanceId . '/collectives/' . $collectiveId);
                } catch (NotFoundException $e) {
                    // Fall through to the file id lookup below
                }
            }

            // Fall back to interpreting the id as a folder file id
            $nodes = $this->rootFolder->getById((int)$collectiveId);
            foreach ($nodes as $node) {
                if ($node instanceof Folder) {
                    return $node;
                }
            }
            throw new \RuntimeException('No collectives folder found for id ' . $collectiveId);
        }

        return $this->getFolderByPath($collectiveId);
    }

    private function getFolderByPath(string $path): Folder
    {
        $node = $this->rootFolder->get($path);
        if (!($node instanceof Folder)) {
            throw new NotFoundException('Not a folder: ' . $path);
        }
        return $node;
    }

    /**
     * @return array{version: int, updated: string, collectives_id: string, pages: array}
     */
    private function readCache(Folder $collectiveFolder): array
    {
        if (!$collectiveFolder->nodeExists(self::CACHE_FILE)) {
            return $this->getEmptyCache();
        }

        try {
            $cacheFile = $collectiveFolder->get(self::CACHE_FILE);
            if (!($cacheFile instanceof File)) {
                return $this->getEmptyCache();
            }
            $data = json_decode($cacheFile->getContent(), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !isset($data['version']) || $data['version'] > self::CACHE_VERSION) {
                return $this->getEmptyCache();
            }

            return $data;
        } catch (NotFoundException |\JsonException $e) {
            return $this->getEmptyCache();
        }
    }

    /**
     * @param array{version: int, updated: string, collectives_id: string, pages: array} $data
     */
    private function writeCache(Folder $collectiveFolder, array $data): void
    {
        $data['version'] = self::CACHE_VERSION;

        if (!$collectiveFolder->nodeExists('.collective')) {
            $collectiveFolder->newFolder('.collective');
        }

        $content = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($collectiveFolder->nodeExists(self::CACHE_FILE)) {
            $cacheFile = $collectiveFolder->get(self::CACHE_FILE);
            if ($cacheFile instanceof File) {
                $cacheFile->putContent($content);
                return;
            }
        }
        $collectiveFolder->newFile(self::CACHE_FILE, $content);
    }

    /**
     * @return array{version: int, updated: string, collectives_id: string, pages: array}
     */
    private function getEmptyCache(): array
    {
        return [
            'version' => self::CACHE_VERSION,
            'updated' => (new \DateTime())->format('c'),
            'collectives_id' => '',
            'pages' => [],
        ];
    }
}
