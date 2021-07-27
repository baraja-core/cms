<?php

declare(strict_types=1);

namespace Baraja\Cms;


final class Session
{
	public const
		WORKFLOW_PASSWORD_HASH = 'workflow-password-hash',
		WORKFLOW_CHECK_EXPIRATION = 'workflow-check-expiration',
		WORKFLOW_NEED_OTP_AUTH = 'workflow-need-otp-auth',
		WORKFLOW_IS_BOT = 'workflow-is-bot',
		WORKFLOW_USER_AGENT = 'workflow-user-agent',
		LAST_IDENTITY_ID = 'last-identity-id';

	private const PREFIX = '__BRJ_CMS';


	public static function get(string $key): mixed
	{
		if (isset($_SESSION[self::PREFIX]) === false) {
			return null;
		}

		return $_SESSION[self::PREFIX][$key] ?? null;
	}


	public static function set(string $key, mixed $value): void
	{
		if (isset($_SESSION[self::PREFIX]) === false) {
			$_SESSION[self::PREFIX] = [];
		}
		if ($value === null) {
			self::remove($key);
		} else {
			$_SESSION[self::PREFIX][$key] = $value;
		}
	}


	public static function remove(string $key): void
	{
		if (isset($_SESSION[self::PREFIX][$key]) === true) {
			unset($_SESSION[self::PREFIX][$key]);
		}
	}


	public static function removeAll(): void
	{
		$_SESSION[self::PREFIX] = [];
	}
}
