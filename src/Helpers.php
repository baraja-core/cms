<?php

declare(strict_types=1);

namespace Baraja\Cms;


final class Helpers
{

	/**
	 * @throws \Error
	 */
	public function __construct()
	{
		throw new \Error('Class ' . get_class($this) . ' is static and cannot be instantiated.');
	}

	/**
	 * @param string $signal
	 * @return string
	 */
	public static function formatApiMethod(string $signal): string
	{
		$return = 'action' . str_replace('.', '', self::firstUpper($signal));

		$return = (string) preg_replace_callback('/-([a-z])/', static function (array $match): string {
			return mb_strtoupper($match[1], 'UTF-8');
		}, $return);

		return $return;
	}

	/**
	 * Converts first character to upper case.
	 */
	public static function firstUpper(string $s): string
	{
		return mb_strtoupper(self::substring($s, 0, 1), 'UTF-8') . self::substring($s, 1);
	}

	/**
	 * Returns a part of UTF-8 string.
	 */
	public static function substring(string $s, int $start, int $length = null): string
	{
		if (function_exists('mb_substr')) {
			return mb_substr($s, $start, $length, 'UTF-8'); // MB is much faster
		}

		if ($length === null) {
			$length = self::length($s);
		} elseif ($start < 0 && $length < 0) {
			$start += self::length($s); // unifies iconv_substr behavior with mb_substr
		}

		return iconv_substr($s, $start, $length, 'UTF-8');
	}

	/**
	 * Returns number of characters (not bytes) in UTF-8 string.
	 * That is the number of Unicode code points which may differ from the number of graphemes.
	 */
	public static function length(string $s): int
	{
		return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen(utf8_decode($s));
	}

	/**
	 * Return random UUID version 4
	 *
	 * @return string
	 */
	public static function uuid(): string
	{
		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),

			// 16 bits for "time_mid"
			mt_rand(0, 0xffff),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand(0, 0x0fff) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand(0, 0x3fff) | 0x8000,

			// 48 bits for "node"
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);
	}

	/**
	 * Return current absolute URL.
	 * Return null, if current URL does not exist (for example in CLI mode).
	 *
	 * @return string|null
	 */
	public static function getCurrentUrl(): ?string
	{
		if (!isset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'])) {
			return null;
		}

		return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
			. '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	}

	/**
	 * @return string|null
	 */
	public static function getBaseUrl(): ?string
	{
		static $return;

		if ($return !== null) {
			return $return;
		}

		$currentUrl = self::getCurrentUrl();

		if ($currentUrl !== null) {
			if (preg_match('/^(https?:\/\/.+)\/www\//', $currentUrl, $localUrlParser)) {
				$return = $localUrlParser[0];
			} elseif (preg_match('/^(https?:\/\/[^\/]+)/', $currentUrl, $publicUrlParser)) {
				$return = $publicUrlParser[1];
			}
		}

		if ($return !== null) {
			$return = rtrim($return, '/');
		}

		return $return;
	}

}