<?php

declare(strict_types=1);

namespace Baraja\Cms;


final class MenuItem
{
	/**
	 * @param array<int, self> $child
	 */
	public function __construct(
		public string $key,
		public string $title,
		public string $pluginName,
		public int $priority,
		public string $link,
		public ?string $icon,
		public array $child = [],
	) {
	}
}
