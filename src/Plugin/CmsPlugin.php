<?php

declare(strict_types=1);

namespace Baraja\Cms\Plugin;


use Baraja\Cms\User\UserManager;
use Baraja\Plugin\BasePlugin;

final class CmsPlugin extends BasePlugin
{
	public function __construct(
		private UserManager $userManager,
	) {
	}


	public function getName(): string
	{
		return 'CMS';
	}


	public function getLabel(): string
	{
		return 'CMS';
	}


	public function getBaseEntity(): ?string
	{
		return null;
	}


	public function actionSignOut(): void
	{
		$this->userManager->logout();
		$this->redirect('');
	}
}
