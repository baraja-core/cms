<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy;


use Baraja\Plugin\Plugin;
use Baraja\Plugin\PluginManager;

/**
 * Smart proxy service for render all plugin components to single javascript.
 */
final class Proxy
{
	public const CONTENT_TYPES = [
		'js' => 'application/javascript',
		'css' => 'text/css',
	];

	private PluginManager $pluginManager;


	public function __construct(PluginManager $pluginManager)
	{
		$this->pluginManager = $pluginManager;
	}


	/**
	 * Render dynamic Vue components to requested file.
	 */
	public function run(string $path): void
	{
		header('Content-Type: ' . self::CONTENT_TYPES['js']);

		if (preg_match('/^admin-assets\/web-loader\/(.+)\.js$/', $path, $parser)) {
			$return = '/*' . "\n";
			$return .= ' * This file is part of Baraja CMS.' . "\n";
			$return .= ' * Routed by plugin: ' . htmlspecialchars($parser[1]) . "\n";
			$pluginName = $parser[1];
		} else {
			echo '/* error: Plugin name is invalid, path "' . $path . '" given. */';
			die;
		}

		try {
			$plugin = $this->pluginManager->getPluginByName($pluginName);
		} catch (\RuntimeException $e) {
			echo '/* Plugin "' . $pluginName . '" does not exist. */';
			die;
		}

		$return .= ' * MD5 content hash: ' . md5($content = $this->renderContent($plugin)) . "\n";
		$return .= ' */' . "\n\n\n";

		echo $return . $content;
		die;
	}


	/**
	 * Rewrite all plugin components to one javascript file.
	 */
	private function renderContent(Plugin $plugin): string
	{
		$return = '';

		foreach ($this->pluginManager->getComponents($plugin, null) as $component) {
			$return .= '/* Component ' . $component->getKey() . ' */' . "\n";
			if (\is_file($component->getSource()) === true) {
				$content = trim((string) file_get_contents($component->getSource()));

				if (substr($content, -2) === '})') {
					$content .= ';';
				}

				$return .= $content . "\n\n\n";
			}
		}

		return $return;
	}
}
