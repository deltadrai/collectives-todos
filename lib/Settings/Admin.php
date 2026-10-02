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
		// Registers the script tag with a CSP nonce; needs the server
		// runtime (skipped in unit tests, which boot no \OC)
		if (class_exists(\OC::class)) {
			\OCP\Util::addScript('collectives_todos', 'admin-picker');
		}
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
