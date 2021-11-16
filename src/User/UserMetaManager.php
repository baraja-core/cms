<?php

declare(strict_types=1);

namespace Baraja\Cms\User;


use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserMeta;
use Baraja\Cms\User\Entity\UserMetaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

final class UserMetaManager
{
	/** @var array<string, UserMeta> */
	private static array $cache = [];


	public function __construct(
		private EntityManagerInterface $entityManager,
		private UserManager $userManager,
	) {
	}


	public function loadAll(int $userId): self
	{
		/** @var UserMetaRepository $userMetaRepository */
		$userMetaRepository = $this->entityManager->getRepository(UserMeta::class);

		foreach ($userMetaRepository->loadAll($userId) as $meta) {
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
			/** @var UserMetaRepository $userMetaRepository */
			$userMetaRepository = $this->entityManager->getRepository(UserMeta::class);
			$meta = $userMetaRepository->load($userId, $key);
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
			throw new \InvalidArgumentException(sprintf('User "%s" does not exist.', $userId), 500, $e);
		}
		$cacheKey = $this->getCacheKey($user->getId(), $key);
		try {
			/** @var UserMetaRepository $userMetaRepository */
			$userMetaRepository = $this->entityManager->getRepository(UserMeta::class);
			/** @var UserMeta $meta */
			$meta = self::$cache[$cacheKey] ?? $userMetaRepository->load($user->getId(), $key);
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
