<?php

declare(strict_types=1);

namespace Baraja\Cms\User\AdminBar;


use Baraja\AdminBar\Panel\Panel;
use Baraja\CAS\User;
use Baraja\Cms\LinkGenerator;
use Baraja\Cms\Session;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class BackToLastIdentityPanel implements Panel
{
	public function __construct(
		private User $user,
	) {
	}


	public function getTab(): string
	{
		$userId = Session::get(Session::LAST_IDENTITY_ID);
		if ($userId === null || is_int($userId) === false) {
			return '';
		}
		try {
			$user = $this->user->getUserStorage()->getUserById($userId);
		} catch (NoResultException|NonUniqueResultException) {
			return '';
		}

		$linkBack = LinkGenerator::generateInternalLink('User:loginAs', ['id' => $user->getId()]);
		$linkForgot = LinkGenerator::generateInternalLink('User:forgotLastIdentity', ['id' => $user->getId()]);

		return '<a href="' . $linkBack . '" class="btn btn-primary" style="background:#17a2b8 !important" title="Switch the current login session back to the last user\'s profile.">'
			. 'Back to <b style="color:#000 !important">' . htmlspecialchars($user->getName()) . '</b>'
			. '</a>&nbsp;&nbsp;&nbsp;'
			. '<a href="' . $linkForgot . '" class="btn btn-primary" style="background:#17a2b8 !important" title="Forget information about previous identity. You will no longer be able to return to the user.">'
			. 'Forgot last identity'
			. '</a>';
	}


	public function getBody(): ?string
	{
		return null;
	}


	public function getPriority(): ?int
	{
		return 99;
	}
}
