<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Url\Url;
use Latte\Engine;
use Nette\Http\Request;
use Nette\Utils\Strings;
use Tracy\Debugger;

final class Helpers
{
	/** @throws \Error */
	public function __construct()
	{
		throw new \Error('Class ' . self::class . ' is static and cannot be instantiated.');
	}


	/**
	 * Return current API path by current HTTP URL.
	 * In case of CLI return empty string.
	 */
	public static function processPath(Request $httpRequest): string
	{
		return trim(str_replace(rtrim($httpRequest->getUrl()->withoutUserInfo()->getBaseUrl(), '/'), '', Url::get()->getCurrentUrl()), '/');
	}


	public static function formatApiMethod(string $signal): string
	{
		$return = 'action' . str_replace('.', '', Strings::firstUpper($signal));

		$return = (string) preg_replace_callback('/-([a-z])/', static fn(array $match): string => mb_strtoupper($match[1], 'UTF-8'), $return);

		return $return;
	}


	public static function userIp(): string
	{
		static $ip = null;

		if ($ip === null) {
			if (isset($_SERVER['REMOTE_ADDR']) === true) {
				if (\in_array($_SERVER['REMOTE_ADDR'], ['::1', '0.0.0.0', 'localhost'], true)) {
					$ip = '127.0.0.1';
				} elseif (($ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) === false) {
					$ip = '127.0.0.1';
				}
			} else {
				$ip = '127.0.0.1';
			}
		}

		return $ip;
	}


	/**
	 * @param string $data -> a string of length divisible by five
	 * @copyright Jakub Vrána, https://php.vrana.cz/
	 */
	public static function otpBase32Encode(string $data): string
	{
		static $codes = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits = '';
		foreach (str_split($data) as $c) {
			$bits .= sprintf('%08b', \ord($c));
		}
		$return = '';
		foreach (str_split($bits, 5) as $c) {
			$return .= $codes[bindec($c)];
		}

		return $return;
	}


	/**
	 * Generate URL for OTP QR code
	 *
	 * @param string $issuer -> service (or project) name
	 * @param string $user -> username (displayed in Authenticator app)
	 * @param string $secret -> in binary format
	 * @copyright Jakub Vrána, https://php.vrana.cz/
	 */
	public static function getOtpQrUrl(string $issuer, string $user, string $secret): string
	{
		return 'https://chart.googleapis.com/chart?chs=500x500&chld=M|0&cht=qr&chl='
			. urlencode(
				'otpauth://totp/' . rawurlencode($issuer)
				. ':' . $user . '?secret=' . self::otpBase32Encode($secret)
				. '&issuer=' . rawurlencode($issuer),
			);
	}


	/**
	 * Generate one-time password
	 *
	 * @param string $secret -> in binary format
	 * @param string $timeSlot -> example: floor(time() / 30)
	 * @copyright Jakub Vrána, https://php.vrana.cz/
	 */
	public static function getOtp(string $secret, string $timeSlot): int
	{
		$data = str_pad(pack('N', $timeSlot), 8, "\0", STR_PAD_LEFT);
		$hash = hash_hmac('sha1', $data, $secret, true);
		$offset = \ord(\substr($hash, -1)) & 0xF;
		$unpacked = (array) unpack('N', substr($hash, $offset, 4));

		return ($unpacked[1] & 0x7FFFFFFF) % 1e6;
	}


	public static function checkAuthenticatorOtpCodeManually(string $otpCode, int $code): bool
	{
		$checker = static fn(int $timeSlot): bool => self::getOtp($otpCode, (string) $timeSlot) === $code;

		return $checker($slot = (int) floor(time() / 30)) || $checker($slot - 1) || $checker($slot + 1);
	}


	/**
	 * Normalize phone to basic format if pattern match.
	 *
	 * @param int $region use this prefix when number prefix does not exist
	 */
	public static function fixPhone(string $phone, int $region = 420): string
	{
		$phone = (string) preg_replace('/\s+/', '', $phone); // remove spaces

		if (preg_match('/^([\+0-9]+)/', $phone, $trimUnexpected)) { // remove user notice and unexpected characters
			$phone = (string) $trimUnexpected[1];
		}
		if (preg_match('/^\+(4\d{2})(\d{3})(\d{3})(\d{3})$/', $phone, $prefixParser)) { // +420 xxx xxx xxx
			$phone = '+' . $prefixParser[1] . ' ' . $prefixParser[2] . ' ' . $prefixParser[3] . ' ' . $prefixParser[4];
		} elseif (preg_match('/^\+(4\d{2})(\d+)$/', $phone, $prefixSimpleParser)) { // +420 xxx
			$phone = '+' . $prefixSimpleParser[1] . ' ' . $prefixSimpleParser[2];
		} elseif (preg_match('/^(\d{3})(\d{3})(\d{3})$/', $phone, $regularParser)) { // numbers only
			$phone = '+' . $region . ' ' . $regularParser[1] . ' ' . $regularParser[2] . ' ' . $regularParser[3];
		} else {
			throw new \InvalidArgumentException('Phone number "' . $phone . '" for region "' . $region . '" does not exist.');
		}

		return $phone;
	}


