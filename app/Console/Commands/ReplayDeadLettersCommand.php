<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppOutbox;
use App\Models\WhatsAppDeadLetter;
use App\Models\WhatsAppOutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** OPS: reprocessa DLQs uma única vez, exigindo ausência de provider_message_id. */
final class ReplayDeadLettersCommand extends Command
{
    protected $signature = 'ops:replay-dead-letters {--limit=50} {--dry-run}';

    protected $description = 'Reprocessa dead letters da outbox sem duplicar entregas já confirmadas.';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $deadLetters = WhatsAppDeadLetter::query()
            ->whereNull('replayed_at')
            ->orderBy('failed_at')
            ->limit($limit)
            ->get();

        $replayed = 0;
        foreach ($deadLetters as $deadLetter) {
            $outbox = WhatsAppOutboxMessage::query()->find($deadLetter->reference_id);
            if ($outbox === null || $outbox->status !== 'dead' || $outbox->provider_message_id !== null) {
                $deadLetter->forceFill([
                    'replayed_at' => now(),
                    'replay_count' => (int) $deadLetter->replay_count + 1,
                ])->save();
                $this->warn('DLQ ignorada: '.$deadLetter->dedupe_key.' (sem outbox morta ou já entregue).');

                continue;
            }

            if ($dryRun) {
                $this->line('DRY RUN: '.$deadLetter->dedupe_key);

                continue;
            }

            DB::transaction(static function () use ($deadLetter, $outbox): void {
                $outbox->forceFill([
                    'status' => 'queued',
                    'attempts' => 0,
                    'available_at' => now(),
                    'last_error' => null,
                ])->save();
                $deadLetter->forceFill([
                    'replayed_at' => now(),
                    'replay_count' => (int) $deadLetter->replay_count + 1,
                ])->save();
                SendWhatsAppOutbox::dispatch($outbox->id)->onQueue((string) config('whatsapp.queue'));
            });
            $replayed++;
        }

        $this->info($dryRun ? 'DRY RUN concluído.' : "DLQs reprocessadas: {$replayed}.");

        return self::SUCCESS;
    }
}
