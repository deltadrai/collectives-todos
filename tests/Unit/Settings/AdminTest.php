<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Tests\Unit\Settings;

use OCA\CollectiveTodos\Service\PanelDataService;
use OCA\CollectiveTodos\Settings\Admin;
use OCP\AppFramework\Http\TemplateResponse;
use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase
{
	public function testGetFormRendersAdminTemplate(): void
	{
		$panelData = $this->createMock(PanelDataService::class);
		$expectedParams = ['form_action' => '/apps/collectives_todos/settings', 'errors' => []];
		$panelData->expects($this->once())->method('getPanelData')->with()->willReturn($expectedParams);

		$admin = new Admin($panelData);

		$response = $admin->getForm();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('admin', $response->getTemplateName());
		$this->assertSame($expectedParams, $response->getParams());
	}

	public function testSettingsMetadata(): void
	{
		$admin = new Admin($this->createMock(PanelDataService::class));

		$this->assertSame('collectives_todos', $admin->getSection());
		$this->assertSame(80, $admin->getPriority());
		$this->assertNull($admin->getName());
		$this->assertSame(['collectives_todos' => ['/.*/']], $admin->getAuthorizedAppConfig());
	}
}
