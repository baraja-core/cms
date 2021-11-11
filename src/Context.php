<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\AdminBar\Panel\BasicPanel;
use Baraja\Cms\Container\Container;
use Baraja\Cms\MiddleWare\IntegrityWorkflow;
use Baraja\Cms\Proxy\GlobalAsset\CmsAsset;
use Baraja\Cms\Proxy\GlobalAsset\CustomGlobalAssetManagerAccessor;
use Baraja\Cms\Translator\TranslatorFilter;
use Baraja\Cms\User\UserManagerAccessor;
use Baraja\Cms\User\UserMetaManager;
use Baraja\Doctrine\Cache\FilesystemCache;
use Baraja\Doctrine\EntityManager;
use Baraja\DynamicConfiguration\Configuration;
use Baraja\DynamicConfiguration\ConfigurationSection;
use Baraja\Localization\Localization;
use Baraja\Plugin\Component\PluginComponent;
use Baraja\Plugin\Plugin;
use Baraja\Plugin\PluginManager;
use DeviceDetector\Cache\DoctrineBridge;
use DeviceDetector\DeviceDetector;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Security\User;
use Psr\Container\ContainerInterface;

final class Context implements ContainerInterface
{
	/** @var array<string, string> (type => path) */
	private array $customAssets = [];

	private ConfigurationSection $config;

	private Container $container;


	public function __construct(
		private Request $request,
		private Response $response,
		private EntityManager $entityManager,
		private Settings $settings,
		private User $user,
		private TranslatorFilter $translatorFilter,
		private BasicPanel $basicInformation,
		private PluginManager $pluginManager,
		private MenuAuthorizatorAccessor $authorizator,
		private UserManagerAccessor $userManager,
		private CustomGlobalAssetManagerAccessor $customGlobalAssetManager,
		private Localization $localization,
		Configuration $configuration,
	) {
		$this->config = new ConfigurationSection($configuration, 'core');
		$localization->setContextLocale($localization->getDefaultLocale());
		$this->container = new Container($pluginManager);
	}


	public function get(string $id): object
	{
		return $this->container->get($id);
	}


	public function has(string $id): bool
	{
		return $this->container->has($id);
	}


	public function getContainer(): Container
	{
		return $this->container;
	}


	public function getPluginManager(): PluginManager
	{
		return $this->pluginManager;
	}


	public function getPluginByName(string $name): Plugin
	{
		return $this->pluginManager->getPluginByName($name);
	}


	public function getPluginNameByType(Plugin $type): string
	{
		return $this->pluginManager->getPluginNameByType($type);
	}


	/**
	 * @param class-string $type
	 */
	public function getPluginByType(string $type): Plugin
	{
		return $this->pluginManager->getPluginByType($type);
	}


	/**
	 * @return array<int, PluginComponent>
	 */
	public function getComponents(Plugin $plugin, string $view): array
	{
		return $this->pluginManager->getComponents($plugin, $view);
	}


	/**
	 * Find Plugin service name (key).
	 */
	public function getPluginKey(Plugin $plugin): string
	{
		$type = $plugin::class;
		foreach ($this->pluginManager->getPluginInfo() as $info) {
			if ($info['type'] === $type) {
				return $info['service'];
			}
		}

		throw new \RuntimeException('Plugin info for "' . $type . '" does not exist.');
	}


	public function getRequest(): Request
	{
		return $this->request;
	}


	public function getResponse(): Response
	{
		return $this->response;
	}


	public function getEntityManager(): EntityManager
	{
		return $this->entityManager;
	}


	public function getUser(): User
	{
		return $this->user;
	}


	public function getConfiguration(): ConfigurationSection
	{
		return $this->config;
	}


	public function getTranslatorFilter(): TranslatorFilter
	{
		return $this->translatorFilter;
	}


	public function getLocale(): string
	{
		return $this->localization->getLocale(true);
	}


	public function setLocale(string $locale): void
	{
		$this->localization->setContextLocale($locale);
	}


