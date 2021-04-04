<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy;


use Baraja\AssetsLoader\Minifier\DefaultJsMinifier;
use Baraja\Cms\Context;
use Baraja\Cms\Helpers;
use Baraja\Plugin\Plugin;

/**
 * Smart proxy service for render all plugin components to single javascript.
 */
final class Proxy
{
	public const CONTENT_TYPES = [
		'js' => 'application/javascript',
		'css' => 'text/css',
		'ico' => 'image/x-icon',
	];

	public const TEXTUAL_FILE_EXTENSIONS = [
		'js' => true,
		'css' => true,
		'txt' => true,
		'html' => true,
		'php' => true,
	];


	public function __construct(
		private Context $context
	) {
	}


	/**
	 * Render dynamic Vue components to requested file.
	 */
	public function run(string $path): void
	{
		if (str_starts_with($path, 'assets/')) {
			$this->responseStaticAsset($path);
		}
		$this->responsePluginJsAsset($path);
	}


	/**
	 * Rewrite all plugin components to one javascript file.
	 */
	private function renderContent(Plugin $plugin): string
	{
		$return = '';
		foreach ($this->context->getPluginManager()->getComponents($plugin, null) as $component) {
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


	private function responsePluginJsAsset(string $path): void
	{
		header('Content-Type: ' . self::CONTENT_TYPES['js']);

		if (preg_match('/^cms-web-loader\/(.+)\.js$/', $path, $parser)) {
			$return = '/*' . "\n";
			$return .= ' * This file is part of Baraja CMS.' . "\n";
			$return .= ' * Routed by plugin: ' . htmlspecialchars($parser[1]) . "\n";
			$pluginName = $parser[1];
		} else {
			echo '/* error: Plugin name is invalid, path "' . htmlspecialchars($path) . '" given. */';
			die;
		}

		try {
			$plugin = $this->context->getPluginManager()->getPluginByName($pluginName);
		} catch (\RuntimeException) {
			echo '/* Plugin "' . htmlspecialchars($pluginName) . '" does not exist. */';
			die;
		}

		$return .= ' * MD5 content hash: ' . md5($content = $this->renderContent($plugin)) . "\n";
		$return .= ' */' . "\n\n\n";
		$return .= $content;

		if (\class_exists(DefaultJsMinifier::class)) {
			$return = (new DefaultJsMinifier)->minify($return);
		}

		echo $return;
		die;
	}


	private function responseStaticAsset(string $path): void
	{
		if (preg_match('/^assets\/(?<filename>([a-z0-9\/\-]+)\.(?<extension>[^.]+))$/', $path, $pathParser)) {
			$extension = $pathParser['extension'] ?? throw new \LogicException('Format does not exist.');
			$fileName = $pathParser['filename'] ?? throw new \LogicException('Filename does not exist.');
			$assetPath = __DIR__ . '/../../template/assets/' . $fileName;
			if (!is_file($assetPath)) {
				return;
			}
		} else {
			return;
		}

		if (isset(self::CONTENT_TYPES[$extension])) {
			header('Content-Type: ' . self::CONTENT_TYPES[$extension]);
		} else {
			throw new \OutOfRangeException(
				'Invalid content type, because unknown format "' . $extension . '" given. '
				. 'Did you mean "' . implode('", "', array_keys(self::CONTENT_TYPES)) . '"?',
			);
		}
		$return = file_get_contents($assetPath);
		if ($fileName === 'core.css' || $fileName === 'core.js') {
			$customAssetPath = $this->context->getCustomAssetPath($extension);
			if ($customAssetPath !== null) {
				$return .= "\n\n" . file_get_contents($customAssetPath);
			}
		}
		if (isset(self::TEXTUAL_FILE_EXTENSIONS[$extension])) {
			if ($extension === 'css') {
				$return = Helpers::minifyHtml($return);
			} elseif ($extension === 'js' && \class_exists(DefaultJsMinifier::class)) {
				$return = (new DefaultJsMinifier)->minify($return);
			}
			echo '/*' . "\n"
				. ' * This file is part of Baraja CMS.' . "\n"
				. ' */' . "\n\n";
		}
		echo $return;
		die;
	}
}