	/**
	 * Advance function for parsing real user full name.
	 * Accept name in format "Doc. Ing. Jan Barášek, PhD."
	 *
	 * @return string[]|null[]
	 */
	public static function nameParser(string $name): array
	{
		static $degreePattern = '((?:(?:\s*(?:[A-Za-z]{2,8})\.\s*)+))?';
		$normalized = str_replace(',', '', trim(str_replace('/\s+/', ' ', $name)));
		$degreeBefore = null;
		$degreeAfter = null;

		if (preg_match('/^' . $degreePattern . '\s*([^.]+?)?\s*' . $degreePattern . '$/', $normalized, $degreeParser)) {
			$normalized = trim($degreeParser[2] ?? '');
			$degreeBefore = trim($degreeParser[1] ?? '') ?: null;
			$degreeAfter = trim($degreeParser[3] ?? '') ?: null;
		}

		$parts = explode(' ', $normalized, 2);

		return [
			'firstName' => Strings::firstUpper($parts[0] ?? '') ?: null,
			'lastName' => Strings::firstUpper($parts[1] ?? '') ?: null,
			'degreeBefore' => $degreeBefore,
			'degreeAfter' => $degreeAfter,
		];
	}


	public static function formatPresenterNameToUri(string $name): string
	{
		return trim((string) preg_replace_callback('/([A-Z])/', static fn(array $match): string => '-' . mb_strtolower($match[1], 'UTF-8'), $name), '-');
	}


	public static function formatPresenterNameByUri(string $name): string
	{
		return Strings::firstUpper(self::formatPresenter(mb_strtolower($name, 'UTF-8')));
	}


	public static function formatActionNameByUri(string $name): string
	{
		return trim(self::formatPresenter($name), '/');
	}


	/**
	 * Convert URI case to Presenter name case. The first character will not be enlarged automatically.
	 *
	 * For example: "article-manager" => "articleManager".
	 */
	public static function formatPresenter(string $haystack): string
	{
		return (string) preg_replace_callback('/-([a-z])/', static fn(array $match): string => mb_strtoupper($match[1], 'UTF-8'), $haystack);
	}


	/**
	 * Escapes string for use inside HTML attribute value.
	 */
	public static function escapeHtmlAttr(string $s, bool $double = true): string
	{
		if (str_contains($s, '`') && strpbrk($s, ' <>"\'') === false) {
			$s .= ' '; // protection against innerHTML mXSS vulnerability nette/nette#1496
		}

		return htmlspecialchars($s, ENT_QUOTES, 'UTF-8', $double);
	}


	/**
	 * Escapes string for use inside HTML comments.
	 */
	public static function escapeHtmlComment(string $s): string
	{
		if ($s && (str_starts_with($s, '-') || str_starts_with($s, '>') || str_starts_with($s, '!'))) {
			$s = ' ' . $s;
		}

		$s = str_replace('--', '- - ', $s);
		if (substr($s, -1) === '-') {
			$s .= ' ';
		}

		return $s;
	}


	public static function minifyHtml(string $haystack): string
	{
		$return = (string) preg_replace_callback(
			'#[ \t\r\n]+|<(/)?(textarea|pre)(?=\W)#i',
			fn(array $match): string => empty($match[2]) ? ' ' : $match[0],
			$haystack,
		);
		$return = (string) preg_replace('/(\w|;)\s+({|})\s+(\w|\.|#)/', '$1$2$3', $return);
		$return = str_replace(';}', '}', $return);
		$return = (string) preg_replace('/(\w)\s*:\s+(\w|#|-|.)/', '$1:$2', $return);
		return (string) preg_replace('/\s*\/\*+[^\*]+\*+\/\s*/', '', $return);
	}


	public static function brokenAdmin(\Throwable $e): void
	{
		echo self::minifyHtml((new Engine)
			->renderToString(__DIR__ . '/../template/broken-admin.latte', [
				'basePath' => Url::get()->getBaseUrl(),
				'exception' => $e,
				'isDebug' => Debugger::isEnabled(),
			]));
	}
}
