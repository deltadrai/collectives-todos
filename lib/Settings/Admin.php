<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Settings;

use OCA\CollectiveTodos\Service\PanelDataService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;

class Admin implements IDelegatedSettings
{
	private PanelDataService $panelData;

	public function __construct(PanelDataService $panelData)
	{
		$this->panelData = $panelData;
	}

	public function getForm(): TemplateResponse
	{
		return new TemplateResponse('collectives_todos', 'admin', $this->panelData->getPanelData());
	}

	public function getSection(): string
	{
		return 'collectives_todos';
	}

	public function getPriority(): int
	{
		return 80;
	}

	public function getName(): ?string
	{
		return null;
	}

	public function getAuthorizedAppConfig(): array
	{
		return ['collectives_todos' => ['/.*/']];
	}
}
