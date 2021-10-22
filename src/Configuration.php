<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\PathResolvers\Resolvers\RootDirResolver;
use Baraja\PathResolvers\Resolvers\VendorResolver;
use Nette\Utils\FileSystem;
use Tracy\Debugger;

/**
 * This is the entity for defining the general global configuration of the CMS.
 * All settings are available globally as a singleton, so consider overriding them.
 */
final class Configuration
{
	private string $rootDir;

	private string $tempDir;

	private string $cacheDir;

	private string $wwwDir;

	private string $baseUri = 'admin';

	/** @var array<int, string> */
	private array $supportedLocales = ['cs', 'en'];

	private bool $debugMode;


	private function __construct()
	{
		$this->rootDir = (new RootDirResolver(new VendorResolver))->get();
		$this->tempDir = $this->rootDir . '/temp';
		$this->cacheDir = $this->rootDir . '/temp/cache';
		$this->wwwDir = $this->rootDir . '/www';
		FileSystem::createDir($this->tempDir);
		FileSystem::createDir($this->cacheDir);
		$this->debugMode = class_exists(Debugger::class) && Debugger::isEnabled();
	}


	public static function get(): self
	{
		static $singleton;
		if ($singleton === null) {
			$singleton = new self;
		}

		return $singleton;
	}


	public function getRootDir(): string
	{
		return $this->rootDir;
	}


	public function setRootDir(string $rootDir): void
	{
		$this->rootDir = $rootDir;
	}


	public function getTempDir(): string
	{
		return $this->tempDir;
	}


	public function setTempDir(string $tempDir): void
	{
		if (is_file($tempDir) === false) {
			throw new \InvalidArgumentException('Directory "' . $tempDir . '" does not exist.');
		}
		$this->tempDir = $tempDir;
	}


	public function getCacheDir(): string
	{
		return $this->cacheDir;
	}


	public function setCacheDir(string $cacheDir): void
	{
		if (is_file($cacheDir) === false) {
			throw new \InvalidArgumentException('Directory "' . $cacheDir . '" does not exist.');
		}
		$this->cacheDir = $cacheDir;
	}


	public function getWwwDir(): string
	{
		return $this->wwwDir;
	}


	public function setWwwDir(string $wwwDir): void
	{
		if (is_file($wwwDir) === false) {
			throw new \InvalidArgumentException('Directory "' . $wwwDir . '" does not exist.');
		}
		$this->wwwDir = $wwwDir;
	}


	public function getBaseUriEscaped(): string
	{
		static $cache = [];
		if (isset($cache[$this->baseUri]) === false) {
			$cache[$this->baseUri] = urlencode($this->baseUri);
		}

		return $cache[$this->baseUri];
	}


	public function getBaseUri(): string
	{
		return $this->baseUri;
	}


	public function setBaseUri(string $baseUri): void
	{
		$this->baseUri = $baseUri;
	}


	/**
	 * @return array<int, string>
	 */
	public function getSupportedLocales(): array
	{
		return $this->supportedLocales;
	}


	/**
	 * @param array<int, string> $supportedLocales
	 */
	public function setSupportedLocales(array $supportedLocales): void
	{
		$this->supportedLocales = $supportedLocales;
	}


	public function isDebugMode(): bool
	{
		return $this->debugMode;
	}


	public function setDebugMode(bool $debugMode): void
	{
		$this->debugMode = $debugMode;
	}
}
