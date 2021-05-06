<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Doctrine\EntityManager;
use Baraja\Localization\Domain;
use Baraja\Localization\Locale;
use Baraja\StructuredApi\BaseEndpoint;
use Nette\Utils\Strings;
use Nette\Utils\Validators;

final class CmsSettingsEndpoint extends BaseEndpoint
{
	public function __construct(
		private Settings $settings,
		private CloudManager $cloudManager,
		private EntityManager $entityManager,
	) {
	}


	public function actionCommon(): void
	{
		$this->sendJson([
			'isOk' => $this->settings->isBasicConfigurationOk(),
			'projectName' => $this->settings->getProjectName(),
			'adminEmail' => $this->settings->getAdminEmail(),
			'cloudToken' => $this->cloudManager->getToken(),
			'locales' => $this->entityManager->getRepository(Locale::class)
				->createQueryBuilder('locale')
				->select('PARTIAL locale.{id, locale, active, default, position}')
				->orderBy('locale.position')
				->getQuery()
				->getArrayResult(),
			'domains' => $this->entityManager->getRepository(Domain::class)
				->createQueryBuilder('domain')
				->select('PARTIAL domain.{id, https, domain, www, environment, default, protected}')
				->addSelect('PARTIAL locale.{id, locale}')
				->leftJoin('domain.locale', 'locale')
				->orderBy('domain.environment')
				->getQuery()
				->getArrayResult(),
		]);
	}


	public function postSaveCommon(string $projectName, string $adminEmail, string $cloudToken): void
	{
		$projectName = trim($projectName);
		if ($projectName === '') {
			$this->sendError('Project name can not be empty.');
		}
		if (Validators::isEmail($adminEmail) === false) {
			$this->sendError('Admin e-mail must be valid e-mail address.');
		}

		$this->settings->setConfiguration('name', Strings::firstUpper($projectName));
		$this->settings->setConfiguration('admin-email', $adminEmail);
		$this->cloudManager->setToken($cloudToken);

		$this->flashMessage('Common settings has been saved.', 'success');
		$this->sendOk();
	}
}
