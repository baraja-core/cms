<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare;


use Nette\Security\User;
use Nette\Utils\Arrays;
use Tracy\Debugger;

final class IntegrityWorkflow
{
	private const SESSION_EXPIRE_KEY = '__BRJ_CMS--workflow-check-expiration';

	/** @var array<int, callable(self): void> */
	private array $onRun = [];

	public function __construct(
		private User $user,
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