	public function getBasicInformation(): BasicPanel
	{
		return $this->basicInformation;
	}


	public function getSettings(): Settings
	{
		return $this->settings;
	}


	/**
	 * @deprecated since 2021-10-22 use configuration service.
	 */
	public function getTempDir(): string
	{
		return $this->container->getConfiguration()->getTempDir();
	}


	public function isBot(): bool
	{
		if (PHP_SAPI === 'cli') {
			return false;
		}
		$isBot = Session::get(Session::WORKFLOW_IS_BOT);
		$cacheUserAgent = Session::get(Session::WORKFLOW_USER_AGENT);
		$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		if ($isBot !== null && $cacheUserAgent === $userAgent) {
			return (bool) $isBot;
		}
		$isBot = $this->getDeviceDetector()->isBot();
		Session::set(Session::WORKFLOW_USER_AGENT, $userAgent);
		Session::set(Session::WORKFLOW_IS_BOT, $isBot);

		return $isBot;
	}


	public function getDeviceDetector(): DeviceDetector
	{
		$dd = new DeviceDetector($_SERVER['HTTP_USER_AGENT'] ?? '');
		$dd->setCache(
			new DoctrineBridge(
				new FilesystemCache($this->container->getConfiguration()->getTempDir() . '/device-detector')
			)
		);
		$dd->skipBotDetection();
		$dd->parse();

		return $dd;
	}


	public function getIntegrityWorkflow(): IntegrityWorkflow
	{
		static $service;
		if ($service === null) {
			$service = new IntegrityWorkflow($this->user);
			$service->addRunEvent(
				function (): void {
					$identity = $this->userManager->get()->getIdentity();
					assert($identity !== null);
					$metaManager = new UserMetaManager($this->entityManager, $this->userManager->get());
					$metaManager->set(
						$identity->getId(),
						'last-activity',
						date('Y-m-d H:i:s'),
					);
				}
			);
			$service->addRunEvent(
				function (): void {
					$hash = Session::get(Session::WORKFLOW_PASSWORD_HASH);
					$identity = $this->userManager->get()->getIdentity();
					if ($identity !== null) {
						$newHash = md5($identity->getPassword());
						if ($hash === null) {
							Session::set(Session::WORKFLOW_PASSWORD_HASH, $newHash);
						} elseif ($hash !== $newHash) {
							$this->user->logout(true);
							Session::removeAll();
						}
					}
				}
			);
		}

		return $service;
	}


	public function checkPermission(string $plugin, ?string $view = null): bool
	{
		$pluginName = Helpers::formatPresenterNameToUri($plugin);
		if ($pluginName === 'cms') {
			return true;
		}
		try {
			return $view === null
				? $this->authorizator->get()->isAllowedPlugin($pluginName)
				: $this->authorizator->get()->isAllowedComponent($pluginName, $view);
		} catch (\Throwable $e) {
			if ($e->getCode() === 404) { // Identity is broken or user does not exist
				$this->user->logout(true);
			} else {
				trigger_error('Can not check permissions: ' . htmlspecialchars($e->getMessage()));
			}
		}

		return false;
	}


	public function getCustomAssetPath(string $type): ?string
	{
		return $this->customAssets[$type] ?? null;
	}


	/**
	 * @return CmsAsset[]
	 */
	public function getCustomGlobalAssetPaths(): array
	{
		return $this->customGlobalAssetManager->get()->getAssets();
	}


	/**
	 * @return array<string, string> (hash => path)
	 */
	public function getCustomGlobalAssetMap(): array
	{
		return $this->customGlobalAssetManager->get()->getDiskPathsMap();
	}


	/**
	 * @internal
	 */
	public function setCustomAssetPath(string $type, string $path): void
	{
		if (isset($this->customAssets[$type]) === true) {
			throw new \RuntimeException('Custom asset "' . $type . '" already exist.');
		}
		$this->customAssets[$type] = $path;
	}


	public function getUserManager(): UserManagerAccessor
	{
		return $this->userManager;
	}
}
