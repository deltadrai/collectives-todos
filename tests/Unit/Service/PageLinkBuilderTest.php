<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\Collectives\Db\Collective;
use OCA\Collectives\Db\CollectiveMapper;
use OCA\Collectives\Db\Page;
use OCA\Collectives\Db\PageMapper;
use OCA\CollectiveTodos\Service\PageLinkBuilder;
use PHPUnit\Framework\TestCase;

class PageLinkBuilderTest extends TestCase
{
    private CollectiveMapper $collectiveMapper;
    private PageMapper $pageMapper;
    private PageLinkBuilder $builder;

    protected function setUp(): void
    {
        $this->collectiveMapper = $this->createMock(CollectiveMapper::class);
        $this->pageMapper = $this->createMock(PageMapper::class);
        $this->builder = new PageLinkBuilder($this->collectiveMapper, $this->pageMapper);
    }

    private function mockCollective(string $urlPath): Collective
    {
        $collective = $this->createMock(Collective::class);
        $collective->method('getUrlPath')->willReturn($urlPath);
        return $collective;
    }

    private function mockPage(?string $slug): Page
    {
        // Page uses Entity magic getters (__call), which PHPUnit cannot
        // configure on mocks, so use a real entity and set the slug directly.
        $page = new Page();
        $page->setSlug($slug);
        return $page;
    }

    public function testGetPageUrlWithSlug(): void
    {
        $this->collectiveMapper->method('idToCollective')->willReturn($this->mockCollective('JF-Protokolle-8'));
        $this->pageMapper->method('findByFileId')->with(4127)->willReturn($this->mockPage('Ein-Demo-Protokoll'));

        $this->assertSame(
            '/apps/collectives/JF-Protokolle-8/Ein-Demo-Protokoll-4127',
            $this->builder->getPageUrl(8, '4127', 'Ein Demo Protokoll')
        );
    }

    public function testGetPageUrlWithoutSlugFallsBackToFileId(): void
    {
        $this->collectiveMapper->method('idToCollective')->willReturn($this->mockCollective('JF-Protokolle-8'));
        $this->pageMapper->method('findByFileId')->willReturn($this->mockPage(null));

        $this->assertSame(
            '/apps/collectives/JF-Protokolle-8/Ein%20Demo%20Protokoll?fileId=4127',
            $this->builder->getPageUrl(8, '4127', 'Ein Demo Protokoll')
        );
    }

    public function testGetPageUrlWithoutPageRowFallsBackToFileId(): void
    {
        $this->collectiveMapper->method('idToCollective')->willReturn($this->mockCollective('JF-Protokolle-8'));
        $this->pageMapper->method('findByFileId')->willReturn(null);

        $this->assertSame(
            '/apps/collectives/JF-Protokolle-8/Ein%20Demo%20Protokoll?fileId=4127',
            $this->builder->getPageUrl(8, '4127', 'Ein Demo Protokoll')
        );
    }

    public function testGetPageUrlReturnsNullWhenCollectiveNotFound(): void
    {
        $this->collectiveMapper->method('idToCollective')->willThrowException(new \RuntimeException('not found'));

        $this->assertNull($this->builder->getPageUrl(99, '4127', 'Ein Demo Protokoll'));
    }

    public function testGetPageUrlReturnsNullWhenPageLookupFails(): void
    {
        $this->collectiveMapper->method('idToCollective')->willReturn($this->mockCollective('JF-Protokolle-8'));
        $this->pageMapper->method('findByFileId')->willThrowException(new \RuntimeException('db error'));

        $this->assertSame(
            '/apps/collectives/JF-Protokolle-8/Ein%20Demo%20Protokoll?fileId=4127',
            $this->builder->getPageUrl(8, '4127', 'Ein Demo Protokoll')
        );
    }
}
