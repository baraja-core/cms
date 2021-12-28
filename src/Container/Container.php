<?php

declare(strict_types=1);

namespace Baraja\Cms\Container;


use Baraja\Cms\Configuration;
use Baraja\Cms\LinkGenerator;
use Baraja\Cms\MiddleWare\RequestHandler;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Plugin\PluginManager;
use GuzzleHttp\Psr7\ServerRequest;
use Nette\Caching\Storage;
use Nette\Caching\Storages\FileStorage;
use PhpMiddleware\RequestId\Generator\PhpUniqidGenerator;
use PhpMiddleware\RequestId\RequestIdMiddleware;
use PhpMiddleware\RequestId\RequestIdProviderFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

	private ?ServerRequestInterface $serverRequest;

	private ?RequestHandlerInterface $requestHandler;

	private ?RequestIdMiddleware $requestIdMiddleware = null;

	private PluginManager $pluginManager;

	/** @var array<string, string> */
	private array $map = [
		'storage' => 'getCacheStorage',
	];


	public function __construct(
		PluginManager $pluginManager,
		?ServerRequestInterface $serverRequest = null,
		?RequestHandlerInterface $requestHandler = null,
	) {
		self::$singleton = $this;
		$this->serverRequest = $serverRequest;
		$this->requestHandler = $requestHandler;
		$this->pluginManager = $pluginManager;
		$this->getRequestIdMiddleware()->process($this->getServerRequest(), $this->getRequestHandler());
		$this->configuration = Configuration::get();
	}


	public function get(string $id): object
	{
		if (isset($this->map[$id])) {
			/** @phpstan-ignore-next-line */
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


	public function getServerRequest(): ServerRequestInterface
	{
		if ($this->serverRequest === null) {
			$this->serverRequest = ServerRequest::fromGlobals();
		}

		return $this->serverRequest;
	}


	public function getRequestHandler(): RequestHandlerInterface
	{
		if ($this->requestHandler === null) {
			$this->requestHandler = new RequestHandler;
		}

		return $this->requestHandler;
	}


	public function getRequestIdMiddleware(): RequestIdMiddleware
	{
		if ($this->requestIdMiddleware === null) {
			$this->requestIdMiddleware = new RequestIdMiddleware(
				new RequestIdProviderFactory(
					new PhpUniqidGenerator,
				),
			);
		}

		return $this->requestIdMiddleware;
	}


	public function getRequestId(): string
	{
		return $this->getRequestIdMiddleware()->getRequestId();
	}
}
