<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\AdminBar\BasicPanel;
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
	private Request $request;

	private Response $response;

	private Localization $localization;

	private EntityManager $entityManager;

	private Configuration $configuration;

	private Settings $settings;

	private User $user;

	private TranslatorFilter $translatorFilter;

	private BasicPanel $basicInformation;

	private PluginManager $pluginManager;

	/** @var string[] (type => path) */
	private array $customAssets = [];


	public function __construct(Request $request, Response $response, Localization $localization, EntityManager $entityManager, Configuration $configuration, Settings $settings, User $user, TranslatorFilter $translatorFilter, BasicPanel $basicInformation, PluginManager $pluginManager)
	{
		$this->request = $request;
		$this->response = $response;
		$this->localization = $localization->setContextLocale($localization->getDefaultLocale());
		$this->entityManager = $entityManager;
		$this->configuration = $configuration;
		$this->settings = $settings;
		$this->user = $user;
		$this->translatorFilter = $translatorFilter;
		$this->basicInformation = $basicInformation;
		$this->pluginManager = $pluginManager;
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


	public function checkPermission(string $plugin, string $view): bool
	{
		return $plugin === 'Homepage' || $plugin === 'Cms' || $plugin === 'Error'
			|| $this->user->isAllowed(Helpers::formatPresenterNameToUri($plugin), $view);
	}


	public function getCustomAssetPath(string $type): ?string
	{
		return $this->customAssets[$type] ?? null;
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
}
