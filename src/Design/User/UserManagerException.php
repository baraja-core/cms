<?php

declare(strict_types=1);

namespace Baraja\Cms;


class UserManagerException extends \Exception
{

	/**
	 * @param string $id
	 * @throws UserManagerException
	 */
	public static function userDoesNotExist(string $id): void
	{
		throw new self('User id "' . $id . '" does not exist.');
	}

}