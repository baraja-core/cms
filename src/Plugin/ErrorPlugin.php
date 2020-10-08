<?php

declare(strict_types=1);

namespace Baraja\Cms\Plugin;


use Baraja\Plugin\BasePlugin;

final class ErrorPlugin extends BasePlugin
{
	public function getName(): string
	{
		return 'Error';
	}


	public function getLabel(): string
	{
		return 'Error';
	}


	public function getBaseEntity(): ?string
	{
		return null;
	}
}
