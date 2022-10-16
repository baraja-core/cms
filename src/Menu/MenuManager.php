<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\CAS\User;
use Baraja\Cms\Plugin\CmsPlugin;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Plugin\HomepagePlugin;
use Baraja\Plugin\Plugin;
use Baraja\Plugin\PluginManager;

final class MenuManager
{
	/** @var array<class-string<Plugin>, true> */
	private array $ignorePlugins = [
		CmsPlugin::class => true,
		ErrorPlugin::class => true,
		HomepagePlugin::class => true,
	];


	public function __construct(
		private PluginManager $pluginManager,
		private MenuAuthorizatorAccessor $authorizator,
		private User $user,
	) {
	}


	/**
	 * @param Plugin|class-string<Plugin> $plugin
	 */
	public function addIgnorePlugin(Plugin|string $plugin): void
	{
		$this->ignorePlugins[is_string($plugin) ? $plugin : $plugin::class] = true;
	}


	/**
	 * @return array<int, MenuItem>
	 */
	public function getItems(): array
	{
		if ($this->user->isLoggedIn() === false) {
			return [];
		}

		$return = [];
		foreach ($this->pluginManager->getPluginInfo() as $plugin) {
			if (isset($this->ignorePlugins[$plugin['type']]) === true) {
				continue;
			}
			if (isset($plugin['menuItem'])) {
				$return[] = MenuItem::fromPluginDefinition($plugin['menuItem']);
			} elseif ($this->authorizator->get()->isAllowedPlugin(Helpers::formatPresenterNameToUri($plugin['name']))) {
				$return[] = MenuItem::fromPluginDefinition($plugin);
			}
		}

		usort($return, static fn(MenuItem $a, MenuItem $b): int => $a->priority < $b->priority ? 1 : -1);

		return $return;
	}
}
