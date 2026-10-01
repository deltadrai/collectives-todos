<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Service;

use OCA\Collectives\Db\CollectiveMapper;
use OCA\Collectives\Db\PageMapper;

/**
 * Build root-relative URLs to Collectives pages, in the same format the
 * Collectives app itself uses (PageService::getPageLink()):
 *
 *   /apps/collectives/<collectiveUrlPath>/<pageSlug>-<pageFileId>
 *
 * or, for pages without a slug, the fileId fallback:
 *
 *   /apps/collectives/<collectiveUrlPath>/<title>?fileId=<pageFileId>
 *
 * Both are recognized by the Collectives frontend router and by the
 * page reference provider.
 */
class PageLinkBuilder
{
    private CollectiveMapper $collectiveMapper;
    private PageMapper $pageMapper;

    public function __construct(CollectiveMapper $collectiveMapper, PageMapper $pageMapper)
    {
        $this->collectiveMapper = $collectiveMapper;
        $this->pageMapper = $pageMapper;
    }

    /**
     * Get the root-relative URL of a page inside its collective.
     *
     * @param int $collectiveId The collective id (Collectives app)
     * @param string $pageId The file id of the page's markdown file
     * @param string $title The page title (used in the no-slug fallback)
     */
    public function getPageUrl(int $collectiveId, string $pageId, string $title): ?string
    {
        try {
            $collective = $this->collectiveMapper->idToCollective($collectiveId);
        } catch (\Throwable $e) {
            return null;
        }

        $slug = null;
        try {
            $page = $this->pageMapper->findByFileId((int)$pageId);
            $slug = $page !== null ? $page->getSlug() : null;
        } catch (\Throwable $e) {
            // Fall through to the fileId fallback below
        }

        if ($slug !== null && $slug !== '') {
            $pageRoute = rawurlencode($slug . '-' . $pageId);
        } else {
            $pageRoute = rawurlencode($title) . '?fileId=' . $pageId;
        }

        return '/apps/collectives/' . rawurlencode($collective->getUrlPath()) . '/' . $pageRoute;
    }
}
