<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy;


use Baraja\AssetsLoader\Minifier\DefaultJsMinifier;
use Baraja\Cms\Configuration;
use Baraja\Cms\Context;
use Baraja\Cms\Helpers;
use Baraja\Plugin\Plugin;
use Baraja\Url\Url;

/**
 * Smart proxy service for render all plugin components to single javascript.
 */
final class Proxy
{
	public const CONTENT_TYPES = [
		'js' => 'application/javascript',
		'css' => 'text/css',
		'ico' => 'image/x-icon',
		'txt' => 'text/plain',
		'map' => 'text/plain',
	];

	public const TEXTUAL_FILE_EXTENSIONS = [
		'js' => true,
		'css' => true,
		'txt' => true,
		'html' => true,
		'php' => true,
	];


	public function __construct(
		private Context $context,
	) {
	}


	public static function getUrl(string $file): string
	{
		if (str_contains($file, '..')) {
			throw new \LogicException(sprintf('File path "%s" is not safe.', $file));
		}
		$diskPath = sprintf('%s/../../template/assets/%s', __DIR__, $file);
		if (is_file($diskPath) === false) {
			throw new \InvalidArgumentException(sprintf('Static file "%s" does not exist. Path "%s" given.', $file, $diskPath));
		}

		return sprintf('%s/%s/assets/%s', Url::get()->getBaseUrl(), Configuration::get()->getBaseUriEscaped(), $file);
	}


	/**
	 * Render dynamic Vue components to requested file.
	 *
	 * @return never-return
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
				if (str_ends_with($content, '})')) {
					$content .= ';';
				}
				$return .= $content . "\n\n\n";
			}
		}

		return $return;
	}


	/**
	 * @return never-return
	 */
	private function responsePluginJsAsset(string $path): void
	{
		header('Content-Type: ' . self::CONTENT_TYPES['js']);

		if (preg_match('/^cms-web-loader\/(.+)\.js$/', $path, $parser) === 1) {
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


	/**
	 * @return never-return
	 */
	private function responseStaticAsset(string $path): void
	{
		if (
			preg_match(
				'/^assets\/static-file-proxy\/(?<hash>[a-f0-9]{32})\.(?<extension>[^.]+)$/',
				$path,
				$pathParser,
			) === 1
		) {
			$hash = $pathParser['hash'] ?? '';
			$fileName = '';
			$extension = $pathParser['extension'] ?? throw new \LogicException('Format does not exist.');
			$assetMap = $this->context->getCustomGlobalAssetMap();
			if (isset($assetMap[$hash])) {
				$assetPath = $assetMap[$hash];
			} else {
				die;
			}
		} elseif (
			preg_match(
				'~^assets/(?<filename>([a-z0-9/.-]+)\.(?<extension>[^.]+?))$~',
				$path,
				$pathParser,
			) === 1
		) {
			$extension = $pathParser['extension'] ?? throw new \LogicException('Format does not exist.');
			$fileName = $pathParser['filename'] ?? throw new \LogicException('Filename does not exist.');
			$assetPath = __DIR__ . '/../../template/assets/' . $fileName;
			if (!is_file($assetPath)) {
				header(sprintf('Content-Type: %s', self::CONTENT_TYPES['txt']));
				echo 'Error 404' . "\n\n";
				echo sprintf('File "%s" does not exist. Make sure you are loading the correct URL.', htmlspecialchars($path));
				echo "\n\n" . 'Rendered by </BRJ> CMS.';
				die;
			}
		} else {
			die;
		}

		if (isset(self::CONTENT_TYPES[$extension])) {
			header(sprintf('Content-Type: %s', self::CONTENT_TYPES[$extension]));
		} else {
			throw new \OutOfRangeException(sprintf(
				'Invalid content type, because unknown format "%s" given. Did you mean "%s"?',
				$extension,
				implode('", "', array_keys(self::CONTENT_TYPES)),
			));
		}

		$modificationTime = (int) filemtime($assetPath);
		$tsString = gmdate('D, d M Y H:i:s ', $modificationTime) . 'GMT';
		$etag = 'EN' . $modificationTime;
		if (
			($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag
			&& ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') === $tsString
		) {
			header('HTTP/1.1 304 Not Modified');
			die;
		}
		header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 86_400)); // 1 day
		header('Last-Modified: ' . $tsString);
		header('ETag: "' . md5($etag) . '"');

		$return = (string) file_get_contents($assetPath);
		if ($fileName === 'core.css' || $fileName === 'core.js') {
			$customAssetPath = $this->context->getCustomAssetPath($extension);
			if ($customAssetPath !== null) {
				$return .= "\n\n" . file_get_contents($customAssetPath);
			}
		}
		if (isset(self::TEXTUAL_FILE_EXTENSIONS[$extension])) {
			if (str_contains($path, '.min') === false) {
				if ($extension === 'css') {
					$return = Helpers::minifyHtml($return);
				} elseif ($extension === 'js' && \class_exists(DefaultJsMinifier::class)) {
					$return = (new DefaultJsMinifier)->minify($return);
				}
			}
			echo '/*' . "\n"
				. ' * This file is part of Baraja CMS.' . "\n"
				. ' */' . "\n\n";
		}
		echo $return;
		die;
	}
}
