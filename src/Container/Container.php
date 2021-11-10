<?php

declare(strict_types=1);

namespace Baraja\Cms\Container;


use Baraja\Cms\Configuration;
use Baraja\Cms\LinkGenerator;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Plugin\PluginManager;
use Nette\Caching\Storage;
use Nette\Caching\Storages\FileStorage;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Tracy\Bridges\Psr\TracyToPsrLoggerAdapter;
use Tracy\Debugger;

final class Container implements ContainerInterface
{
	private static ?self $singleton = null;

	private Configuration $configuration;

	private ?LoggerInterface $logger = null;

	private ?LinkGenerator $linkGenerator = null;

	private ?CmsPluginPanel $pluginPanel = null;

	private PluginManager $pluginManager;

	/** @var array<string, string> */
	private array $map = [
		'storage' => 'getCacheStorage',
	];


	public function __construct(PluginManager $pluginManager)
	{
		self::$singleton = $this;
		$this->configuration = Configuration::get();
		$this->pluginManager = $pluginManager;
	}


	public function get(string $id): object
	{
		if (isset($this->map[$id])) {
			return $this->{$this->map[$id]}();
		}
		throw new ServiceDoesNotExistException('Service "' . $id . '" does not exist.');
	}


	public function has(string $id): bool
	{
		return isset($this->map[$id]);
	}


	/** @internal */
	public static function getSingleton(): ?self
	{
		return self::$singleton;
	}


	public function getConfiguration(): Configuration
	{
		return $this->configuration;
	}


	public function getCacheStorage(): Storage
	{
		return new FileStorage($this->configuration->getCacheDir() . '/baraja.cms');
	}


	public function getLogger(): LoggerInterface
	{
		if ($this->logger === null) {
			if (class_exists(Debugger::class)) {
				$this->logger = new TracyToPsrLoggerAdapter(Debugger::getLogger());
			} else {
				throw new \LogicException('Logger has not been defined.');
			}
		}

		return $this->logger;
	}


	public function getRequest(): RequestInterface
	{
		throw new \LogicException('Method has not been implemented.');
	}


	public function getResponse(): ResponseInterface
	{
		throw new \LogicException('Method has not been implemented.');
	}


	public function getPluginPanel(): CmsPluginPanel
	{
		if ($this->pluginPanel === null) {
			$this->pluginPanel = new CmsPluginPanel($this->pluginManager);
		}

		return $this->pluginPanel;
	}


	public function getLinkGenerator(): LinkGenerator
	{
		if ($this->linkGenerator === null) {
			$this->linkGenerator = new LinkGenerator;
		}

		return $this->linkGenerator;
	}
}
