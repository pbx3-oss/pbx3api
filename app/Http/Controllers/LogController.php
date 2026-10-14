<?php

namespace App\Http\Controllers;

use App\Services\Directory\InstanceLogArchiveService;
use App\Services\Directory\LogRetentionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class LogController extends Controller
{

	private $updateableColumns = [];

	/** Instance SIP pcap carousel (config.php SIPLOG). */
	private const SIPLOG_DIR = '/opt/pbx3/db/var/log/siplog';

	/** List of log files to show (relative to /var/log/, except siplog/* absolute via resolveFullPath). */
	private const LOG_FILES = [
		'asterisk/messages',
		'asterisk/security',
		'asterisk/full',
		'asterisk/sip-debug',
		'asterisk/cdr-csv/Master.csv',
		'asterisk/queue_log',
		'syslog',
		'ufw.log',
		'mail.log',
		'fail2ban.log',
		'auth.log',
	];

	/** Map symbolic names to actual log file paths (relative to /var/log/). */
	private const LOG_FILE_MAP = [
		'astmessages' => 'asterisk/messages',
		'astsecurity' => 'asterisk/security',
		'astfull' => 'asterisk/full',
		'astsipdebug' => 'asterisk/sip-debug',
		'astcdrs' => 'asterisk/cdr-csv/Master.csv',
		'astqueues' => 'asterisk/queue_log',
	];

	/** Safe log path: no path traversal. */
	private static function isValidLogPath(string $path): bool
	{
		return $path !== '' && $path !== '.' && $path !== '..'
			&& strpos($path, '..') === false
			&& strpos($path, '/') !== 0; // Must be relative
	}

	/**
	 * Resolve symbolic name to actual path (e.g., astmessages → asterisk/messages).
	 */
	private static function resolveLogPath(string $name): string
	{
		return self::LOG_FILE_MAP[$name] ?? $name;
	}

	/**
	 * Absolute filesystem path for a validated log name/path.
	 * siplog/{basename} → SIPLOG_DIR/{basename}
	 * sip-text/{basename} → /var/log/asterisk/{basename} (rotated sip-debug.*)
	 * else /var/log/{path}.
	 */
	private static function resolveFullPath(string $nameOrPath): ?string
	{
		$actual = self::resolveLogPath($nameOrPath);
		if (str_starts_with($actual, 'siplog/')) {
			$base = basename(substr($actual, strlen('siplog/')));
			if ($base === '' || $base === '.' || $base === '..' || str_contains($base, '/')) {
				return null;
			}
			// Allow only pcap ring names
			if (! preg_match('/^(siplog_.*\.pcap|siplog\.pcap\d*|siplog\.pcap)$/', $base)) {
				return null;
			}

			return self::SIPLOG_DIR.'/'.$base;
		}
		if (str_starts_with($actual, 'sip-text/')) {
			$base = basename(substr($actual, strlen('sip-text/')));
			if ($base === '' || $base === '.' || $base === '..' || str_contains($base, '/')) {
				return null;
			}
			// Rotated segments only (not the live sip-debug file — use astsipdebug)
			if (! preg_match('/^sip-debug\.(\d+|20\d{6}T\d{6}Z)(\.gz)?$/', $base)) {
				return null;
			}

			return '/var/log/asterisk/'.$base;
		}
		if (! self::isValidLogPath($actual)) {
			return null;
		}

		return '/var/log/'.$actual;
	}

	/**
	 * Check if a log name/path is valid (symbolic, LOG_FILES entry, siplog/*, or sip-text/*).
	 */
	private static function isValidLogName(string $name): bool
	{
		if (isset(self::LOG_FILE_MAP[$name])) {
			return true;
		}
		if (in_array($name, self::LOG_FILES, true)) {
			return self::isValidLogPath($name);
		}
		if (str_starts_with($name, 'siplog/') || str_starts_with($name, 'sip-text/')) {
			return self::resolveFullPath($name) !== null;
		}

		return false;
	}

	/**
	 * Get display name for log file (symbolic name if mapped, otherwise path).
	 */
	private static function getLogDisplayName(string $logPath): string
	{
		$symbolic = array_search($logPath, self::LOG_FILE_MAP, true);

		return $symbolic !== false ? $symbolic : $logPath;
	}

	/**
	 * @return list<array{path: string, actualPath: string, exists: bool, size: int}>
	 */
	private function listSipTextRotations(): array
	{
		$logs = [];
		[$lsOut] = pbx3_request_syscmd('ls -1 /var/log/asterisk/sip-debug.* 2>/dev/null');
		if ($lsOut === null || trim($lsOut) === '') {
			return $logs;
		}
		foreach (preg_split('/\r?\n/', trim($lsOut)) as $full) {
			$full = trim($full);
			if ($full === '') {
				continue;
			}
			$base = basename($full);
			if (! preg_match('/^sip-debug\.(\d+|20\d{6}T\d{6}Z)(\.gz)?$/', $base)) {
				continue;
			}
			$display = 'sip-text/'.$base;
			$size = 0;
			[$sizeOut, $sizeErr] = pbx3_request_syscmd('stat -c %s '.escapeshellarg($full).' 2>/dev/null');
			if ($sizeErr === null && is_numeric(trim((string) $sizeOut))) {
				$size = (int) trim((string) $sizeOut);
			}
			$logs[] = [
				'path' => $display,
				'actualPath' => $display,
				'exists' => true,
				'size' => $size,
			];
		}

		return $logs;
	}

	/**
	 * @return list<array{path: string, actualPath: string, exists: bool, size: int}>
	 */
	private function listSiplogPcaps(): array
	{
		$logs = [];
		[$lsOut] = pbx3_request_syscmd('ls -1 '.escapeshellarg(self::SIPLOG_DIR).' 2>/dev/null');
		if ($lsOut === null || trim($lsOut) === '') {
			return $logs;
		}
		foreach (preg_split('/\r?\n/', trim($lsOut)) as $base) {
			$base = trim($base);
			if ($base === '' || ! preg_match('/^(siplog_.*\.pcap|siplog\.pcap\d*|siplog\.pcap)$/', $base)) {
				continue;
			}
			$display = 'siplog/'.$base;
			$full = self::SIPLOG_DIR.'/'.$base;
			$size = 0;
			[$sizeOut, $sizeErr] = pbx3_request_syscmd('stat -c %s '.escapeshellarg($full).' 2>/dev/null');
			if ($sizeErr === null && is_numeric(trim((string) $sizeOut))) {
				$size = (int) trim((string) $sizeOut);
			}
			$logs[] = [
				'path' => $display,
				'actualPath' => $display,
				'exists' => true,
				'size' => $size,
			];
		}

		return $logs;
	}

	/**
	 * List log files with metadata (exists, size).
	 */
	public function index()
	{
		try {
			$logs = [];
			foreach (self::LOG_FILES as $logPath) {
				$fullPath = '/var/log/'.$logPath;

				[$testOut, $testErr] = pbx3_request_syscmd('test -f '.escapeshellarg($fullPath).' && echo exists || echo missing');
				$exists = ($testErr === null && trim((string) $testOut) === 'exists');

				$size = 0;
				if ($exists) {
					[$sizeOut, $sizeErr] = pbx3_request_syscmd('stat -c %s '.escapeshellarg($fullPath).' 2>/dev/null');
					if ($sizeErr === null && is_numeric(trim((string) $sizeOut))) {
						$size = (int) trim((string) $sizeOut);
					}
				}

				$displayName = self::getLogDisplayName($logPath);

				$logs[] = [
					'path' => $displayName,
					'actualPath' => $logPath,
					'exists' => $exists,
					'size' => $size,
				];
			}
			foreach ($this->listSipTextRotations() as $seg) {
				$logs[] = $seg;
			}
			foreach ($this->listSiplogPcaps() as $pcap) {
				$logs[] = $pcap;
			}

			return response()->json(['logs' => $logs], 200);
		} catch (\Exception $e) {
			Log::error('LogController::index failed', ['error' => $e->getMessage()]);

			return response()->json(['message' => 'Failed to list logs', 'detail' => $e->getMessage()], 500);
		}
	}

	/**
	 * Get paginated log lines.
	 * offset=0 means last N lines (tail), offset>0 means older lines.
	 * 
	 * @param Request $request
	 * @param string $logfile Log file path (relative to /var/log/) - may be partial if route split on /
	 */
	public function show(Request $request, string $logfile)
	{
		if (! self::isValidLogName($logfile)) {
			return response()->json(['message' => 'Invalid log name'], 422);
		}

		$fullPath = self::resolveFullPath($logfile);
		if ($fullPath === null) {
			return response()->json(['message' => 'Invalid log path'], 422);
		}

		// Binary pcaps: use download, not text tail
		if (str_ends_with($fullPath, '.pcap') || preg_match('/\.pcap\d+$/', $fullPath)) {
			return response()->json(['message' => 'Use download for pcap files'], 422);
		}

		$validator = Validator::make($request->all(), [
			'offset' => 'integer|min:0',
			'limit' => 'integer|min:1|max:1000',
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 422);
		}

		$offset = (int) $request->input('offset', 0);
		$limit = (int) $request->input('limit', 100);

		[$testOut, $testErr] = pbx3_request_syscmd('test -f '.escapeshellarg($fullPath).' && echo exists || echo missing');
		if ($testErr !== null || trim((string) $testOut) !== 'exists') {
			return response()->json(['message' => 'Log file not found'], 404);
		}

		[$lineCountOut, $lineCountErr] = pbx3_request_syscmd('wc -l < '.escapeshellarg($fullPath).' 2>/dev/null');
		$totalLines = $lineCountErr === null ? (int) trim((string) $lineCountOut) : 0;

		$lines = [];
		if ($totalLines > 0) {
			if ($offset === 0) {
				[$output, $err] = pbx3_request_syscmd('tail -n '.$limit.' '.escapeshellarg($fullPath).' 2>/dev/null');
			} else {
				$startLine = max(1, $totalLines - $offset - $limit + 1);
				$endLine = $totalLines - $offset;
				if ($startLine <= $endLine && $endLine > 0) {
					[$output, $err] = pbx3_request_syscmd('sed -n "'.$startLine.','.$endLine.'p" '.escapeshellarg($fullPath).' 2>/dev/null');
				} else {
					$output = '';
					$err = null;
				}
			}

			if ($err === null && $output !== null) {
				$lines = array_filter(preg_split('/\r?\n/', $output), function ($line) {
					return $line !== '';
				});
			}
		}

		$hasMore = ($offset + $limit) < $totalLines;
		$displayName = self::getLogDisplayName(self::resolveLogPath($logfile));

		return response()->json([
			'path' => $displayName,
			'lines' => array_values($lines),
			'offset' => $offset,
			'limit' => $limit,
			'totalLines' => $totalLines,
			'hasMore' => $hasMore,
		], 200);
	}

	/**
	 * Download full log file.
	 *
	 * @param  Request  $request
	 * @param  string  $logfile  Log file path - may be partial if route split on /
	 */
	public function download(Request $request, string $logfile)
	{
		if (! self::isValidLogName($logfile)) {
			return response()->json(['message' => 'Invalid log name'], 422);
		}

		$fullPath = self::resolveFullPath($logfile);
		if ($fullPath === null) {
			return response()->json(['message' => 'Invalid log path'], 422);
		}

		[$testOut, $testErr] = pbx3_request_syscmd('test -f '.escapeshellarg($fullPath).' && echo exists || echo missing');
		if ($testErr !== null || trim((string) $testOut) !== 'exists') {
			return response()->json(['message' => 'Log file not found'], 404);
		}

		$tmpName = 'log_'.preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($fullPath)).'_'.time();
		$tmpPath = '/tmp/'.$tmpName;
		[$copyOut, $copyErr] = pbx3_request_syscmd('/bin/cp '.escapeshellarg($fullPath).' '.escapeshellarg($tmpPath).' 2>&1');
		if ($copyErr !== null || ! file_exists($tmpPath)) {
			return response()->json(['message' => 'Failed to prepare download', 'detail' => $copyErr ?? 'Copy failed'], 502);
		}

		@chmod($tmpPath, 0644);
		$downloadName = basename($fullPath);

		return Response::download($tmpPath, $downloadName)->deleteFileAfterSend(true);
	}

