<?php

declare(strict_types=1);

namespace OCA\CollectiveTodos\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class Section implements IIconSection
{
	private IURLGenerator $urlGenerator;
	private IL10N $l10n;

	public function __construct(IURLGenerator $urlGenerator, IL10N $l10n)
	{
		$this->urlGenerator = $urlGenerator;
		$this->l10n = $l10n;
	}

	public function getID()
	{
		return 'collectives_todos';
	}

	public function getName()
	{
		return $this->l10n->t('Collectives Todos');
	}

	public function getPriority()
	{
		return 80;
	}

	public function getIcon()
	{
		return $this->urlGenerator->imagePath('collectives_todos', 'app-dark.svg');
	}
}
