<?php

declare(strict_types=1);

namespace Baraja\Cms\Announcement\Entity;


use Baraja\CAS\Entity\User;
use Baraja\Localization\Localization;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnnouncementRepository::class)]
#[ORM\Table(name: 'cms__announcement')]
class Announcement
{
	#[ORM\Id]
	#[ORM\Column(type: 'integer', unique: true, options: ['unsigned' => true])]
	#[ORM\GeneratedValue]
	protected int $id;

	#[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'logins')]
	private User $user;

	#[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
	private ?self $parent;

	/** @var Collection<self> */
	#[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
	private Collection $children;

	#[ORM\Column(type: 'string', length: 2, nullable: true)]
	private ?string $locale;

	#[ORM\Column(type: 'text')]
	private string $message;

	#[ORM\Column(type: 'datetime')]
	private \DateTime $showSince;

	#[ORM\Column(type: 'datetime', nullable: true)]
	private ?\DateTime $showUntil = null;

	#[ORM\Column(type: 'boolean')]
	private bool $active = false;

	#[ORM\Column(type: 'boolean')]
	private bool $pinned = false;


	public function __construct(User $user, ?string $locale, string $message, ?self $parent = null)
	{
		$this->user = $user;
		$this->locale = $locale !== null ? Localization::normalize($locale) : null;
		$this->setMessage($message);
		$this->parent = $parent;
		$this->showSince = new \DateTime;
		$this->children = new ArrayCollection;
	}


	public function getId(): int
	{
		return $this->id;
	}


	public function getUser(): User
	{
		return $this->user;
	}


	public function getParent(): ?self
	{
		return $this->parent;
	}


	/**
	 * @return Collection<self>
	 */
	public function getChildren(): Collection
	{
		return $this->children;
	}


	public function getLocale(): ?string
	{
		return $this->locale;
	}


	public function getMessage(): string
	{
		return $this->message;
	}


	public function getShowSince(): \DateTime
	{
		return $this->showSince;
	}


	public function getShowUntil(): ?\DateTime
	{
		return $this->showUntil;
	}


	public function isActive(): bool
	{
		return $this->active;
	}


	public function isPinned(): bool
	{
		return $this->pinned;
	}


	public function setMessage(string $message): void
	{
		$message = trim($message);
		if ($message === '') {
			throw new \InvalidArgumentException('Message can not be empty.');
		}
		$this->message = $message;
	}


	public function setShowSince(\DateTime $showSince): void
	{
		$this->showSince = $showSince;
	}


	public function setShowUntil(?\DateTime $showUntil): void
	{
		$this->showUntil = $showUntil;
	}


	public function setActive(bool $active = true): void
	{
		$this->active = $active;
	}


	public function setPinned(bool $pinned): void
	{
		$this->pinned = $pinned;
	}
}
