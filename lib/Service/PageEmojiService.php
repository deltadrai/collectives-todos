<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCA\Collectives\Db\Page;
use OCA\Collectives\Db\PageMapper;
use OCP\Files\Folder;

/**
 * Enforce the configured emoji of the Todos page: the Collectives app
 * stores a page's emoji in the `collectives_pages` row and renders it in
 * the page tree. An empty configured value clears the emoji (stored as
 * null), like Collectives itself does.
 */
class PageEmojiService
{
	private PageMapper $pageMapper;
	private SettingsService $settings;

	public function __construct(PageMapper $pageMapper, SettingsService $settings)
	{
		$this->pageMapper = $pageMapper;
		$this->settings = $settings;
	}

	/**
	 * Write the configured Todos page emoji to the page's collectives_pages
	 * row. A freshly (re)created Todos page has no row yet - Collectives
	 * creates it lazily - so the row is created with the emoji, the same
	 * way Collectives' own code inserts page rows. Skipped when the Todos
	 * page is missing or no emoji is configured.
	 */
	public function enforceEmoji(Folder $collectiveFolder, ?int $collectiveId): void
	{
		$emoji = $this->settings->resolve($collectiveId, SettingsService::KEY_TODOS_PAGE_EMOJI);
		$todosFilename = $this->settings->resolveTodosPageFilename($collectiveId);

		try {
			$todosFile = $collectiveFolder->get($todosFilename);
		} catch (\OCP\Files\NotFoundException $e) {
			return;
		}

		$page = $this->pageMapper->findByFileId((int)$todosFile->getId());
		if ($page === null) {
			if ($emoji === '') {
				return;
			}
			$page = new Page();
			$page->setFileId((int)$todosFile->getId());
			// last_user_id is NOT NULL in collectives_pages; the Todos page
			// is written by the app, not by a user. An empty id makes the
			// frontend hide the "last edited" info, like for untouched pages.
			$page->setLastUserId('');
			$page->setEmoji($emoji);
			$this->pageMapper->updateOrInsert($page);
			return;
		}

		if (($page->getEmoji() ?? '') === $emoji) {
			return;
		}

		$page->setEmoji($emoji === '' ? null : $emoji);
		$this->pageMapper->update($page);
	}
}
