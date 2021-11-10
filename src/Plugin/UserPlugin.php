<?php

declare(strict_types=1);

namespace Baraja\Cms\Plugin;


use Baraja\AdminBar\AdminBar;
use Baraja\Cms\Search\SearchablePlugin;
use Baraja\Cms\Session;
use Baraja\Cms\User\AdminBar\LoginAsUserPanel;
use Baraja\Cms\User\Entity\CmsUser;
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


	/**
	 * @return class-string<CmsUser>
	 */
	public function getBaseEntity(): string
	{
		return $this->userManager->getDefaultEntity();
	}


	public function getIcon(): ?string
	{
		return 'person-circle';
	}


	public function actionDefault(): void
	{
		$this->setTitle('User manager');
	}


	public function actionDetail(int $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->error();
		}

		$currentIdentity = $this->userManager->getIdentity();
		if ($currentIdentity !== null && $currentIdentity->getId() !== $id) {
			AdminBar::getBar()->addPanel(new LoginAsUserPanel($user->getId()));
		}

		$this->setTitle(
			sprintf(
				'(%s) %s %s',
				(string) $user->getId(),
				$this->userManager->isOnline($id) ? '[ONLINE]' : '',
				$user->getName()
			)
		);
		$this->setSubtitle($user->getEmail());
		$this->setLinkBack($this->link('Article:default'));

		$this->addBreadcrumb(new Breadcrumb('Dashboard', $this->link('Homepage:default')));
		$this->addBreadcrumb(new Breadcrumb('User', $this->link('User:default')));
		$this->addBreadcrumb(new Breadcrumb($user->getName()));
	}


	public function actionMe(): void
	{
		$identity = $this->userManager->getIdentity();
		if ($identity !== null) {
			$this->redirect($this->link('User:detail', [
				'id' => $identity->getId(),
			]));
		}
		$this->redirect(Url::get()->getBaseUrl() . '/admin');
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
			Session::remove(Session::LAST_IDENTITY_ID);
		}
		$this->redirect(Url::get()->getBaseUrl());
	}


	/** @return array<int, string> */
	public function getSearchColumns(): array
	{
		return [':username(name)', '!firstName', '!lastName', 'nick', 'email'];
	}
}
