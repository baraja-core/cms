<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\Plugin\CmsPlugin;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Plugin\HomepagePlugin;
use Baraja\Plugin\PluginManager;
use Nette\Security\User;

final class MenuManager
{
	private PluginManager $pluginManager;

	private User $user;

	private MenuAuthorizatorAccessor $authorizator;

	/** @var true[] */
	private array $ignorePlugins = [
		CmsPlugin::class => true,
		ErrorPlugin::class => true,
		HomepagePlugin::class => true,
	];


	public function __construct(PluginManager $pluginManager, MenuAuthorizatorAccessor $authorizator, User $user)
	{
		$this->pluginManager = $pluginManager;
		$this->user = $user;
		$this->authorizator = $authorizator;
	}


	/**
	 * @return mixed[]
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
			if (($menuItem = $plugin['menuItem'] ?? null) !== null) {
				$return[] = $menuItem;
				continue;
			}
			if ($this->authorizator->get()->isAllowedPlugin($route = Helpers::formatPresenterNameToUri($plugin['name']))) {
				$return[] = [
					'key' => $plugin['service'],
					'title' => $plugin['label'],
					'priority' => $plugin['priority'],
					'link' => 'admin/' . $route,
					'icon' => $plugin['icon'] ?? null,
					'child' => [],
				];
			}
		}

		usort($return, fn (array $a, array $b): int => $a['priority'] < $b['priority'] ? 1 : -1);

		return $return;
	}
}
