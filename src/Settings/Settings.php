<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Doctrine\EntityManager;
use Baraja\DoctrineConfiguration\Option;
use Baraja\DynamicConfiguration\Configuration;
use Baraja\Localization\Localization;
use Latte\Engine;
use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\Utils\Validators;

final class Settings
{

	/** @var Cache */
	private $cache;

	/** @var EntityManager */
	private $entityManager;

	/** @var Localization */
	private $localization;

	/** @var Configuration */
	private $configuration;

	/** @var CloudManager */
	private $cloudManager;


	public function __construct(IStorage $storage, EntityManager $entityManager, Localization $localization, Configuration $configuration, CloudManager $cloudManager)
	{
		$this->cache = new Cache($storage, 'cms-settings');
		$this->entityManager = $entityManager;
		$this->localization = $localization;
		$this->configuration = $configuration;
		$this->cloudManager = $cloudManager;
	}


	public function isOk(): bool
	{
		try {
			return $this->isDatabaseConnectionOk() && $this->isCloudConnectionOk() && $this->isBasicConfigurationOk();
		} catch (\Throwable $e) {
			return false;
		}
	}


	public function run(): string
	{
		$url = Helpers::getCurrentUrl();
		$databaseException = null;
		try {
			$dbOk = $this->isDatabaseConnectionOk();
		} catch (\Throwable $e) {
			$dbOk = false;
			$databaseException = $e;
		}

		if ($dbOk === false) {
			return (new Engine)
				->renderToString(__DIR__ . '/../../template/install-database.latte', [
					'basePath' => Helpers::getBaseUrl(),
					'locale' => $this->localization->getLocale(),
					'isCloudHost' => (bool) preg_match('/^.+?\.ondigitalocean\.com$/', $host = $this->entityManager->getConnection()->getParams()['host'] ?? ''),
					'host' => $host,
					'user' => $this->entityManager->getConnection()->getParams()['user'] ?? '?',
					'exception' => $databaseException,
				]);
		}
		if ($this->isCloudConnectionOk() === false) {
			return (new Engine)
				->renderToString(__DIR__ . '/../../template/install-cloud-connection.latte', [
					'basePath' => Helpers::getBaseUrl(),
					'locale' => $this->localization->getLocale(),
				]);
		}
		if ($this->isBasicConfigurationOk() === false) {
			return (new Engine)
				->renderToString(__DIR__ . '/../../template/install-basic.latte', [
					'basePath' => Helpers::getBaseUrl(),
					'locale' => $this->localization->getLocale(),
					'isLocalhost' => strpos($url, 'localhost') !== false,
					'isBarajaCz' => strpos($url, 'baraja.cz') !== false,
				]);
		}

		return '<p>Configuration is broken, please clear cache and try load this page again.</p>';
	}


	public function getProjectName(): ?string
	{
		return $this->configuration->get('name', 'core');
	}


	public function getAdminEmail(): ?string
	{
		return $this->configuration->get('admin-email', 'core');
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
				->getSingleScalarResult()
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
		} catch (\InvalidArgumentException $e) {
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
