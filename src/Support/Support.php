<?php

declare(strict_types=1);

namespace Baraja\Cms\Support;


use Baraja\BarajaCloud\CloudManager;
use Nette\Http\Request;
use Nette\Http\UrlScript;

final class Support
{
	public const
		PRIORITY_LOW = 'low',
		PRIORITY_NORMAL = 'normal',
		PRIORITY_URGENT = 'urgent';

	public const PRIORITY_LIST = [
		self::PRIORITY_LOW => 'Low (3 weeks)',
		self::PRIORITY_NORMAL => 'Normal (7 days)',
		self::PRIORITY_URGENT => 'Urgent (24 hours)',
	];

	private UrlScript $url;


	public function __construct(
		private CloudManager $cloudManager,
		Request $request,
	) {
		$this->url = $request->getUrl();
	}


	/**
	 * @return mixed[][]
	 */
	public function getIssues(): array
	{
		return $this->cloudManager->callRequest('cms-issue', [
			'domain' => $this->getDomain(),
		]);
	}


	public function createIssue(
		string $subject,
		string $message,
		string $priority,
		?\DateTime $dueDate = null,
		?string $url = null
	): void {
		$this->cloudManager->callRequest('cms-issue', [
			'domain' => $this->getDomain(),
			'subject' => $subject,
			'message' => $message,
			'priority' => $priority,
			'dueDate' => $dueDate === null ? null : $dueDate->format(\DateTime::ATOM),
			'url' => $url,
		], 'POST');
	}


	/**
	 * @return mixed[]
	 */
	public function getIssue(string $id): array
	{
		return $this->cloudManager->callRequest('cms-issue/detail', [
			'id' => $id,
			'domain' => $this->getDomain(),
		]);
	}


	private function getDomain(): string
	{
		return str_replace('www.', '', $this->url->getDomain(4));
	}
}
