<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Settings\SystemInfo;
use Baraja\Cms\User\UserManagerAccessor;
use Baraja\DoctrineConfiguration\Option;
use Baraja\DoctrineConfiguration\OptionRepository;
use Baraja\DynamicConfiguration\Configuration;
use Baraja\DynamicConfiguration\ConfigurationSection;
use Baraja\Localization\Localization;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Caching\Cache;
use Nette\Caching\Storage;

final class Settings
{
	private Cache $cache;

	private ConfigurationSection $config;


	public function __construct(
		private EntityManagerInterface $entityManager,
		private Localization $localization,
		private CloudManager $cloudManager,
		private UserManagerAccessor $userManager,
		private string $currentVersion,
		Storage $storage,
		Configuration $configuration,
	) {
		$this->cache = new Cache($storage, 'cms-settings');
		$this->config = new ConfigurationSection($configuration, 'core');
	}


	public function getSystemInfo(): SystemInfo
	{
		return new SystemInfo($this->userManager);
	}


	public function isOk(): bool
	{
		try {
			return $this->isDatabaseConnectionOk() && $this->isBasicConfigurationOk();
		} catch (\Throwable) {
			return false;
		}
	}


	public function runInstallProcess(): string
	{
		return (new InstallProcess($this, $this->localization, $this->entityManager))->run();
	}


	public function getProjectName(): ?string
	{
		return $this->getOption('name');
	}


	public function getAdminEmail(): ?string
	{
		return $this->getOption('admin-email');
	}


	public function getOption(string $key): ?string
	{
		return $this->config->get($key);
	}


	public function setConfiguration(string $key, ?string $value): void
	{
		$this->config->save($key, $value);
	}


	/**
	 * @throws \Throwable
	 */
	public function isDatabaseConnectionOk(): bool
	{
		if ($this->cache->load('database-connection') === true) {
			return true;
		}

		/** @var OptionRepository $optionRepository */
		$optionRepository = $this->entityManager->getRepository(Option::class);

		$status = $optionRepository->isOptionExist();
		if ($status === true) {
			$this->cache->save('database-connection', true);
		}

		return $status;
	}


	public function isCloudConnectionOk(): bool
	{
		if ($this->cache->load('cloud-connection') === true) {
			return true;
		}
		try {
			if ($this->cloudManager->isConnectionOk() === true) {
				$this->cache->save('cloud-connection', true);

				return true;
			}
		} catch (\InvalidArgumentException) {
			// Silence is golden.
		}

		return false;
	}


	public function isBasicConfigurationOk(): bool
	{
		if ($this->cache->load('configuration') === true) {
			return true;
		}
		if ($this->config->get('name') !== null) {
			$this->cache->save('configuration', true);

			return true;
		}

		return false;
	}


	/**
	 * @deprecated since 2021-11-15, in future it will be implemented without cache.
	 */
	public function cleanCache(): void
	{
		$this->cache->clean([Cache::ALL => true]);
	}


	public function getCurrentVersion(): string
	{
		return $this->currentVersion;
	}
}
