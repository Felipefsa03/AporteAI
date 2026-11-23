<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Support\OpsMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

/** OPS: healthcheck, métricas e disparo sintético de alerta. */
final class OpsController
{
    public function __construct(private readonly OpsMetrics $metrics)
    {
    }

    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseStatus(),
            'queue' => $this->queueStatus(),
            'dead_letters' => $this->deadLetterStatus(),
        ];

        $down = in_array('down', $checks, true);
        $degraded = in_array('degraded', $checks, true);

        return response()->json([
            'status' => $down ? 'down' : ($degraded ? 'degraded' : 'ok'),
            'checks' => $checks,
            'targets' => [
                'rpo_minutes' => (int) config('ops.targets.rpo_minutes', 15),
                'rto_minutes' => (int) config('ops.targets.rto_minutes', 60),
                'p95_ms' => (int) config('ops.targets.p95_ms', 800),
            ],
            'public_recommendations' => (bool) config('pilot.public_recommendations', false),
        ], $down ? 503 : 200);
    }

    public function metrics(): Response
    {
        $lines = [
            '# HELP ops_alerts_total_24h Alertas OPS registrados nas ultimas 24 horas.',
            '# TYPE ops_alerts_total_24h gauge',
        ];

        foreach ($this->metrics->snapshot() as $name => $value) {
            $lines[] = $name.' '.$value;
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function testAlert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'severity' => ['sometimes', 'string', 'in:info,warning,critical'],
        ]);

        $this->metrics->alert('synthetic', [
            'severity' => $data['severity'] ?? 'info',
            'synthetic' => true,
        ]);

        return response()->json([
            'status' => 'recorded',
            'channel' => 'audit_events',
            'synthetic' => true,
        ], 202);
    }

    private function databaseStatus(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }

    private function queueStatus(): string
    {
        try {
            $failed = (int) DB::table('failed_jobs')->count();

            return $failed >= (int) config('ops.thresholds.failed_jobs', 20) ? 'degraded' : 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }

    private function deadLetterStatus(): string
    {
        try {
            $pending = (int) DB::table('whatsapp_dead_letters')->whereNull('replayed_at')->count();

            return $pending >= (int) config('ops.thresholds.dead_letters', 20) ? 'degraded' : 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }
}
