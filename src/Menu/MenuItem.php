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


	/**
	 * @param array{
	 *     service: string,
	 *     type: class-string<Plugin>,
	 *     name: string,
	 *     realName: string,
	 *     baseEntity: string|null,
	 *     label: string,
	 *     basePath: string,
	 *     priority: int,
	 *     icon: string|null,
	 *     roles: array<int, string>,
	 *     privileges: array<int, string>,
	 *     menuItem: array<string, string|null>|null
	 * } $plugin
	 */
	public static function fromPluginDefinition(array $plugin): self
	{
		return new self(
			key: $plugin['service'],
			title: $plugin['label'],
			pluginName: $plugin['name'],
			priority: $plugin['priority'],
			link: sprintf(
				'%s/%s',
				Configuration::get()->getBaseUri(), Helpers::formatPresenterNameToUri($plugin['name']),
			),
			icon: $plugin['icon'] ?? null,
			child: [],
		);
	}
}
