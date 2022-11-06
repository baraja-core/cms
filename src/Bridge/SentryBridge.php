<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare\Bridge;


use Baraja\CAS\User;
use Baraja\Cms\Context;
use Baraja\Network\Ip;
use Baraja\TracySentryBridge\SentryLogger;
use Sentry\State\Scope;
use function Sentry\configureScope;

final class SentryBridge
{
	public function __construct(
		private User $user,
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
				if ($this->user->isLoggedIn() === false) {
					return;
				}
				$identity = $this->user->getIdentity();
				if ($identity !== null) {
					$scope->setUser([
						'id' => $identity->getId(),
						'ip_address' => Ip::get(),
						'username' => $identity->getName(),
					]);
				}
			},
		);
	}
}
