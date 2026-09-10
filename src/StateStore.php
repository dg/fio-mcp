<?php declare(strict_types=1);

namespace DG\Fio;


/**
 * Small JSON file shared by all server processes using the same token, accessed under an exclusive lock.
 */
final class StateStore
{
	public function __construct(
		private readonly string $file,
	) {
	}


	/**
	 * State file in the system temp directory; its name is derived from the token, never containing it.
	 */
	public static function forToken(
		#[\SensitiveParameter]
		string $token,
	): self
	{
		return new self(sys_get_temp_dir() . '/fio-mcp-' . substr(hash('sha256', $token), 0, 16) . '.json');
	}


	/**
	 * Runs $fn with the state passed by reference and saves the modified state, all under the lock.
	 * @template T
	 * @param  \Closure(array<string, mixed>&): T  $fn
	 * @return T
	 */
	public function update(\Closure $fn): mixed
	{
		$isNew = !is_file($this->file);
		$handle = @fopen($this->file, 'c+') ?: throw new \RuntimeException("Cannot open state file $this->file.");
		try {
			if ($isNew) {
				// the state holds the account number and payment fingerprints; on a shared /tmp keep others out
				@chmod($this->file, 0o600);
			}
			flock($handle, LOCK_EX);
			$content = stream_get_contents($handle);
			$state = $content ? json_decode($content, true) : null;
			$state = is_array($state) ? $state : [];
			$original = $state;

			$result = $fn($state);

			if ($state !== $original) {
				// encode before truncating, so a failure cannot leave the file empty
				$json = json_encode($state, JSON_THROW_ON_ERROR);
				ftruncate($handle, 0);
				rewind($handle);
				fwrite($handle, $json);
				fflush($handle);
			}
			return $result;

		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}
