<?php

declare(strict_types=1);

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** LED: idempotência e isolamento precisam valer sob concorrência real no banco. */
final class PortfolioLedgerConcurrencyTest extends TestCase
{
    public function testConcurrentSameExternalIdAppliesOnce(): void
    {
        $direct = $this->directConnection();
        $adminId = (string) Str::uuid();
        $investorId = (string) Str::uuid();
        $portfolioId = (string) Str::uuid();
        $instrumentId = (string) Str::uuid();
        $externalId = 'race-'.Str::lower(Str::random(10));
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $direct->table('admin_users')->insert([
                'id' => $adminId,
                'email' => 'ledger-owner-'.Str::lower(Str::random(10)).'@example.test',
                'password_hash' => 'x',
                'mfa_secret_encrypted' => 'x',
                'mfa_enabled' => false,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $direct->table('investors')->insert([
                'id' => $investorId,
                'created_by_admin_id' => $adminId,
                'full_name' => 'Ledger Race',
                'phone_e164' => '+55759999'.Str::random(4),
                'email' => 'ledger-investor-'.Str::lower(Str::random(10)).'@example.test',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $direct->table('portfolios')->insert([
                'id' => $portfolioId,
                'investor_id' => $investorId,
                'name' => 'Carteira principal',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $direct->table('instruments')->insert([
                'id' => $instrumentId,
                'symbol' => 'LEDGER-RACE-'.Str::upper(Str::random(6)),
                'instrument_type' => 'renda_fixa',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $command = sprintf(
                '%s %s portfolio:apply-event %s %s ORDER_CONFIRMED 10000 --instrument=%s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('artisan')),
                escapeshellarg($portfolioId),
                escapeshellarg($externalId),
                escapeshellarg($instrumentId),
            );
            $results = $this->runConcurrently([$command, $command, $command, $command]);

            $applied = 0;
            foreach ($results as $result) {
                self::assertSame(0, $result['code'], $result['error']);
                $applied += substr_count($result['output'], 'applied=true');
            }
            self::assertSame(1, $applied, 'Apenas uma das requisições concorrentes pode aplicar o evento.');
            self::assertSame(1, $direct->table('portfolio_events')
                ->where('portfolio_id', $portfolioId)->where('external_id', $externalId)->count());
            self::assertSame(1, $direct->table('audit_events')
                ->where('subject_type', 'portfolio')->where('subject_id', $portfolioId)->count());
        } finally {
            $direct->table('portfolio_events')->where('portfolio_id', $portfolioId)->delete();
            $direct->table('audit_events')->where('subject_id', $portfolioId)->delete();
            $direct->table('portfolios')->where('id', $portfolioId)->delete();
            $direct->table('instruments')->where('id', $instrumentId)->delete();
            $direct->table('investors')->where('id', $investorId)->delete();
            $direct->table('admin_users')->where('id', $adminId)->delete();
        }
    }

    private function directConnection(): Connection
    {
        $name = 'ledger_direct_'.Str::lower(Str::random(8));
        config()->set('database.connections.'.$name, array_merge(
            config('database.connections.'.config('database.default')),
            ['name' => $name],
        ));

        return DB::connection($name);
    }

    /**
     * @param list<string> $commands
     * @return list<array{output: string, error: string, code: int}>
     */
    private function runConcurrently(array $commands): array
    {
        $running = [];
        foreach ($commands as $command) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            self::assertIsResource($process);
            $running[] = ['process' => $process, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($running as $item) {
            $output = stream_get_contents($item['pipes'][1]);
            $error = stream_get_contents($item['pipes'][2]);
            $code = proc_close($item['process']);
            $results[] = ['output' => (string) $output, 'error' => (string) $error, 'code' => $code];
        }

        return $results;
    }
}
