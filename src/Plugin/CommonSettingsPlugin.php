<?php

declare(strict_types=1);

namespace Baraja\Cms\Plugin;


use Baraja\Plugin\BasePlugin;

final class CommonSettingsPlugin extends BasePlugin
{
	public function getName(): string
	{
		return 'Common settings';
	}
}
