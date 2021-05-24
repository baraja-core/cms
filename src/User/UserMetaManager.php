<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserMeta;
use Baraja\Doctrine\EntityManager;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class UserMetaManager
{
	/** @var array<string, UserMeta> */
	private static array $cache = [];


	public function __construct(
		private EntityManager $entityManager,
		private UserManager $userManager,
	) {
	}


	public function loadAll(int $userId): self
	{
		/** @var UserMeta[] $metas */
		$metas = $this->entityManager->getRepository(UserMeta::class)
			->createQueryBuilder('meta')
			->where('meta.user = :userId')
			->setParameter('userId', $userId)
			->getQuery()
			->getResult();

		foreach ($metas as $meta) {
			$cacheKey = $this->getCacheKey($userId, $meta->getKey());
			self::$cache[$cacheKey] = $meta;
		}

		return $this;
	}


	public function get(int $userId, string $key): ?string
	{
		$cacheKey = $this->getCacheKey($userId, $key);
		if (isset(self::$cache[$cacheKey])) {
			return self::$cache[$cacheKey]->getValue();
		}
		try {
			/** @var UserMeta $meta */
			$meta = $this->entityManager->getRepository(UserMeta::class)
				->createQueryBuilder('meta')
				->where('meta.user = :userId')
				->andWhere('meta.key = :key')
				->setParameter('userId', $userId)
				->setParameter('key', $key)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();
			self::$cache[$cacheKey] = $meta;

			return $meta->getValue();
		} catch (NoResultException | NonUniqueResultException) {
			// Silence is golden.
		}

		return null;
	}


	public function set(int $userId, string $key, ?string $value): self
	{
		try {
			/** @var User $user */
			$user = $this->userManager->getUserById($userId);
		} catch (NoResultException | NonUniqueResultException $e) {
			throw new \InvalidArgumentException('User "' . $userId . '" does not exist.', $e->getCode(), $e);
		}
		$cacheKey = $this->getCacheKey((int) $user->getId(), $key);
		try {
			/** @var UserMeta $meta */
			$meta = self::$cache[$cacheKey] ?? $this->entityManager->getRepository(UserMeta::class)
					->createQueryBuilder('meta')
					->where('meta.user = :userId')
					->andWhere('meta.key = :key')
					->setParameter('userId', $user->getId())
					->setParameter('key', $key)
					->setMaxResults(1)
					->getQuery()
					->getSingleResult();
		} catch (NoResultException | NonUniqueResultException) {
			if ($value === null) {
				return $this;
			}
			$meta = new UserMeta($user, $key, $value);
			$this->entityManager->persist($meta);
		}
		if ($value === null) {
			$this->entityManager->remove($meta);
			unset(self::$cache[$cacheKey]);
		} else {
			$meta->setValue($value);
			self::$cache[$cacheKey] = $meta;
		}
		$this->entityManager->flush();

		return $this;
	}


	private function getCacheKey(int $userId, string $key): string
	{
		return $userId . '__' . $key;
	}
}
