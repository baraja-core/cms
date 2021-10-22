<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare\Bridge;


use Baraja\AdminBar\User\AdminIdentity;
use Baraja\Cms\User\UserManager;
use Baraja\Network\Ip;
use Baraja\TracySentryBridge\SentryLogger;
use function Sentry\configureScope;

use Sentry\State\Scope;

final class SentryBridge
{
	public function __construct(
		private UserManager $userManager,
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
				if ($this->userManager->isLoggedIn() === false) {
					return;
				}
				$identity = $this->userManager->getCmsIdentity();
				if ($identity !== null) {
					$scope->setUser(
						[
							'id' => (int) $identity->getId(),
							'ip_address' => Ip::get(),
							'username' => $identity->getName(),
							'roles' => $identity->getRoles(),
						]
					);
				}
			}
		);
	}
}
