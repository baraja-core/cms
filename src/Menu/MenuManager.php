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

	/** @var true[] */
	private array $ignorePlugins = [
		CmsPlugin::class => true,
		ErrorPlugin::class => true,
		HomepagePlugin::class => true,
	];


	public function __construct(PluginManager $pluginManager, User $user)
	{
		$this->pluginManager = $pluginManager;
		$this->user = $user;
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
			if ($this->checkPermission($plugin, $route = Helpers::formatPresenterNameToUri($plugin['name'])) === true) {
				$return[] = [
					'key' => $plugin['service'],
					'title' => $plugin['label'],
					'priority' => $plugin['priority'],
					'link' => 'admin/' . $route,
					'icon' => $plugin['icon'] ?? 'fa fa-warning tx-danger',
					'child' => [],
				];
			}
		}

		usort($return, fn (array $a, array $b): int => $a['priority'] < $b['priority'] ? 1 : -1);

		return $return;
	}


	/**
	 * Check user permission by fallback cascade:
	 *
	 * - Call route to plugin as privilege (for ex. "file-manager")
	 * - Check plugin required roles
	 * - Check plugin required privileges
	 * - Is superuser? If yes, allow always
	 *
	 * @param mixed[] $plugin
	 */
	private function checkPermission(array $plugin, string $route): bool
	{
		if ($this->user->isAllowed($route) === true) {
			return true;
		}

		foreach ($plugin['roles'] ?? [] as $role) {
			if ($this->user->isInRole((string) $role) === true) {
				return true;
			}
		}
		foreach ($plugin['privileges'] ?? [] as $privilege) {
			if ($this->user->isAllowed((string) $privilege) === true) {
				return true;
			}
		}

		return $this->user->isInRole('admin');
	}
}
