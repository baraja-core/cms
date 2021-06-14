<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare;


use Baraja\Cms\Session;
use Nette\Security\User;
use Nette\Utils\Arrays;
use Tracy\Debugger;

final class IntegrityWorkflow
{
	/** @var array<int, callable(self): void> */
	private array $onRun = [];

	public function __construct(
		private User $user,
	) {
	}


	public static function isNeedRun(): bool
	{
		$checkExpiration = Session::get(Session::WORKFLOW_CHECK_EXPIRATION);
		if ($checkExpiration === null || ((int) $checkExpiration) < time()) {
			self::setExpireCheckSession();
			return true;
		}

		return false;
	}


	public static function setExpireCheckSession(string $interval = '45 seconds'): void
	{
		Session::set(Session::WORKFLOW_CHECK_EXPIRATION, (int) strtotime('now + ' . $interval));
	}


	public function addRunEvent(callable $event): void
	{
		$this->onRun[] = $event;
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

		Arrays::invoke($this->onRun, $this);

		return true;
	}
}
