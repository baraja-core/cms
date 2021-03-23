<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare\Bridge;


use Baraja\AdminBar\AdminBar;
use Baraja\Cms\LinkGenerator;
use Baraja\Cms\MenuAuthorizatorAccessor;
use Nette\Security\User;

final class AdminBarBridge
{
	public function __construct(
		private LinkGenerator $linkGenerator,
		private User $user,
		private MenuAuthorizatorAccessor $menuAuthorizator,
	) {
	}


	public function setup(): void
	{
		$menu = AdminBar::getBar()->getMenu();

		// Show link only in case of user can edit profile
		try {
			$allowUserOverview = $this->menuAuthorizator->get()->isAllowedComponent('user', 'user-overview');
		} catch (\RuntimeException) {
			$allowUserOverview = false;
		}
		if ($allowUserOverview === true) {
			$menu->addLink(
				'My Profile',
				$this->linkGenerator->link('User:detail', [
					'id' => $this->user->getId(),
				]),
				'user',
			);
		}

		$menu->addLink('Settings', $this->linkGenerator->link('Settings:default'), 'ui');
		$menu->addLink('Sign out', $this->linkGenerator->link('Cms:signOut'), 'ui');
	}
}
