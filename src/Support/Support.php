<?php

declare(strict_types=1);

namespace Baraja\Cms\Support;


use Baraja\BarajaCloud\CloudManager;
use Nette\Http\Request;
use Nette\Http\UrlScript;

final class Support
{
	public const PRIORITY_LOW = 'low';

	public const PRIORITY_NORMAL = 'normal';

	public const PRIORITY_URGENT = 'urgent';

	public const PRIORITY_LIST = [
		self::PRIORITY_LOW => 'Low (3 weeks)',
		self::PRIORITY_NORMAL => 'Normal (7 days)',
		self::PRIORITY_URGENT => 'Urgent (24 hours)',
	];

	/** @var UrlScript */
	private $url;

	/** @var CloudManager */
	private $cloudManager;


	public function __construct(Request $request, CloudManager $cloudManager)
	{
		$this->url = $request->getUrl();
		$this->cloudManager = $cloudManager;
	}


	/**
	 * @return mixed[][]
	 */
	public function getIssues(): array
	{
		return json_decode(file_get_contents(self::BASE_URL . '/cms-issue?domain=' . urlencode($this->getDomain())), true);
	}


	public function createIssue(string $subject, string $message, string $priority, ?\DateTime $dueDate = null, ?string $url = null): void
	{
		file_get_contents(self::BASE_URL . '/cms-issue', false, stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => 'Content-Type: application/x-www-form-urlencoded',
				'content' => http_build_query([
					'domain' => $this->getDomain(),
					'subject' => $subject,
					'message' => $message,
					'priority' => $priority,
					'dueDate' => $dueDate === null ? null : $dueDate->format(\DateTime::ATOM),
					'url' => $url,
				]),
			],
		]));
	}


	/**
	 * @param string $id
	 * @return mixed[]
	 */
	public function getIssue(string $id): array
	{
		return json_decode(file_get_contents(self::BASE_URL . '/cms-issue/detail?id=' . urlencode($id) . '&domain=' . urlencode($this->getDomain())), true);
	}


	private function getDomain(): string
	{
		return str_replace('www.', '', $this->url->getDomain(4));
	}
}
