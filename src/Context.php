<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\AdminBar\Panel\BasicPanel;
use Baraja\Cms\Proxy\GlobalAsset\CustomGlobalAssetManagerAccessor;
use Baraja\Cms\Translator\TranslatorFilter;
use Baraja\Cms\User\UserManagerAccessor;
use Baraja\Doctrine\EntityManager;
use Baraja\DynamicConfiguration\Configuration;
use Baraja\Localization\Localization;
use Baraja\Plugin\Component\PluginComponent;
use Baraja\Plugin\Plugin;
use Baraja\Plugin\PluginManager;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Security\User;

final class Context
{
	/** @var string[] (type => path) */
	private array $customAssets = [];


	public function __construct(
		private Request $request,
		private Response $response,
		private EntityManager $entityManager,
		private Configuration $configuration,
		private Settings $settings,
		private User $user,
		private TranslatorFilter $translatorFilter,
		private BasicPanel $basicInformation,
		private PluginManager $pluginManager,
		private MenuAuthorizatorAccessor $authorizator,
		private UserManagerAccessor $userManager,
		private CustomGlobalAssetManagerAccessor $customGlobalAssetManager,
		private Localization $localization,
	) {
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
		$type = \get_class($plugin);
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


	public function getConfiguration(): Configuration
	{
		return $this->configuration;
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
				trigger_error('Can not check permissions: ' . $e->getMessage());
			}
		}

		return false;
	}


	public function getCustomAssetPath(string $type): ?string
	{
		return $this->customAssets[$type] ?? null;
	}


	/**
	 * @return array<string, string> (path => format)
	 */
	public function getCustomGlobalAssetPaths(): array
	{
		return $this->customGlobalAssetManager->get()->toArray();
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
