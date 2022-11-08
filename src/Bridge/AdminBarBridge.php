<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare\Bridge;


use Baraja\AdminBar\AdminBar;
use Baraja\CAS\User;
use Baraja\Cms\Admin;
use Baraja\Cms\LinkGenerator;
use Baraja\Cms\MenuAuthorizatorAccessor;
use Baraja\Cms\Session;
use Baraja\Cms\User\AdminBar\BackToLastIdentityPanel;
use Baraja\Url\Url;

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
		if ($this->user->isLoggedIn() === false) {
			return;
		}
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

		if (Admin::isAdminRequest()) {
			$menu->addEvent('Settings', 'cms-settings-open', 'ui');
		}
		$menu->addLink('Sign out', $this->linkGenerator->link('Cms:signOut', nonce: true), 'ui');

		if ($this->user->getIdentity() !== null) {
			if (Session::get(Session::LAST_IDENTITY_ID) !== null) {
				AdminBar::getBar()->addPanel(new BackToLastIdentityPanel($this->user));
			}
			if (AdminBar::getBar()->isDebugMode() === false) {
				$url = new \Nette\Http\Url(Url::get()->getUrlScript());
				$url->setQueryParameter('debugMode', '1');
				$menu->addLink('Debug mode', $url->getAbsoluteUrl(), 'ui');
			}
		}
	}
}
