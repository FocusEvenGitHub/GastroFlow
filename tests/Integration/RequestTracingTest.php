<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Middleware\CorrelationIdMiddleware;
use App\Models\Setting;
use App\Services\JobService;
use App\Settings;
use Illuminate\Database\Capsule\Manager as Db;

/**
 * Proves the roadmap's own trace claim for real (spec 042): a request_id generated for one
 * HTTP request survives into the async print job it dispatches, and shows up — alongside
 * order_id and a machine-readable event tag — in the real app.log line the print attempt
 * produces. This is the same file public/admin/logs.php reads and the same chain the roadmap
 * names: HTTP Request -> Order -> Job -> Printing.
 *
 * Reads the REAL app.log rather than a test double, deliberately: CorrelationIdMiddleware logs
 * through the container's real DI-bound logger (there is no seam to substitute it from
 * IntegrationTestCase::request(), which builds the real App verbatim), and PrintOrderJob
 * (src/Jobs/PrintOrderJob.php) hardcodes its own real Logger/StreamHandler with no constructor
 * injection point at all — unlike JobService, it cannot be given a NullLogger or TestHandler.
 * Every integration test that calls request() now writes one such HTTP-summary line to the
 * real log as an unavoidable, disclosed side effect of this spec, not something unique to this
 * test. The read is bounded to the byte range this test itself appends, so it never depends on
 * (or is confused by) whatever was in the file before.
 */
class RequestTracingTest extends IntegrationTestCase
{
    private function logFile(): string
    {
        return (new Settings())->getLogFile();
    }

    private function logFileSize(): int
    {
        return file_exists($this->logFile()) ? filesize($this->logFile()) : 0;
    }

    private function tailLogFile(int $fromByteOffset): string
    {
        if (!file_exists($this->logFile())) {
            return '';
        }

        $handle = fopen($this->logFile(), 'r');
        fseek($handle, $fromByteOffset);
        $tail = stream_get_contents($handle);
        fclose($handle);

        return (string) $tail;
    }

    /** AC1, AC4, AC6, AC7 — the flagship trace, in one shared file, exactly as an operator would read it. */
    public function testRequestIdSurvivesFromHttpRequestIntoTheDispatchedPrintJobsLog(): void
    {
        // Deterministic failure path, same as PrintingTest.php's established pattern — the test
        // database has no printer configured, so the print attempt fails predictably and fast.
        Setting::setValue('printer_ip', '');
        $item = $this->availableMenuItem();

        $before = $this->logFileSize();

        $response = $this->request('POST', '/api/orders', [
            'items'        => [['id' => $item->id, 'quantity' => 1]],
            'print_ticket' => true,
        ]);

        $requestId = $response->getHeaderLine(CorrelationIdMiddleware::HEADER_NAME);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $requestId, 'AC1');

        $orderId = (int) $this->decode($response)['id'];

        // AC6 — the persisted job payload carries the same request_id the response header did.
        $job = Db::table('jobs')->where('queue', 'print')->first();
        $this->assertNotNull($job, 'print_ticket: true must have dispatched a print job');
        $payload = json_decode($job->payload, true);
        $this->assertSame($requestId, $payload['data']['request_id'] ?? null, 'AC6');
        $this->assertSame($orderId, $payload['data']['order_id'] ?? null);

        // Run the job for real, through the exact same JobService -> PrintOrderJob -> PrintService
        // path bin/worker uses in production — no test double for PrintOrderJob is possible
        // (see class docblock), so this genuinely exercises the real log-writing code.
        (new JobService())->processNext('print');

        $tail = $this->tailLogFile($before);

        // AC4 — the HTTP request's own summary log line (CorrelationIdMiddleware) carries the
        // same request_id as the response header.
        $this->assertMatchesRegularExpression(
            '/HTTP request.*"request_id":"' . preg_quote($requestId, '/') . '"/',
            $tail,
            'Expected the HTTP request summary log to carry the request_id'
        );

        // AC7 — the print attempt's log line carries order_id, a machine-readable event tag,
        // and the SAME request_id — closing the HTTP -> Order -> Job -> Printing chain for
        // real, in the one file an operator (or public/admin/logs.php) actually reads.
        // Field order in the JSON context is an implementation detail (PrintService merges
        // caller-supplied context before its own fields), so each field is checked
        // independently rather than assuming a fixed order in one regex.
        $printLine = null;
        foreach (explode("\n", $tail) as $line) {
            if (str_contains($line, 'Print failed')) {
                $printLine = $line;
                break;
            }
        }
        $this->assertNotNull($printLine, 'Expected a "Print failed" line in the log tail');
        $this->assertStringContainsString('"order_id":' . $orderId, $printLine);
        $this->assertStringContainsString('"request_id":"' . $requestId . '"', $printLine);
        $this->assertStringContainsString('"event":"print.failed"', $printLine);
    }
}
