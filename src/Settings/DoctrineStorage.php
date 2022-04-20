<?php

declare(strict_types=1);

namespace Baraja\DoctrineConfiguration;


use Baraja\DynamicConfiguration\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;

/**
 * @internal
 */
final class DoctrineStorage implements Storage
{
	private OptionRepository $optionRepository;


	public function __construct(
		private EntityManagerInterface $entityManager,
	) {
		/** @var OptionRepository $optionRepository */
		$optionRepository = $entityManager->getRepository(Option::class);
		$this->optionRepository = $optionRepository;
	}


	/**
	 * @return array<string, string>
	 */
	public function loadAll(): array
	{
		$return = [];
		foreach ($this->optionRepository->loadAll() as $item) {
			$return[$item['key']] = $item['value'];
		}

		return $return;
	}


	public function get(string $key): ?string
	{
		return $this->optionRepository->getAsArray($key);
	}


	/**
	 * @param array<int, string> $keys
	 * @return array<string, string>
	 */
	public function getMultiple(array $keys): array
	{
		if ($keys === []) {
			return [];
		}

		$return = [];
		foreach ($this->optionRepository->getMultiple($keys) as $option) {
			$return[$option['key']] = $option['value'];
		}

		return $return;
	}


	public function save(string $key, string $value): void
	{
		try {
			$option = $this->optionRepository->getAsEntity($key);
		} catch (NoResultException | NonUniqueResultException) {
			$option = new Option($key, $value);
			$this->entityManager->persist($option);
		}
		$option->setValue($value);
		$this->entityManager->flush();
	}


	public function remove(string $key): void
	{
		try {
			$option = $this->optionRepository->getAsEntity($key);
		} catch (NoResultException | NonUniqueResultException) {
			return;
		}

		$this->entityManager->remove($option);
		$this->entityManager->flush();
		$this->entityManager->clear();
	}
}
