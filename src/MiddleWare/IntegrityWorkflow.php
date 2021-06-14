<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare;


use Baraja\Cms\User\UserManager;
use Baraja\Cms\User\UserMetaManager;
use Baraja\Doctrine\EntityManager;
use Nette\Security\User;
use Tracy\Debugger;

final class IntegrityWorkflow
{
	private const SESSION_EXPIRE_KEY = '__BRJ_CMS--workflow-check-expiration';

	public function __construct(
		private User $user,
		private EntityManager $entityManager,
		private UserManager $userManager,
	) {
	}


	public static function isNeedRun(): bool
	{
		$checkExpiration = $_SESSION[self::SESSION_EXPIRE_KEY] ?? null;
		if ($checkExpiration === null || ((int) $checkExpiration) < time()) {
			self::setExpireCheckSession();
			return true;
		}

		return false;
	}


	public static function setExpireCheckSession(string $interval = '45 seconds'): void
	{
		$_SESSION[self::SESSION_EXPIRE_KEY] = (int) strtotime('now + ' . $interval);
	}


	public function run(bool $ajax = false): bool
	{
		if ($this->user->isLoggedIn() === false) { // ignore for anonymous users
			return false;
		}
		if ($ajax === true) {
			session_write_close();
			ignore_user_abort(true);
			if (class_exists(Debugger::class)) {
				Debugger::enable(Debugger::PRODUCTION);
			}
		}

		$metaManager = new UserMetaManager($this->entityManager, $this->userManager);
		$metaManager->set(
			(int) $this->user->getId(),
			'last-activity',
			date('Y-m-d H:i:s'),
		);

		return true;
	}
}
