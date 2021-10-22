<?php

declare(strict_types=1);

namespace Baraja\Cms\Container;


use Baraja\Cms\Configuration;
use Baraja\Cms\LinkGenerator;
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
	private Configuration $configuration;

	private ?LoggerInterface $logger = null;

	private ?LinkGenerator $linkGenerator = null;

	/** @var array<string, string> */
	private array $map = [
		'storage' => 'getCacheStorage',
	];


	public function __construct()
	{
		$this->configuration = Configuration::get();
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
	}


	public function getResponse(): ResponseInterface
	{
	}


	public function getLinkGenerator(): LinkGenerator
	{
		if ($this->linkGenerator === null) {
			$this->linkGenerator = new LinkGenerator;
		}

		return $this->linkGenerator;
	}
}
