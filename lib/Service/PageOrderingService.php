<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCA\Collectives\Db\Page;
use OCA\Collectives\Db\PageMapper;
use OCP\Files\Folder;

/**
 * Enforce the configured tree position of the Todos page in the Collectives
 * page tree.
 *
 * The Collectives frontend sorts subpages of a parent page by the parent's
 * `subpage_order`: pages listed in the array come first (in array order),
 * unlisted pages follow alphabetically by title. Inserting the Todos page id
 * at the front of the landing page's array pins it to the top, appending it
 * pins it to the bottom, removing it lets it sort alphabetically. Other ids
 * in the array are never touched, so manual ordering of the other pages is
 * preserved.
 */
class PageOrderingService
{
	private PageMapper $pageMapper;
	private SettingsService $settings;

	public function __construct(PageMapper $pageMapper, SettingsService $settings)
	{
		$this->pageMapper = $pageMapper;
		$this->settings = $settings;
	}

	/**
	 * Pin (or unpin) the Todos page of a collective to its configured
	 * position. Silently skipped when the Todos page, the landing page or
	 * its collectives_pages row is missing, or the stored subpage order is
	 * corrupt.
	 */
	public function enforcePosition(Folder $collectiveFolder, ?int $collectiveId): void
	{
		$position = $this->settings->resolve($collectiveId, SettingsService::KEY_TREE_POSITION);
		$todosFilename = $this->settings->resolveTodosPageFilename($collectiveId);

		try {
			$todosFile = $collectiveFolder->get($todosFilename);
			$landingFile = $collectiveFolder->get('Readme.md');
		} catch (\OCP\Files\NotFoundException $e) {
			$landingFile = null;
			try {
				$todosFile = $collectiveFolder->get($todosFilename);
				$landingFile = $collectiveFolder->get('README.md');
			} catch (\OCP\Files\NotFoundException $e2) {
				return;
			}
		}

		$page = $this->pageMapper->findByFileId((int)$landingFile->getId());
		if ($page === null) {
			return;
		}

		$order = json_decode($page->getSubpageOrder() ?? '[]', true);
		if (!is_array($order) || !array_is_list($order)) {
			return;
		}
		foreach ($order as $id) {
			if (!is_int($id)) {
				return;
			}
		}

		$todosId = (int)$todosFile->getId();
		$filtered = [];
		foreach ($order as $id) {
			if ((int)$id !== $todosId) {
				$filtered[] = (int)$id;
			}
		}

		if ($position === SettingsService::POSITION_TOP) {
			array_unshift($filtered, $todosId);
		} elseif ($position === SettingsService::POSITION_BOTTOM) {
			$filtered[] = $todosId;
		}

		if ($filtered === $order) {
			return;
		}

		$page->setSubpageOrder((string)json_encode(array_values($filtered)));
		$this->pageMapper->update($page);
	}
}
