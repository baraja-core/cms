<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare\Bridge;


use Baraja\Cms\Context;
use Baraja\Cms\User\UserManager;
use Baraja\Network\Ip;
use Baraja\TracySentryBridge\SentryLogger;
use function Sentry\configureScope;

use Sentry\State\Scope;

final class SentryBridge
{
	public function __construct(
		private UserManager $userManager,
		private Context $context,
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
				$scope->setTag('request_id', $this->context->getContainer()->getRequestId());
				if ($this->userManager->isLoggedIn() === false) {
					return;
				}
				$identity = $this->userManager->getIdentity();
				if ($identity !== null) {
					$scope->setUser(
						[
							'id' => $identity->getId(),
							'ip_address' => Ip::get(),
							'username' => $identity->getName(),
							'roles' => $identity->getRoles(),
						],
					);
				}
			},
		);
	}
}
