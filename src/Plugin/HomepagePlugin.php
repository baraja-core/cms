<?php

declare(strict_types=1);

namespace Baraja\Cms\Plugin;


use Baraja\Plugin\BasePlugin;

final class HomepagePlugin extends BasePlugin
{
	public function getName(): string
	{
		return 'Dashboard';
	}


	public function getLabel(): string
	{
		return 'Dashboard';
	}


	public function getBaseEntity(): ?string
	{
		return null;
	}


	public function getPriority(): int
	{
		return 1000;
	}
}