/**
 * Return (Download) CDR
 * 
 * @param  REQUEST
 * @return csv file
 */
	public function showcdr(Request $request)
	{
		// Validate         
		$validator = Validator::make($request->all(), [         
			'limit' => 'numeric',
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 422);
		}

		$dname = "/tmp/Master." . time() . ".csv";
		$cmd = "/bin/cat";
		if (isset($request->limit)) {
			$cmd = "/usr/bin/tail -n $limit";
		}
	   
		shell_exec(" $cmd /var/log/asterisk/cdr-csv/Master.csv > $dname");

		return Response::download($dname)->deleteFileAfterSend(true);
	}

	/**
	 * Phase 5: effective retention knobs (local days + S3 maxage per class).
	 */
	public function retentionShow(LogRetentionService $retention)
	{
		return response()->json($retention->get(), 200);
	}

	/**
	 * Phase 5: write override file + refresh S3 policy.json when bucket configured.
	 */
	public function retentionUpdate(Request $request, LogRetentionService $retention)
	{
		$validator = Validator::make($request->all(), [
			'local_days' => 'sometimes|array',
			'local_days.syslog' => 'sometimes|integer|min:1|max:365',
			'local_days.asterisk-messages' => 'sometimes|integer|min:1|max:365',
			'local_days.cdr' => 'sometimes|integer|min:1|max:365',
			'local_days.sip-text' => 'sometimes|integer|min:1|max:365',
			'local_days.sip-pcap' => 'sometimes|integer|min:1|max:365',
			's3_maxage_days' => 'sometimes|array',
			's3_maxage_days.syslog' => 'sometimes|integer|min:1|max:730',
			's3_maxage_days.asterisk-messages' => 'sometimes|integer|min:1|max:730',
			's3_maxage_days.cdr' => 'sometimes|integer|min:1|max:730',
			's3_maxage_days.sip-text' => 'sometimes|integer|min:1|max:730',
			's3_maxage_days.sip-pcap' => 'sometimes|integer|min:1|max:730',
		]);
		if ($validator->fails()) {
			return response()->json($validator->errors(), 422);
		}

		try {
			return response()->json($retention->put($request->only(['local_days', 's3_maxage_days'])), 200);
		} catch (\InvalidArgumentException $e) {
			return response()->json(['message' => $e->getMessage()], 422);
		} catch (\Throwable $e) {
			Log::error('log retention update failed', ['error' => $e->getMessage()]);

			return response()->json(['message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Phase 5: list shipped log objects on org bucket.
	 */
	public function archiveIndex(Request $request, InstanceLogArchiveService $archive)
	{
		$validator = Validator::make($request->all(), [
			'class' => 'sometimes|string|in:syslog,asterisk-messages,cdr,sip-text,sip-pcap',
			'from' => 'sometimes|string',
			'to' => 'sometimes|string',
		]);
		if ($validator->fails()) {
			return response()->json($validator->errors(), 422);
		}

		if (! $archive->isAvailable()) {
			return response()->json(['objects' => [], 'available' => false], 200);
		}

		try {
			$objects = $archive->list(
				$request->input('class'),
				$request->input('from'),
				$request->input('to'),
			);

			return response()->json(['objects' => $objects, 'available' => true], 200);
		} catch (\InvalidArgumentException $e) {
			return response()->json(['message' => $e->getMessage()], 422);
		} catch (\Throwable $e) {
			Log::error('log archive list failed', ['error' => $e->getMessage()]);

			return response()->json(['message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Phase 5: temporary URL for one archive object (prefix-guarded).
	 */
	public function archiveDownloadUrl(Request $request, InstanceLogArchiveService $archive)
	{
		$validator = Validator::make($request->all(), [
			'key' => 'required|string|max:1024',
		]);
		if ($validator->fails()) {
			return response()->json($validator->errors(), 422);
		}

		try {
			return response()->json($archive->presignedDownloadUrl($request->input('key')), 200);
		} catch (\InvalidArgumentException $e) {
			return response()->json(['message' => $e->getMessage()], 422);
		} catch (\RuntimeException $e) {
			$status = str_contains($e->getMessage(), 'not found') ? 404 : 500;

			return response()->json(['message' => $e->getMessage()], $status);
		} catch (\Throwable $e) {
			Log::error('log archive download-url failed', ['error' => $e->getMessage()]);

			return response()->json(['message' => $e->getMessage()], 500);
		}
	}
}
