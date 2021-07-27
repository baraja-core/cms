<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\AdminBar\Panel\BasicPanel;
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
use Nette\Utils\FileSystem;

final class Context
{
	/** @var string[] (type => path) */
	private array $customAssets = [];

	private ConfigurationSection $config;


	public function __construct(
		private string $tempDir,
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


	public function getPluginByType(string $type): Plugin
	{
		return $this->pluginManager->getPluginByType($type);
	}


	/**
	 * @return PluginComponent[]
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
				return (string) $info['service'];
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


	public function getTempDir(): string
	{
		static $checked = false;
		if ($checked === false) {
			if (is_dir($this->tempDir) === false) {
				FileSystem::createDir($this->tempDir);
			}
			$checked = true;
		}

		return $this->tempDir;
	}


	public function isBot(): bool
	{
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
		$dd->setCache(new DoctrineBridge(new FilesystemCache($this->getTempDir() . '/device-detector')));
		$dd->skipBotDetection();
		$dd->parse();

		return $dd;
	}


	public function getIntegrityWorkflow(): IntegrityWorkflow
	{
		static $service;
		if ($service === null) {
			$service = new IntegrityWorkflow($this->user);
			$service->addRunEvent(function (): void {
				$metaManager = new UserMetaManager($this->entityManager, $this->userManager->get());
				$metaManager->set(
					(int) $this->user->getId(),
					'last-activity',
					date('Y-m-d H:i:s'),
				);
			});
			$service->addRunEvent(function (): void {
				$hash = Session::get(Session::WORKFLOW_PASSWORD_HASH);
				$identity = $this->userManager->get()->getCmsIdentity();
				if ($identity !== null) {
					$newHash = md5($identity->getPassword());
					if ($hash === null) {
						Session::set(Session::WORKFLOW_PASSWORD_HASH, $newHash);
					} elseif ($hash !== $newHash) {
						$this->user->logout(true);
						Session::removeAll();
					}
				}
			});
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
