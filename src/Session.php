<?php

declare(strict_types=1);

namespace Baraja\Cms;


final class Session
{
	public const
		WORKFLOW_PASSWORD_HASH = 'workflow-password-hash',
		WORKFLOW_CHECK_EXPIRATION = 'workflow-check-expiration',
		LAST_IDENTITY_ID = 'last-identity-id';


	public static function get(string $key): mixed
	{
		return $_SESSION[self::getKey($key)] ?? null;
	}


	public static function set(string $key, mixed $value): void
	{
		if ($value === null) {
			self::remove($key);
		} else {
			$_SESSION[self::getKey($key)] = $value;
		}
	}


	public static function remove(string $key): void
	{
		$sessionKey = self::getKey($key);
		if (isset($_SESSION[$sessionKey]) === true) {
			unset($_SESSION[$sessionKey]);
		}
	}


	public static function removeAll(): void
	{
		foreach ($_SESSION as $key => $value) {
			if (str_starts_with($key, '__BRJ_CMS--')) {
				unset($_SESSION[$key]);
			}
		}
	}


	public static function getKey(string $key): string
	{
		return '__BRJ_CMS--' . $key;
	}
}
