<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Doctrine\EntityManager;
use Baraja\DoctrineConfiguration\Option;
use Baraja\DynamicConfiguration\Configuration;
use Baraja\Localization\Localization;
use Baraja\Url\Url;
use Latte\Engine;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\Validators;

final class Settings
{
	private Cache $cache;


	public function __construct(
		private EntityManager $entityManager,
		private Localization $localization,
		private Configuration $configuration,
		private CloudManager $cloudManager,
		Storage $storage,
	) {
		$this->cache = new Cache($storage, 'cms-settings');
	}


	public function isOk(): bool
	{
		try {
			return $this->isDatabaseConnectionOk() && $this->isCloudConnectionOk() && $this->isBasicConfigurationOk();
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
		return $this->configuration->get($key, 'core');
	}


	public function setConfiguration(string $key, ?string $value): void
	{
		$this->configuration->save($key, $value, 'core');
	}


	/**
	 * @throws \Throwable
	 */
	public function isDatabaseConnectionOk(): bool
	{
		if ($this->cache->load('database-connection') === true) {
			return true;
		}

		$status = Validators::isNumericInt(
			$this->entityManager->getRepository(Option::class)
				->createQueryBuilder('option')
				->select('COUNT(option.id)')
				->getQuery()
				->getSingleScalarResult(),
		);

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
		if ($this->configuration->get('name', 'core') !== null) {
			$this->cache->save('configuration', true);

			return true;
		}

		return false;
	}


	public function cleanCache(): void
	{
		$this->cache->clean([Cache::ALL => true]);
	}
}
