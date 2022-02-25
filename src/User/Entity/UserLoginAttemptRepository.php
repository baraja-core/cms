<?php

declare(strict_types=1);

namespace Baraja\Cms\User\Entity;


use Baraja\Network\Ip;
use Doctrine\ORM\EntityRepository;

final class UserLoginAttemptRepository extends EntityRepository
{
	/**
	 * @return array<int, array<string, int>>
	 */
	public function getUsedAttempts(
		string $username,
		string $blockInterval,
		?string $ip = null,
		int $blockCount = 0,
	): array {
		/** @var array<int, array<string, int>> $attempts */
		$attempts = $this->createQueryBuilder('login')
			->select('PARTIAL login.{id}')
			->join('login.user', 'user')
			->where(
				'login.user IS NULL OR user.username = :username OR user.email = :username OR login.username = :username OR login.ip = :ip'
			)
			->andWhere('login.insertedDateTime >= :intervalDate')
			->andWhere('login.password = FALSE')
			->setParameter('username', $username)
			->setParameter('ip', $ip ?? Ip::get())
			->setParameter('intervalDate', new \DateTimeImmutable('now - ' . $blockInterval))
			->setMaxResults($blockCount * 2)
			->getQuery()
			->getArrayResult();

		return $attempts;
	}
}
