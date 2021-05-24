<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare\Bridge;


use Baraja\AdminBar\User\AdminIdentity;
use Baraja\Cms\Helpers;
use Baraja\TracySentryBridge\SentryLogger;
use Nette\Security\User;
use function Sentry\configureScope;

use Sentry\State\Scope;

final class SentryBridge
{
	public function __construct(
		private User $user,
	) {
	}


	public function register(): void
	{
		if (class_exists(SentryLogger::class)) {
			SentryLogger::register();
		}
		if (function_exists('Sentry\configureScope') === false) {
			return;
		}
		configureScope(
			function (Scope $scope): void {
				if ($this->user->isLoggedIn() === false) {
					return;
				}
				$identity = $this->user->getIdentity();
				if ($identity instanceof AdminIdentity) {
					$scope->setUser(
						[
							'id' => $identity->getId(),
							'ip_address' => Helpers::userIp(),
							'username' => $identity->getName(),
							'roles' => $identity->getRoles(),
						]
					);
				}
			}
		);
	}
}
