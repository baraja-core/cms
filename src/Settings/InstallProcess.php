<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\AdminBar\AdminBar;
use Baraja\Localization\Localization;
use Baraja\Url\Url;
use Doctrine\ORM\EntityManagerInterface;
use Latte\Engine;

final class InstallProcess
{
	public function __construct(
		private Settings $settings,
		private Localization $localization,
		private EntityManagerInterface $entityManager,
	) {
	}


	public function run(): string
	{
		AdminBar::enable(AdminBar::MODE_DISABLED);
		$url = Url::get()->getCurrentUrl();
		try {
			$this->settings->isDatabaseConnectionOk();
		} catch (\Throwable $databaseException) {
			/** @var string $host */
			$host = $this->entityManager->getConnection()->getParams()['host'] ?? '';

			return (new Engine)
				->renderToString(__DIR__ . '/../../template/install-database.latte', [
					'basePath' => Url::get()->getBaseUrl(),
					'locale' => $this->localization->getLocale(),
					'isCloudHost' => str_ends_with($host, '.ondigitalocean.com'),
					'host' => $host,
					'user' => $this->entityManager->getConnection()->getParams()['user'] ?? '?',
					'exception' => $databaseException,
				]);
		}
		if ($this->settings->isBasicConfigurationOk() === false) {
			return (new Engine)
				->renderToString(__DIR__ . '/../../template/install-basic.latte', [
					'basePath' => Url::get()->getBaseUrl(),
					'locale' => $this->localization->getLocale(),
					'isLocalhost' => str_contains($url, 'localhost'),
					'isBrj' => str_contains($url, 'brj.cz') || str_contains($url, 'baraja.cz'),
				]);
		}

		return '<p>Configuration is broken, please clear cache and try load this page again.</p>';
	}
}
