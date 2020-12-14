<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Plugin\PluginLinkGenerator;

final class LinkGenerator implements PluginLinkGenerator
{

	/**
	 * @param mixed[] $params
	 */
	public static function generateInternalLink(string $route, array $params = []): string
	{
		if (($route[0] ?? '') === ':') {
			throw new \InvalidArgumentException('Route "' . $route . '" can not be absolute. Please remove the starting colon.');
		}

		[$plugin, $view] = explode(':', trim($route) . ':');

		if ($plugin === '') {
			$plugin = 'Homepage';
		}
		if ($view === '') {
			$view = 'default';
		}
		if ($plugin === 'Admin') {
			throw new \InvalidArgumentException('Route "' . $route . '" is potentially bug (because it\'s just the logic of administration). Did you mean "Homepage:default"?');
		}

		$path = '';
		if ($plugin === 'Homepage') {
			if ($view !== 'default') {
				$path = 'homepage/' . Helpers::formatPresenterNameToUri($view);
			}
		} else {
			$path = Helpers::formatPresenterNameToUri($plugin)
				. ($view !== 'default' ? '/' . Helpers::formatPresenterNameToUri($view) : '');
		}

		return Helpers::getBaseUrl() . '/admin' . ($path !== '' ? '/' . $path : '')
			. ($params !== [] ? '?' . http_build_query($params) : '');
	}


	/**
	 * @param mixed[] $params
	 */
	public function link(string $route, array $params = []): string
	{
		return self::generateInternalLink($route, $params);
	}
}
