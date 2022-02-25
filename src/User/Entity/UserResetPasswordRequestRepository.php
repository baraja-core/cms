<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class UserResetPasswordRequestRepository extends EntityRepository
{
	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getByToken(string $token): UserResetPasswordRequest
	{
		$request = $this->createQueryBuilder('resetRequest')
			->select('resetRequest, user')
			->join('resetRequest.user', 'user')
			->where('resetRequest.token = :token')
			->setParameter('token', $token)
			->setMaxResults(1)
			->getQuery()
			->getSingleResult();
		assert($request instanceof UserResetPasswordRequest);

		return $request;
	}
}
