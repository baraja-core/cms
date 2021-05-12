<?php

declare(strict_types=1);

namespace Baraja\DoctrineConfiguration;


use Baraja\Doctrine\Identifier\IdentifierUnsigned;
use Doctrine\ORM\Mapping as ORM;
use Nette\Utils\DateTime;

/**
 * @ORM\Entity()
 * @ORM\Table(name="core__option")
 */
class Option
{
	use IdentifierUnsigned;

	/** @ORM\Column(type="string", name="`key`", length=128, unique=true) */
	private string $key;

	/** @ORM\Column(type="text") */
	private string $value;

	/**
	 * Last non empty values.
	 *
	 * @var string[]
	 * @ORM\Column(type="json")
	 */
	private array $oldValues = [];

	/** @ORM\Column(type="datetime") */
	private \DateTime $insertedDate;

	/** @ORM\Column(type="datetime", nullable=true) */
	private ?\DateTime $updatedDate = null;


	public function __construct(string $key, string $value)
	{
		$this->key = $key;
		$this->value = $value;
		$this->insertedDate = DateTime::from('now');
	}


	public function __toString(): string
	{
		return $this->getValue();
	}


	public function getKey(): string
	{
		return $this->key;
	}


	public function getValue(): string
	{
		return $this->value;
	}


	public function setValue(string $value): self
	{
		if ($this->value !== '') {
			$this->oldValues = $this->formatOldValues($this->getOldValues(), $this->value);
		}

		$this->value = $value;
		$this->updatedDate = DateTime::from('now');

		return $this;
	}


	/**
	 * @return string[]
	 */
	public function getOldValues(): array
	{
		return $this->oldValues ?? [];
	}


	public function getInsertedDate(): \DateTime
	{
		return $this->insertedDate;
	}


	public function getUpdatedDate(): ?\DateTime
	{
		return $this->updatedDate;
	}


	/**
	 * @param string[] $oldValues
	 * @return string[]
	 */
	private function formatOldValues(array $oldValues, string $lastValue, int $limit = 5): array
	{
		$return = [];
		foreach (array_unique(array_merge($oldValues, [$lastValue])) as $oldValue) {
			$return[] = $oldValue;
			if (\count($return) >= $limit) {
				break;
			}
		}

		return $return;
	}
}
