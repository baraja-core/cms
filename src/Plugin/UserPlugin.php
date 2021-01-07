<?php

declare(strict_types=1);

namespace Baraja\Cms\Plugin;


use Baraja\Cms\User\UserManager;
use Baraja\Plugin\BasePlugin;
use Baraja\Plugin\SimpleComponent\Breadcrumb;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class UserPlugin extends BasePlugin
{
	private UserManager $userManager;


	public function __construct(UserManager $userManager)
	{
		$this->userManager = $userManager;
	}


	public function getName(): string
	{
		return 'Users';
	}


	public function getLabel(): string
	{
		return 'Users';
	}


	public function getBaseEntity(): ?string
	{
		return $this->userManager->getDefaultEntity();
	}


	public function getIcon(): ?string
	{
		return 'person-circle';
	}


	public function actionDetail(string $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->error();

			return;
		}

		$this->setTitle($user->getName());
		$this->setLinkBack($this->link('Article:default'));

		$this->addBreadcrumb(new Breadcrumb('Dashboard', $this->link('Homepage:default')));
		$this->addBreadcrumb(new Breadcrumb('User', $this->link('User:default')));
		$this->addBreadcrumb(new Breadcrumb($user->getName()));
	}
}
