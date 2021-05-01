<?php

declare(strict_types=1);

namespace Baraja\Cms\Plugin;


use Baraja\AdminBar\AdminBar;
use Baraja\Cms\Search\SearchablePlugin;
use Baraja\Cms\User\AdminBar\LoginAsUserPanel;
use Baraja\Cms\User\UserManager;
use Baraja\Plugin\BasePlugin;
use Baraja\Plugin\SimpleComponent\Breadcrumb;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class UserPlugin extends BasePlugin implements SearchablePlugin
{
	public function __construct(
		private UserManager $userManager,
	) {
	}


	public function getName(): string
	{
		return 'Users';
	}


	public function getLabel(): string
	{
		return 'Users';
	}


	public function getBaseEntity(): string
	{
		return $this->userManager->getDefaultEntity();
	}


	public function getIcon(): ?string
	{
		return 'person-circle';
	}


	public function actionDetail(int $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->error();

			return;
		}

		$currentIdentity = $this->userManager->getIdentity();
		if ($currentIdentity !== null && $currentIdentity->getId() !== $id) {
			AdminBar::getBar()->addPanel(new LoginAsUserPanel($user->getId()));
		}

		$this->setTitle($user->getName());
		$this->setLinkBack($this->link('Article:default'));

		$this->addBreadcrumb(new Breadcrumb('Dashboard', $this->link('Homepage:default')));
		$this->addBreadcrumb(new Breadcrumb('User', $this->link('User:default')));
		$this->addBreadcrumb(new Breadcrumb($user->getName()));
	}


	public function actionLoginAs(int $id): void
	{
		try {
			$this->userManager->loginAs($id);
		} catch (\Throwable $e) {
			$this->error($e->getMessage());
		}

		$this->redirect(Url::get()->getBaseUrl());
	}


	public function actionForgotLastIdentity(): void
	{
		if (isset($_SESSION) && session_status() === PHP_SESSION_ACTIVE) {
			unset($_SESSION[UserManager::LAST_IDENTITY_ID__SESSION_KEY]);
		}
		$this->redirect(Url::get()->getBaseUrl());
	}


	/** @return string[] */
	public function getSearchColumns(): array
	{
		return [':username(name)', '!firstName', '!lastName', 'nick', 'email'];
	}
}
