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
		$databaseException = null;
		try {
			$dbOk = $this->settings->isDatabaseConnectionOk();
		} catch (\Throwable $e) {
			$dbOk = false;
			$databaseException = $e;
		}

		if ($dbOk === false) {
			return (new Engine)
				->renderToString(__DIR__ . '/../../template/install-database.latte', [
					'basePath' => Url::get()->getBaseUrl(),
					'locale' => $this->localization->getLocale(),
					'isCloudHost' => (bool) preg_match('/^.+?\.ondigitalocean\.com$/', $host = $this->entityManager->getConnection()->getParams()['host'] ?? ''),
					'host' => $host,
					'user' => $this->entityManager->getConnection()->getParams()['user'] ?? '?',
					'exception' => $databaseException,
				]);
		}
		if ($this->settings->isCloudConnectionOk() === false) {
			return (new Engine)
				->renderToString(__DIR__ . '/../../template/install-cloud-connection.latte', [
					'basePath' => Url::get()->getBaseUrl(),
					'locale' => $this->localization->getLocale(),
				]);
		}
		if ($this->settings->isBasicConfigurationOk() === false) {
			return (new Engine)
				->renderToString(__DIR__ . '/../../template/install-basic.latte', [
					'basePath' => Url::get()->getBaseUrl(),
					'locale' => $this->localization->getLocale(),
					'isLocalhost' => strpos($url, 'localhost') !== false,
					'isBarajaCz' => strpos($url, 'baraja.cz') !== false,
				]);
		}

		return '<p>Configuration is broken, please clear cache and try load this page again.</p>';
	}
}
